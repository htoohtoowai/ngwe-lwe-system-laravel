<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountFeatureAssignment;
use App\Models\CashDenominationLog;
use App\Models\Company;
use App\Models\ProviderFeeTier;
use App\Models\User;
use App\Models\VaultTransaction;
use App\Repositories\CashDenominationRepository;
use App\Repositories\CashFloatRepository;
use App\Services\CashFloatService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VaultTransactionAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('vault transaction audit tests');
        parent::setUp();
    }

    public function test_float_issue_creates_denomination_level_audit_entries(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $teller = User::factory()->create(['role' => 'teller']);
        app(CashDenominationRepository::class)->recordBulk('vault_in', [10000 => 10, 5000 => 10], $cashier->id);

        $float = app(CashFloatService::class)->issue($cashier, $teller->id, [10000 => 2, 5000 => 1]);

        $this->assertSame(2, VaultTransaction::query()
            ->where('float_id', $float->id)
            ->where('txn_type', 'float_issue')
            ->count());

        $batchId = VaultTransaction::query()
            ->where('float_id', $float->id)
            ->where('txn_type', 'float_issue')
            ->value('batch_id');

        $this->assertNotNull($batchId);
        $this->assertSame(2, CashDenominationLog::query()->where('batch_id', $batchId)->count());
        $this->assertDatabaseHas('cash_denomination_logs', [
            'batch_id' => $batchId,
            'movement_type' => 'cashier_to_teller',
            'source_type' => 'cashier_vault',
            'destination_type' => 'teller_float',
            'affects_main_vault' => true,
        ]);
    }

    public function test_teller_to_customer_cash_out_is_mirrored_without_changing_main_vault(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier', 'pin_hash' => Hash::make('2222')]);
        $teller = User::factory()->create(['role' => 'teller', 'pin_hash' => Hash::make('3333')]);
        $company = Company::query()->create([
            'name' => 'Cash Out Provider',
            'category' => 'Pay',
            'is_active' => true,
        ]);
        $account = Account::query()->create([
            'company_id' => $company->id,
            'account_name' => 'Cash Out Main',
            'account_type' => 'PAY',
            'account_identifier' => 'cash-out-main',
            'balance' => 0,
            'is_active' => true,
            'is_fee_account' => false,
            'is_agent' => false,
        ]);
        AccountFeatureAssignment::query()->create([
            'account_id' => $account->id,
            'feature' => 'cash_out',
        ]);

        $vault = app(CashDenominationRepository::class);
        $vault->recordBulk('vault_in', [10000 => 10], $cashier->id);

        $floatService = app(CashFloatService::class);
        $float = $floatService->issue($cashier, $teller->id, [10000 => 2]);
        $floatService->activate($teller, $float->fresh(), '3333');
        $this->assertSame(8, $vault->getVaultBalance()[10000]);

        $transaction = app(TransactionService::class)->createCashOut([
            'account_id' => $account->id,
            'amount' => 10000,
            'customer_name' => 'Client One',
            'customer_phone' => '0911111111',
            'denominations' => [10000 => 1],
        ], $teller);

        $audit = VaultTransaction::query()
            ->where('transaction_id', $transaction->id)
            ->where('txn_type', 'cash_out')
            ->firstOrFail();

        $this->assertDatabaseHas('cash_denomination_logs', [
            'batch_id' => $audit->batch_id,
            'movement_type' => 'teller_to_customer',
            'source_type' => 'teller_float',
            'source_id' => $float->id,
            'destination_type' => 'customer',
            'denomination' => 10000,
            'quantity' => 1,
            'transaction_id' => $transaction->id,
            'affects_main_vault' => false,
        ]);
        $this->assertSame(8, $vault->getVaultBalance()[10000]);
    }

    public function test_cash_out_cash_fee_can_receive_larger_note_and_return_change_with_mirrored_ledgers(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier', 'pin_hash' => Hash::make('2222')]);
        $teller = User::factory()->create(['role' => 'teller', 'pin_hash' => Hash::make('3333')]);
        $company = Company::query()->create([
            'name' => 'Cash Fee Change Provider',
            'category' => 'Pay',
            'is_active' => true,
        ]);
        $account = Account::query()->create([
            'company_id' => $company->id,
            'account_name' => 'Cash Fee Change Main',
            'account_type' => 'PAY',
            'account_identifier' => 'cash-fee-change-main',
            'balance' => 0,
            'is_active' => true,
            'is_fee_account' => false,
            'is_agent' => false,
        ]);
        AccountFeatureAssignment::query()->create([
            'account_id' => $account->id,
            'feature' => 'cash_out',
        ]);
        ProviderFeeTier::query()->create([
            'company_id' => $company->id,
            'feature' => 'cash_out',
            'amount_from' => 0,
            'amount_to' => 1000000,
            'fee_type' => 'FIXED',
            'fee_value' => 200,
            'additional_fee_type' => 'FIXED',
            'additional_fee_value' => 0,
            'is_active' => true,
        ]);

        $vault = app(CashDenominationRepository::class);
        $vault->recordBulk('vault_in', [20000 => 10, 500 => 5, 200 => 5, 100 => 5], $cashier->id);

        $floatService = app(CashFloatService::class);
        $float = $floatService->issue($cashier, $teller->id, [20000 => 5, 200 => 1, 100 => 1]);
        $floatService->activate($teller, $float->fresh(), '3333');

        $transaction = app(TransactionService::class)->createCashOut([
            'account_id' => $account->id,
            'amount' => 100000,
            'customer_name' => 'Client Change',
            'customer_phone' => '0911111111',
            'fee_payment_method' => 'cash',
            'denominations' => [20000 => 5],
            'fee_denominations' => [500 => 1],
            'change_denominations' => [200 => 1, 100 => 1],
        ], $teller);

        $this->assertSame('200.00', $transaction->customer_fee);
        $this->assertSame('300.00', $transaction->change_given);
        $this->assertSame([500 => 1], $transaction->received_denominations);
        $this->assertSame([200 => 1, 100 => 1], $transaction->change_denominations);

        $float = $float->fresh();
        $this->assertSame('500.00', $float->current_balance);
        $stock = app(CashFloatRepository::class)->getDenominationBalance($float->id);
        $this->assertSame(0, $stock[20000] ?? 0);
        $this->assertSame(1, $stock[500] ?? 0);
        $this->assertSame(0, $stock[200] ?? 0);
        $this->assertSame(0, $stock[100] ?? 0);

        foreach (['cash_out', 'cash_out_fee_received', 'cash_out_change'] as $type) {
            $batchId = VaultTransaction::query()
                ->where('transaction_id', $transaction->id)
                ->where('txn_type', $type)
                ->value('batch_id');

            $this->assertNotNull($batchId, "Missing {$type} vault transaction batch.");
            $this->assertTrue(
                CashDenominationLog::query()->where('batch_id', $batchId)->exists(),
                "Missing {$type} cash denomination mirror.",
            );
        }
    }
}
