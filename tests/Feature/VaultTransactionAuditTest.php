<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountFeatureAssignment;
use App\Models\CashDenominationLog;
use App\Models\Company;
use App\Models\User;
use App\Models\VaultTransaction;
use App\Repositories\CashDenominationRepository;
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
}
