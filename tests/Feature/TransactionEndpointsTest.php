<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ServiceType;
use App\Models\Transaction;
use App\Models\User;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TransactionEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is not enabled for in-memory transaction endpoint tests.');
        }

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('e', 32));
    }

    public function test_cash_in_debits_account_and_creates_pending_transaction(): void
    {
        [$owner, $token] = $this->owner();
        [$account, $serviceType] = $this->accountWithBalance(50_000);
        $this->fixedTier($serviceType->id, feeDeposit: 500);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 10_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
            ])
            ->assertCreated()
            ->assertJsonPath('data.transaction_type', 'cash_in')
            ->assertJsonPath('data.status', 'PENDING_CASHIER_CONFIRM')
            ->assertJsonPath('data.vault_impact', 'none')
            ->assertJsonPath('data.customer_fee', '500.00')
            ->assertJsonPath('data.amount', '10000.00')
            ->assertJsonPath('data.balance_change', '-10000.00');

        $this->assertSame('40000.00', $account->fresh()->balance);
        $this->assertSame(1, ActivityLog::query()->where('action', 'transaction_created')->count());
        $this->assertSame($owner->id, ActivityLog::query()->first()->user_id);

        $txnId = $response->json('data.id');
        $this->assertNotNull(Transaction::query()->find($txnId));
    }

    public function test_cash_in_rejects_overdraw(): void
    {
        [, $token] = $this->owner();
        [$account, $serviceType] = $this->accountWithBalance(1_000);
        $this->fixedTier($serviceType->id);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 5_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
            ])
            ->assertStatus(409);

        $this->assertSame('1000.00', $account->fresh()->balance);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_cash_out_credits_account_and_marks_completed(): void
    {
        [, $token] = $this->owner();
        [$account, $serviceType] = $this->accountWithBalance(20_000);
        $this->fixedTier($serviceType->id, feeWithdraw: 700);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/transactions/cash-out', [
                'account_id' => $account->id,
                'amount' => 15_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
            ])
            ->assertCreated()
            ->assertJsonPath('data.transaction_type', 'cash_out')
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.customer_fee', '700.00')
            ->assertJsonPath('data.balance_change', '15000.00');

        $this->assertSame('35000.00', $account->fresh()->balance);
    }

    public function test_transfer_moves_balance_between_accounts_and_rejects_overdraw(): void
    {
        [, $token] = $this->owner();
        [$from, $serviceType] = $this->accountWithBalance(30_000, 'From');
        $to = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'To',
            'phone_number' => '0900000000',
            'balance' => 0,
        ]);
        $this->fixedTier($serviceType->id, feeDeposit: 300);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/transactions/transfer', [
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => 10_000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.transaction_type', 'transfer')
            ->assertJsonPath('data.customer_fee', '300.00');

        $this->assertSame('20000.00', $from->fresh()->balance);
        $this->assertSame('10000.00', $to->fresh()->balance);

        // Overdraw attempt
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/transactions/transfer', [
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => 100_000,
            ])
            ->assertStatus(409);

        $this->assertSame('20000.00', $from->fresh()->balance);
        $this->assertSame('10000.00', $to->fresh()->balance);
    }

    public function test_transfer_rejects_same_account(): void
    {
        [, $token] = $this->owner();
        [$account, $serviceType] = $this->accountWithBalance(50_000);
        $this->fixedTier($serviceType->id);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/transactions/transfer', [
                'from_account_id' => $account->id,
                'to_account_id' => $account->id,
                'amount' => 1_000,
            ])
            ->assertStatus(422);
    }

    public function test_cashier_cannot_create_transactions(): void
    {
        [$cashier, $cashierToken] = $this->userWithToken('cashier');
        [$account, $serviceType] = $this->accountWithBalance(50_000);
        $this->fixedTier($serviceType->id);

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 1_000,
                'customer_name' => 'X',
                'customer_phone' => '09',
            ])
            ->assertForbidden();
    }

    public function test_cashier_can_confirm_pending_cash_in(): void
    {
        [$owner, $ownerToken] = $this->owner();
        [$cashier, $cashierToken] = $this->userWithToken('cashier');
        [$account, $serviceType] = $this->accountWithBalance(50_000);
        $this->fixedTier($serviceType->id, feeDeposit: 500);

        $txnId = $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 5_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
            ])
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/transactions/'.$txnId.'/confirm-cash-in')
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.vault_impact', 'main_vault_increase')
            ->assertJsonPath('data.confirmed_by', $cashier->id)
            ->assertJsonPath('data.cash_approved_by', $cashier->id);

        // Balance stays debited (already applied at creation).
        $this->assertSame('45000.00', $account->fresh()->balance);
    }

    public function test_cashier_can_cancel_pending_cash_in_and_balance_is_reversed(): void
    {
        [, $ownerToken] = $this->owner();
        [, $cashierToken] = $this->userWithToken('cashier');
        [$account, $serviceType] = $this->accountWithBalance(50_000);
        $this->fixedTier($serviceType->id);

        $txnId = $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 5_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
            ])
            ->json('data.id');

        $this->assertSame('45000.00', $account->fresh()->balance);

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/transactions/'.$txnId.'/cancel-cash-in', ['note' => 'customer left'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.vault_impact', 'none')
            ->assertJsonPath('data.note', 'customer left');

        $this->assertSame('50000.00', $account->fresh()->balance);
    }

    public function test_cash_in_confirm_is_idempotent_safe(): void
    {
        [, $ownerToken] = $this->owner();
        [, $cashierToken] = $this->userWithToken('cashier');
        [$account, $serviceType] = $this->accountWithBalance(50_000);
        $this->fixedTier($serviceType->id);

        $txnId = $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 5_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
            ])
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/transactions/'.$txnId.'/confirm-cash-in')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/transactions/'.$txnId.'/confirm-cash-in')
            ->assertStatus(409);
    }

    public function test_owner_transactions_list_supports_filters(): void
    {
        [, $ownerToken] = $this->owner();
        [$account, $serviceType] = $this->accountWithBalance(50_000);
        $this->fixedTier($serviceType->id);

        for ($i = 0; $i < 3; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$ownerToken)
                ->postJson('/api/transactions/cash-out', [
                    'account_id' => $account->id,
                    'amount' => 1_000,
                    'customer_name' => 'X'.$i,
                    'customer_phone' => '09',
                ])
                ->assertCreated();
        }

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->getJson('/api/transactions?type=cash_out&limit=10')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_hard_delete_is_disabled(): void
    {
        [, $ownerToken] = $this->owner();
        [$account, $serviceType] = $this->accountWithBalance(50_000);
        $this->fixedTier($serviceType->id);

        $txnId = $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/transactions/cash-out', [
                'account_id' => $account->id,
                'amount' => 1_000,
                'customer_name' => 'X',
                'customer_phone' => '09',
            ])
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->deleteJson('/api/transactions/'.$txnId)
            ->assertStatus(409);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function owner(): array
    {
        return $this->userWithToken('owner');
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
}
