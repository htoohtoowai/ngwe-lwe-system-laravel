<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountFeatureAssignment;
use App\Models\CashFloatAssignment;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\User;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CashInRoleFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('Cash In role flow tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('r', 32));
    }

    public function test_teller_can_open_cash_in_page_but_admin_and_cashier_cannot(): void
    {
        [$teller, $tellerToken] = $this->activeTellerWithEmptyFloat();

        $this->withHeader('Authorization', 'Bearer '.$tellerToken)
            ->get('/transactions/cash-in')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('transactions/CashIn')
                ->where('role', $teller->role)
                ->where('cashInRequiresDenominations', true)
            );

        foreach (['admin', 'cashier'] as $role) {
            [, $token] = $this->userWithToken($role);

            $this->withHeader('Authorization', 'Bearer '.$token)
                ->get('/transactions/cash-in')
                ->assertForbidden();
        }
    }

    public function test_admin_cannot_submit_cash_in_from_web_or_api(): void
    {
        [, $adminToken] = $this->userWithToken('admin');
        [$account, $company] = $this->accountWithBalance(50_000);
        $this->fixedTier($company->id);

        $this->from('/transactions/cash-in')
            ->withHeader('Authorization', 'Bearer '.$adminToken)
            ->post('/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 5_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
                'amount_received' => 5_000,
                'fee_payment_method' => 'cash',
                'received_denominations' => [5_000 => 1],
            ])
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 5_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
                'amount_received' => 5_000,
                'fee_payment_method' => 'cash',
                'received_denominations' => [5_000 => 1],
            ])
            ->assertForbidden();

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame('50000.00', $account->fresh()->balance);
    }

    public function test_cash_in_page_uses_cash_in_feature_accounts(): void
    {
        [, $tellerToken] = $this->activeTellerWithEmptyFloat();
        [$account] = $this->accountWithBalance(50_000, operation: 'CashOut');

        AccountFeatureAssignment::query()->create([
            'account_id' => $account->id,
            'feature' => 'cash_in',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$tellerToken)
            ->get('/transactions/cash-in')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('transactions/CashIn')
                ->has('accounts', 1)
                ->where('accounts.0.id', $account->id)
                ->where('accounts.0.features.0', 'cash_in')
            );
    }

    public function test_teller_submits_cash_in_through_web_inertia_route(): void
    {
        [, $tellerToken] = $this->activeTellerWithEmptyFloat();
        [$account, $company] = $this->accountWithBalance(50_000);
        $this->fixedTier($company->id);

        $this->from('/transactions/cash-in')
            ->withHeader('Authorization', 'Bearer '.$tellerToken)
            ->post('/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 5_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
                'amount_received' => 5_000,
                'fee_payment_method' => 'cash',
                'received_denominations' => [5_000 => 1],
                'handoff_denominations' => [5_000 => 1],
            ])
            ->assertRedirect('/transactions/cash-in')
            ->assertSessionHas('completed');

        $transaction = Transaction::query()->firstOrFail();

        $this->assertSame('cash_in', $transaction->transaction_type);
        $this->assertSame('PENDING_CASHIER_CONFIRM', $transaction->status);
        $this->assertSame('45000.00', $account->fresh()->balance);
    }

    public function test_cashier_reviews_pending_cash_in_on_dashboard_and_confirms_it(): void
    {
        [, $tellerToken] = $this->activeTellerWithEmptyFloat();
        [$cashier, $cashierToken] = $this->userWithToken('cashier');
        [$account, $company] = $this->accountWithBalance(50_000);
        $this->fixedTier($company->id);

        $txnId = $this->withHeader('Authorization', 'Bearer '.$tellerToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 5_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
                'received_denominations' => [5_000 => 1],
                'handoff_denominations' => [5_000 => 1],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('role', 'cashier')
                ->has('pendingCashIns', 1)
                ->where('pendingCashIns.0.id', $txnId)
                ->where('pendingCashIns.0.creator_role', 'teller')
                ->where('pendingCashIns.0.settlement_amount', '5000.00')
            );

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/transactions/'.$txnId.'/confirm-cash-in', ['pin' => '9999'])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.confirmed_by', $cashier->id);
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
            'pin_hash' => $role === 'cashier' ? Hash::make('9999') : null,
        ]);

        return [$user, app(NgweLweTokenService::class)->create($user)];
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
     * @return array{0: Account, 1: Company}
     */
    private function accountWithBalance(int $balance, string $operation = 'CashIn'): array
    {
        $company = Company::query()->create([
            'name' => 'Wave-'.uniqid('', true),
            'category' => 'Pay',
        ]);
        $account = Account::query()->create([
            'company_id' => $company->id,
            'company_id' => $company->id,
            'account_name' => 'Wave Main',
            'phone_number' => '0900000000',
            'balance' => $balance,
            'is_active' => true,
        ]);

        return [$account, $company];
    }

    private function fixedTier(
        int $companyId,
        int $feeDeposit = 0,
        int $feeWithdraw = 0,
        int $commDeposit = 0,
        int $commWithdraw = 0,
    ): CommissionTier {
        return $this->createCompanyTierFixtures(
            $companyId,
            $feeDeposit,
            $feeWithdraw,
            $commDeposit,
            $commWithdraw,
        );
    }}
