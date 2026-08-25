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
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashInCashierSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('Cash In Cashier settlement tests');
        parent::setUp();
    }

    public function test_teller_creates_entry_only_and_cashier_counts_received_cash_and_change(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $teller = User::factory()->create(['role' => 'teller']);

        $company = Company::query()->create([
            'name' => 'Cash In Provider',
            'category' => 'Pay',
            'is_active' => true,
        ]);

        $account = Account::query()->create([
            'company_id' => $company->id,
            'account_name' => 'Cash In Main',
            'account_type' => 'PAY',
            'account_identifier' => 'cash-in-main',
            'balance' => 100000,
            'is_active' => true,
            'is_fee_account' => false,
            'is_agent' => false,
        ]);

        AccountFeatureAssignment::query()->create([
            'account_id' => $account->id,
            'feature' => 'cash_in',
        ]);

        ProviderFeeTier::query()->create([
            'company_id' => $company->id,
            'feature' => 'cash_in',
            'amount_from' => 0,
            'amount_to' => 1000000,
            'fee_type' => 'FIXED',
            'fee_value' => 100,
            'additional_fee_type' => 'FIXED',
            'additional_fee_value' => 0,
            'is_active' => true,
        ]);

        $vault = app(CashDenominationRepository::class);
        $vault->recordBulk('vault_in', [200 => 2], $cashier->id, note: 'Opening change stock');

        $service = app(TransactionService::class);
        $transaction = $service->createCashIn([
            'account_id' => $account->id,
            'amount' => 20000,
            'customer_name' => 'Client Cash In',
            'customer_phone' => '0911111111',
            'fee_payment_method' => 'cash',
        ], $teller);

        $this->assertSame('PENDING_CASHIER_CONFIRM', $transaction->status);
        $this->assertNull($transaction->received_denominations);
        $this->assertNull($transaction->handoff_denominations);
        $this->assertNull($transaction->change_denominations);
        $this->assertSame('100.00', $transaction->customer_fee);

        // Customer pays 20,500 for a 20,100 total due; Cashier returns 400.
        $confirmed = $service->confirmPendingCashIn(
            $transaction->fresh(),
            $cashier,
            [20000 => 1, 500 => 1],
            [200 => 2],
        );

        $this->assertSame('COMPLETED', $confirmed->status);
        $this->assertSame([20000 => 1, 500 => 1], $confirmed->received_denominations);
        $this->assertSame([200 => 2], $confirmed->change_denominations);
        $this->assertSame('400.00', $confirmed->change_given);
        $this->assertSame('main_vault_increase', $confirmed->vault_impact);

        $stock = $vault->getVaultBalance();
        $this->assertSame(1, $stock[20000] ?? 0);
        $this->assertSame(1, $stock[500] ?? 0);
        $this->assertSame(0, $stock[200] ?? 0);

        $receivedBatch = VaultTransaction::query()
            ->where('transaction_id', $confirmed->id)
            ->where('txn_type', 'cash_in_received')
            ->value('batch_id');
        $changeBatch = VaultTransaction::query()
            ->where('transaction_id', $confirmed->id)
            ->where('txn_type', 'cash_in_change')
            ->value('batch_id');

        $this->assertNotNull($receivedBatch);
        $this->assertNotNull($changeBatch);
        $this->assertDatabaseHas('cash_denomination_logs', [
            'batch_id' => $receivedBatch,
            'movement_type' => 'customer_to_cashier',
            'source_type' => 'customer',
            'destination_type' => 'cashier_vault',
            'affects_main_vault' => true,
        ]);
        $this->assertDatabaseHas('cash_denomination_logs', [
            'batch_id' => $changeBatch,
            'movement_type' => 'cashier_to_customer_change',
            'source_type' => 'cashier_vault',
            'destination_type' => 'customer',
            'affects_main_vault' => true,
        ]);

        $this->assertSame(3, CashDenominationLog::query()
            ->where('transaction_id', $confirmed->id)
            ->count());
    }

    public function test_cashier_confirmation_rejects_change_that_does_not_match_amount_due(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $teller = User::factory()->create(['role' => 'teller']);

        $company = Company::query()->create([
            'name' => 'Cash In Validation Provider',
            'category' => 'Pay',
            'is_active' => true,
        ]);
        $account = Account::query()->create([
            'company_id' => $company->id,
            'account_name' => 'Cash In Validation',
            'account_type' => 'PAY',
            'account_identifier' => 'cash-in-validation',
            'balance' => 100000,
            'is_active' => true,
            'is_fee_account' => false,
            'is_agent' => false,
        ]);
        AccountFeatureAssignment::query()->create([
            'account_id' => $account->id,
            'feature' => 'cash_in',
        ]);

        $transaction = app(TransactionService::class)->createCashIn([
            'account_id' => $account->id,
            'amount' => 20000,
            'customer_name' => 'Validation Client',
            'customer_phone' => '0922222222',
            'fee_payment_method' => 'cash',
        ], $teller);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match change due');

        app(TransactionService::class)->confirmPendingCashIn(
            $transaction->fresh(),
            $cashier,
            [20000 => 1, 500 => 1],
            [100 => 1],
        );
    }
}
