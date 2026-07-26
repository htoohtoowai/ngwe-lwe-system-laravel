<?php

namespace Tests\Feature;

use App\Events\BalanceUpdated;
use App\Events\BroadcastPing;
use App\Events\CashInPending;
use App\Events\FloatStatusChanged;
use App\Events\NewTransaction;
use App\Models\Account;
use App\Models\CashFloatAssignment;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\ServiceType;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReverbBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('broadcast tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('i', 32));
    }

    public function test_pending_cash_in_broadcasts_transaction_pending_and_balance_events(): void
    {
        [, $tellerToken] = $this->activeTellerWithEmptyFloat();
        [$account, $serviceType] = $this->accountWithBalance(50_000);
        $this->fixedTier($serviceType->id, feeDeposit: 500);

        Event::fake([BalanceUpdated::class, CashInPending::class, NewTransaction::class]);

        $txnId = $this->withHeader('Authorization', 'Bearer '.$tellerToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 10_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
                'received_denominations' => [10_000 => 1],
                'handoff_denominations' => [10_000 => 1],
            ])
            ->assertCreated()
            ->json('data.id');

        Event::assertDispatched(NewTransaction::class, fn (NewTransaction $event): bool => $event->transaction['id'] === $txnId);
        Event::assertDispatched(CashInPending::class, fn (CashInPending $event): bool => $event->transaction['status'] === 'PENDING_CASHIER_CONFIRM');
        Event::assertDispatched(BalanceUpdated::class, fn (BalanceUpdated $event): bool => collect($event->accounts)->contains(
            fn (array $accountPayload): bool => $accountPayload['id'] === $account->id
                && $accountPayload['balance'] === '40000.00'
        ));
    }

    public function test_completed_transaction_creates_broadcast_new_transaction_and_balance_events(): void
    {
        [$owner, $ownerToken] = $this->userWithToken('admin');
        [$source, $serviceType] = $this->accountWithBalance(100_000, 'Source');
        $target = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'Target',
            'phone_number' => '0900000001',
            'balance' => 5_000,
        ]);
        $this->fixedTier($serviceType->id, feeDeposit: 300, feeWithdraw: 700);
        $this->seedVaultBalance([10_000 => 1], $owner);
        $this->exchangeRate();

        Event::fake([BalanceUpdated::class, CashInPending::class, NewTransaction::class]);

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/transactions/cash-out', [
                'account_id' => $source->id,
                'amount' => 10_000,
                'customer_name' => 'Cash Out',
                'customer_phone' => '09',
                'denominations' => [10_000 => 1],
            ])
            ->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/transactions/transfer', [
                'from_account_id' => $source->id,
                'to_account_id' => $target->id,
                'amount' => 5_000,
            ])
            ->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $source->id,
                'amount' => 2_000,
                'currency' => 'MMK',
            ])
            ->assertCreated();

        Event::assertDispatchedTimes(NewTransaction::class, 3);
        Event::assertDispatchedTimes(BalanceUpdated::class, 3);
        Event::assertNotDispatched(CashInPending::class);
    }

    public function test_cash_in_confirm_cancel_and_balance_adjust_broadcast_balance_updates(): void
    {
        [, $ownerToken] = $this->userWithToken('admin');
        [, $tellerToken] = $this->activeTellerWithEmptyFloat();
        [, $cashierToken] = $this->userWithToken('cashier');
        [$account, $serviceType] = $this->accountWithBalance(100_000);
        $this->fixedTier($serviceType->id);

        $confirmTxnId = $this->createPendingCashIn($tellerToken, $account->id, 10_000);
        $cancelTxnId = $this->createPendingCashIn($tellerToken, $account->id, 5_000);

        Event::fake([BalanceUpdated::class, NewTransaction::class]);

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/transactions/'.$confirmTxnId.'/confirm-cash-in')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/transactions/'.$cancelTxnId.'/cancel-cash-in')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/accounts/'.$account->id.'/balance-adjust', [
                'amount' => 1_000,
                'remark' => 'Broadcast test top-up',
            ])
            ->assertOk();

        Event::assertDispatchedTimes(BalanceUpdated::class, 3);
        Event::assertNotDispatched(NewTransaction::class);
    }

    public function test_float_lifecycle_broadcasts_status_changes_to_affected_employee(): void
    {
        [$cashier, $cashierToken] = $this->userWithToken('cashier');
        $this->setPin($cashier, '9999');
        $employee = $this->activeEmployee();
        $this->setPin($employee, '1234');
        $employeeToken = app(NgweLweTokenService::class)->create($employee);
        $this->seedVaultBalance([10_000 => 5], $cashier);

        Event::fake([FloatStatusChanged::class]);

        $floatId = $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/cash-floats', [
                'employee_id' => $employee->id,
                'denominations' => [10_000 => 2],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/cash-floats/'.$floatId.'/activate', [
                'pin' => '1234',
                'verified_denominations' => [10_000 => 2],
            ])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/cash-floats/'.$floatId.'/initiate-return', [
                'return_denominations' => [10_000 => 2],
            ])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/cash-floats/'.$floatId.'/confirm-return', [
                'closing_total' => 20_000,
                'pin' => '9999',
            ])
            ->assertOk();

        Event::assertDispatchedTimes(FloatStatusChanged::class, 4);

        foreach (['PENDING_RECEIPT', 'ACTIVE', 'PENDING_RECONCILIATION', 'CLOSED'] as $status) {
            Event::assertDispatched(
                FloatStatusChanged::class,
                fn (FloatStatusChanged $event): bool => $event->employeeId === $employee->id
                    && $event->cashFloat['status'] === $status
            );
        }
    }

    public function test_owner_broadcast_test_endpoint_dispatches_ping(): void
    {
        [, $ownerToken] = $this->userWithToken('admin');
        [, $employeeToken] = $this->userWithToken('teller');

        Event::fake([BroadcastPing::class]);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/broadcast/test')
            ->assertForbidden();

        Event::assertNotDispatched(BroadcastPing::class);

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/broadcast/test')
            ->assertOk()
            ->assertJsonPath('message', 'Broadcast ping dispatched');

        Event::assertDispatched(BroadcastPing::class);
    }

    private function createPendingCashIn(string $tellerToken, int $accountId, int $amount): int
    {
        return (int) $this->withHeader('Authorization', 'Bearer '.$tellerToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $accountId,
                'amount' => $amount,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
                'received_denominations' => [$amount => 1],
                'handoff_denominations' => [$amount => 1],
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function userWithToken(string $role): array
    {
        $user = User::factory()->create([
            'username' => $role.'_'.uniqid('', true),
            'role' => $role,
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);

        return [$user, app(NgweLweTokenService::class)->create($user)];
    }

    private function activeEmployee(string $prefix = 'emp'): User
    {
        return User::factory()->create([
            'username' => $prefix.'_'.uniqid('', true),
            'role' => 'teller',
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function activeTellerWithEmptyFloat(): array
    {
        [$cashier] = $this->userWithToken('cashier');
        [$teller, $token] = $this->userWithToken('teller');

        CashFloatAssignment::query()->create([
            'employee_id' => $teller->id,
            'issued_by' => $cashier->id,
            'status' => 'ACTIVE',
            'total_amount' => 0,
            'current_balance' => 0,
            'received_at' => now(),
        ]);

        return [$teller, $token];
    }

    /**
     * @return array{0: Account, 1: ServiceType}
     */
    private function accountWithBalance(int $balance, string $name = 'Wave Main'): array
    {
        $company = Company::query()->create([
            'name' => 'Wave-'.uniqid('', true),
            'category' => 'Pay',
        ]);
        $serviceType = ServiceType::query()->create([
            'company_id' => $company->id,
            'name' => 'Cash In',
            'operation' => 'CashIn',
        ]);
        $account = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => $name,
            'phone_number' => '0900000000',
            'balance' => $balance,
        ]);

        return [$account, $serviceType];
    }

    private function fixedTier(int $serviceTypeId, int $feeDeposit = 0, int $feeWithdraw = 0): CommissionTier
    {
        return CommissionTier::query()->create([
            'service_type_id' => $serviceTypeId,
            'amount_from' => 1,
            'amount_to' => 999_999_999_999,
            'fee_amount_type' => 'FIXED',
            'fee_amount_deposit' => $feeDeposit,
            'fee_amount_withdraw' => $feeWithdraw,
            'comm_type' => 'FIXED',
            'additional_fee_type' => 'FIXED',
            'is_active' => true,
        ]);
    }

    private function exchangeRate(): ExchangeRate
    {
        return ExchangeRate::query()->create([
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'base_amount' => 1,
            'buy_rate' => 100,
            'sell_rate' => 120,
        ]);
    }

    /**
     * @param  array<int, int>  $denominations
     */
    private function seedVaultBalance(array $denominations, User $creator): void
    {
        app(CashDenominationRepository::class)->recordBulk(
            entryType: 'vault_in',
            denominations: $denominations,
            createdBy: $creator->id,
            note: 'Broadcast test seed',
        );
    }

    private function setPin(User $user, string $pin): void
    {
        $user->pin_hash = Hash::make($pin);
        $user->save();
    }
}
