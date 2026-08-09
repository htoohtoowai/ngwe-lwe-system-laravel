<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\ServiceType;
use App\Models\Transaction;
use App\Models\User;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CashierOperationsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('cashier operations page tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('q', 32));
    }

    public function test_cashier_can_open_each_operations_page(): void
    {
        [$cashier, $token] = $this->userWithToken('cashier');

        foreach ([
            '/cashier' => 'cashier/Dashboard',
            '/cashier/teller-entry-notifications' => 'cashier/Notifications',
            '/cashier/main-vault-denomination-stock' => 'cashier/VaultStock',
            '/cashier/morning-issue' => 'cashier/MorningIssue',
            '/cashier/end-of-day' => 'cashier/EndOfDay',
            '/cashier/teller-entry-history' => 'cashier/history/All',
            '/cashier/teller-entry-history-cash-in' => 'cashier/history/CashIn',
            '/cashier/teller-entry-history-cash-out' => 'cashier/history/CashOut',
            '/cashier/teller-entry-history-transfer' => 'cashier/history/Transfer',
            '/cashier/teller-entry-history-exchange' => 'cashier/history/Exchange',
            '/cashier/main-vault-audit-log' => 'cashier/VaultAuditLog',
        ] as $path => $component) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->get($path)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component)
                    ->where('role', $cashier->role)
                );
        }
    }

    public function test_unknown_cashier_operations_page_is_not_found(): void
    {
        [, $token] = $this->userWithToken('cashier');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/cashier/not-a-page')
            ->assertNotFound();
    }

    public function test_cashier_profile_route_still_opens_profile_page(): void
    {
        [$cashier, $token] = $this->userWithToken('cashier');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/cashier/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('cashier/Profile')
                ->where('role', $cashier->role)
                ->where('user.id', $cashier->id)
            );
    }

    public function test_cashier_notifications_include_review_settlement_details(): void
    {
        [, $cashierToken] = $this->userWithToken('cashier');
        [$teller] = $this->userWithToken('teller');
        $account = $this->account();

        $transaction = Transaction::query()->create([
            'transaction_type' => 'cash_in',
            'account_id' => $account->id,
            'customer_name' => 'Aung',
            'customer_phone' => '09',
            'amount' => 5_000,
            'customer_fee' => 0,
            'commission_amount' => 0,
            'additional_fee_amount' => 0,
            'balance_change' => -5_000,
            'currency' => 'MMK',
            'fee_payment_method' => 'cash',
            'created_by' => $teller->id,
            'status' => 'PENDING_CASHIER_CONFIRM',
            'vault_impact' => 'none',
            'received_denominations' => [5_000 => 1],
            'handoff_denominations' => [5_000 => 1],
            'change_denominations' => [],
            'change_given' => 0,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->get('/cashier/teller-entry-notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('cashier/Notifications')
                ->has('pendingCashIns', 1)
                ->where('pendingCashIns.0.id', $transaction->id)
                ->where('pendingCashIns.0.creator_role', 'teller')
                ->where('pendingCashIns.0.settlement_amount', '5000.00')
                ->where('pendingCashIns.0.customer_fee', '0.00')
                ->where('pendingCashIns.0.fee_payment_method', 'cash')
            );

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->from('/cashier/teller-entry-notifications')
            ->post('/cashier/notifications/'.$transaction->id.'/read')
            ->assertRedirect('/cashier/teller-entry-notifications');

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->get('/cashier/teller-entry-notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('cashier/Notifications')
                ->where('notificationCount', 0)
                ->has('pendingCashIns', 1)
            );
    }

    public function test_non_cashier_roles_cannot_open_cashier_operations_pages(): void
    {
        foreach (['admin', 'teller'] as $role) {
            [, $token] = $this->userWithToken($role);

            $this->withHeader('Authorization', 'Bearer '.$token)
                ->get('/cashier/morning-issue')
                ->assertForbidden();
        }
    }

    public function test_cashier_can_update_pin_through_web_action(): void
    {
        [$cashier, $token] = $this->userWithToken('cashier');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->from('/cashier/profile')
            ->post('/cashier/profile/pin', ['pin' => '2468'])
            ->assertRedirect('/cashier/profile');

        $this->assertTrue(Hash::check('2468', (string) $cashier->refresh()->pin_hash));
    }

    public function test_non_cashier_cannot_call_cashier_web_actions(): void
    {
        [, $token] = $this->userWithToken('teller');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/cashier/profile/pin', ['pin' => '2468'])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function userWithToken(string $role): array
    {
        $user = User::factory()->create([
            'username' => $role.'_cashier_page_'.uniqid('', true),
            'full_name' => ucfirst($role).' User',
            'role' => $role,
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);

        return [$user, app(NgweLweTokenService::class)->create($user)];
    }

    private function account(): Account
    {
        $company = Company::query()->create([
            'name' => 'Wave-'.uniqid('', true),
            'category' => 'Pay',
        ]);
        $serviceType = ServiceType::query()->create([
            'company_id' => $company->id,
            'name' => 'WST',
            'operation' => 'CashIn',
            'is_active' => true,
        ]);

        return Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'Wave Main',
            'phone_number' => '0900000000',
            'balance' => 50_000,
            'is_active' => true,
        ]);
    }
}
