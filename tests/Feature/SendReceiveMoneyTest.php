<?php

namespace Tests\Feature;

use App\Enums\AccountFeature;
use App\Models\User;
use App\Models\VaultTransaction;
use App\Repositories\CashDenominationRepository;
use App\Services\CashFloatService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

class SendReceiveMoneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('Send / Receive Money tests');
        parent::setUp();
    }

    public function test_send_money_automatically_adds_provider_fee_and_waits_for_cashier_cash_confirmation(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier', 'pin_hash' => Hash::make('2222')]);
        $teller = User::factory()->create(['role' => 'teller']);
        [$account, $company] = $this->createCompanyAccountFixture(
            500000,
            'Wave Agent',
            true,
            [AccountFeature::SendMoney, AccountFeature::ReceiveMoney],
        );
        $this->createCompanyTierFixtures(
            $company->id,
            outCommission: 196,
            inCommission: 392,
            sendMoneyFee: 1500,
            receiveMoneyFee: 0,
        );

        $transaction = app(TransactionService::class)->createSendMoney([
            'account_id' => $account->id,
            'amount' => 100000,
            'customer_name' => 'Sender One',
            'customer_phone' => '0911111111',
            'destination_customer_name' => 'Receiver One',
            'destination_account_number' => '0999999999',
        ], $teller);

        $this->assertSame('send_money', $transaction->transaction_type);
        $this->assertSame('100000.00', $transaction->amount);
        $this->assertSame('1500.00', $transaction->customer_fee);
        $this->assertSame('101500.00', $transaction->customer_total);
        $this->assertNull($transaction->fee_mode);
        $this->assertSame('PENDING_CASHIER_CONFIRM', $transaction->status);
        $this->assertSame('398696.00', $account->fresh()->balance);

        $confirmed = app(TransactionService::class)->confirmPendingSendMoney(
            $transaction->fresh(),
            $cashier,
            [20000 => 5, 1000 => 1, 500 => 1],
        );

        $this->assertSame('COMPLETED', $confirmed->status);
        $this->assertSame('main_vault_increase', $confirmed->vault_impact);
        $this->assertSame('0.00', $confirmed->change_given);
        $this->assertSame(5, app(CashDenominationRepository::class)->getVaultBalance()[20000] ?? 0);

        $physicalCashTotal = VaultTransaction::query()
            ->where('transaction_id', $transaction->id)
            ->get()
            ->sum(fn (VaultTransaction $entry): int => $entry->denomination * $entry->quantity);
        $this->assertSame(101500, $physicalCashTotal);
        $this->assertDatabaseMissing('vault_transactions', [
            'transaction_id' => $transaction->id,
            'txn_type' => 'agent_commission',
        ]);
    }

    public function test_receive_money_pays_exact_amount_from_teller_float_and_credits_agent_with_in_commission(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier', 'pin_hash' => Hash::make('2222')]);
        $teller = User::factory()->create(['role' => 'teller', 'pin_hash' => Hash::make('3333')]);
        [$account, $company] = $this->createCompanyAccountFixture(
            500000,
            'Wave Receive Agent',
            true,
            [AccountFeature::ReceiveMoney],
        );
        $this->createCompanyTierFixtures(
            $company->id,
            outCommission: 196,
            inCommission: 392,
            sendMoneyFee: 1500,
            receiveMoneyFee: 1500,
        );

        $vault = app(CashDenominationRepository::class);
        $vault->recordBulk('vault_in', [20000 => 10], $cashier->id);
        $floatService = app(CashFloatService::class);
        $float = $floatService->issue($cashier, $teller->id, [20000 => 5]);
        $floatService->activate($teller, $float->fresh(), '3333');

        $transaction = app(TransactionService::class)->createReceiveMoney([
            'account_id' => $account->id,
            'amount' => 100000,
            'customer_name' => 'Receive Customer',
            'customer_phone' => '0933333333',
            'source_account_number' => 'WAVE-REF-001',
            'denominations' => [20000 => 5],
        ], $teller);

        $this->assertSame('receive_money', $transaction->transaction_type);
        $this->assertSame('COMPLETED', $transaction->status);
        $this->assertSame('0.00', $transaction->customer_fee);
        $this->assertSame('100000.00', $transaction->customer_total);
        $this->assertNull($transaction->fee_mode);
        $this->assertSame('600392.00', $account->fresh()->balance);
        $this->assertSame('0.00', $float->fresh()->current_balance);

        $physicalCashTotal = VaultTransaction::query()
            ->where('transaction_id', $transaction->id)
            ->where('txn_type', 'receive_money')
            ->get()
            ->sum(fn (VaultTransaction $entry): int => $entry->denomination * $entry->quantity);
        $this->assertSame(100000, $physicalCashTotal);
        $this->assertDatabaseMissing('vault_transactions', [
            'transaction_id' => $transaction->id,
            'txn_type' => 'agent_commission',
        ]);
    }

    public function test_cancel_pending_send_money_restores_original_agent_digital_balance(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier', 'pin_hash' => Hash::make('2222')]);
        $teller = User::factory()->create(['role' => 'teller']);
        [$account, $company] = $this->createCompanyAccountFixture(
            500000,
            'Wave Cancel Agent',
            true,
            [AccountFeature::SendMoney],
        );
        $this->createCompanyTierFixtures(
            $company->id,
            outCommission: 196,
            sendMoneyFee: 1500,
        );

        $transaction = app(TransactionService::class)->createSendMoney([
            'account_id' => $account->id,
            'amount' => 100000,
            'customer_name' => 'Cancel Sender',
            'customer_phone' => '0912345678',
            'destination_customer_name' => 'Cancel Receiver',
            'destination_account_number' => '0998765432',
        ], $teller);

        $this->assertSame('398696.00', $account->fresh()->balance);

        $cancelled = app(TransactionService::class)->cancelPendingSendMoney(
            $transaction->fresh(),
            $cashier,
            'Customer cancelled before cash confirmation.',
        );

        $this->assertSame('CANCELLED', $cancelled->status);
        $this->assertSame('500000.00', $account->fresh()->balance);
        $this->assertDatabaseMissing('vault_transactions', [
            'transaction_id' => $transaction->id,
        ]);
    }

    public function test_send_and_receive_reject_non_agent_pay_accounts(): void
    {
        $teller = User::factory()->create(['role' => 'teller']);
        [$account] = $this->createCompanyAccountFixture(
            500000,
            'Non Agent PAY',
            false,
            [AccountFeature::SendMoney, AccountFeature::ReceiveMoney],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PAY Agent');

        app(TransactionService::class)->createSendMoney([
            'account_id' => $account->id,
            'amount' => 10000,
            'customer_name' => 'Blocked Sender',
            'customer_phone' => '0944444444',
            'destination_customer_name' => 'Blocked Receiver',
            'destination_account_number' => '0955555555',
        ], $teller);
    }
}
