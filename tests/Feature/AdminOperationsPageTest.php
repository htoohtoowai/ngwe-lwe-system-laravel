<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminOperationsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('admin operations tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('a', 32));
    }

    public function test_admin_can_open_operations_console(): void
    {
        [$admin, $token] = $this->userWithToken('admin');
        [$company, $serviceType, $account] = $this->seedServiceAccount();
        $tier = CommissionTier::query()->create([
            'service_type_id' => $serviceType->id,
            'amount_from' => 1,
            'amount_to' => 999999999,
            'fee_amount_type' => 'FIXED',
            'fee_amount_deposit' => 100,
            'fee_amount_withdraw' => 100,
            'comm_type' => 'FIXED',
            'additional_fee_type' => 'FIXED',
            'is_active' => true,
        ]);
        $rate = ExchangeRate::query()->create([
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'base_amount' => 1,
            'buy_rate' => 145,
            'sell_rate' => 150,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Overview')
                ->where('role', $admin->role)
                ->where('section', 'overview')
                ->where('notificationCount', 0)
            );

        foreach ([
            '/admin/companies' => ['companies', 'admin/Companies'],
            '/admin/service-types' => ['service-types', 'admin/ServiceTypes'],
            '/admin/exchange-rates' => ['exchange-rates', 'admin/ExchangeRates'],
            '/admin/accounts' => ['accounts', 'admin/Accounts'],
            '/admin/fees' => ['fees', 'admin/Fees'],
            '/admin/users' => ['users', 'admin/Users'],
            '/admin/vault' => ['vault', 'admin/Vault'],
            '/admin/reports' => ['reports', 'admin/Reports'],
        ] as $path => [$section, $component]) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->get($path)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component)
                    ->where('role', $admin->role)
                    ->where('section', $section)
                );
        }

        foreach ([
            '/admin/transactions' => 'records',
            '/admin/transactions/cash-in' => 'cash-in',
            '/admin/transactions/cash-out' => 'cash-out',
            '/admin/transactions/transfer' => 'transfer',
            '/admin/transactions/exchange' => 'exchange',
            '/admin/transactions/activity-logs' => 'activity-logs',
        ] as $path => $transactionSubsection) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->get($path)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component(
                        $transactionSubsection === 'activity-logs'
                            ? 'admin/transactions/ActivityLogs'
                            : match ($transactionSubsection) {
                                'records' => 'admin/transactions/All',
                                'cash-in' => 'admin/transactions/CashIn',
                                'cash-out' => 'admin/transactions/CashOut',
                                'transfer' => 'admin/transactions/Transfer',
                                'exchange' => 'admin/transactions/Exchange',
                            }
                    )
                    ->where('role', $admin->role)
                );
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/admin/master-data')
            ->assertNotFound();

        foreach ([
            '/admin/companies/create' => ['companies', 'create', null, 'admin/Companies'],
            '/admin/companies/'.$company->id => ['companies', 'detail', $company->id, 'admin/Companies'],
            '/admin/companies/'.$company->id.'/edit' => ['companies', 'edit', $company->id, 'admin/Companies'],
            '/admin/service-types/create' => ['service-types', 'create', null, 'admin/ServiceTypes'],
            '/admin/service-types/'.$serviceType->id => ['service-types', 'detail', $serviceType->id, 'admin/ServiceTypes'],
            '/admin/service-types/'.$serviceType->id.'/edit' => ['service-types', 'edit', $serviceType->id, 'admin/ServiceTypes'],
            '/admin/exchange-rates/create' => ['exchange-rates', 'create', null, 'admin/ExchangeRates'],
            '/admin/exchange-rates/'.$rate->id => ['exchange-rates', 'detail', $rate->id, 'admin/ExchangeRates'],
            '/admin/exchange-rates/'.$rate->id.'/edit' => ['exchange-rates', 'edit', $rate->id, 'admin/ExchangeRates'],
            '/admin/accounts/create' => ['accounts', 'create', null, 'admin/Accounts'],
            '/admin/accounts/'.$account->id => ['accounts', 'detail', $account->id, 'admin/Accounts'],
            '/admin/accounts/'.$account->id.'/edit' => ['accounts', 'edit', $account->id, 'admin/Accounts'],
            '/admin/fees/create' => ['fees', 'create', null, 'admin/Fees'],
            '/admin/fees/'.$tier->id => ['fees', 'detail', $tier->id, 'admin/Fees'],
            '/admin/fees/'.$tier->id.'/edit' => ['fees', 'edit', $tier->id, 'admin/Fees'],
            '/admin/users/create' => ['users', 'create', null, 'admin/Users'],
            '/admin/users/'.$admin->id => ['users', 'detail', $admin->id, 'admin/Users'],
            '/admin/users/'.$admin->id.'/edit' => ['users', 'edit', $admin->id, 'admin/Users'],
        ] as $path => [$section, $mode, $resourceId, $component]) {
            $assertion = $this->withHeader('Authorization', 'Bearer '.$token)
                ->get($path)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component)
                    ->where('role', $admin->role)
                    ->where('section', $section)
                    ->where('mode', $mode)
                );

            if ($resourceId !== null) {
                $assertion->assertInertia(fn (Assert $page) => $page
                    ->where('resourceId', $resourceId)
                );
            }
        }
    }

    public function test_non_admin_roles_cannot_open_operations_console(): void
    {
        foreach (['cashier', 'teller'] as $role) {
            [, $token] = $this->userWithToken($role);

            foreach (['/admin', '/admin/users'] as $path) {
                $this->withHeader('Authorization', 'Bearer '.$token)
                    ->get($path)
                    ->assertForbidden();
            }
        }
    }

    public function test_admin_company_action_uses_web_redirect_and_stores_logo(): void
    {
        Storage::fake('public');
        [, $token] = $this->userWithToken('admin');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/admin/actions/companies', [
                'name' => 'MAB Pay',
                'category' => 'Pay',
                'is_active' => true,
                'logo' => UploadedFile::fake()->image('mab.png', 120, 120),
            ]);

        $company = Company::query()->where('name', 'MAB Pay')->firstOrFail();
        $response->assertRedirect('/admin/companies/'.$company->id);
        $this->assertNotNull($company->logo_path);
        Storage::disk('public')->assertExists((string) $company->logo_path);
    }

    public function test_admin_action_converts_domain_error_to_inertia_form_error(): void
    {
        [, $token] = $this->userWithToken('admin');
        Company::query()->create([
            'name' => 'Existing Pay',
            'category' => 'Pay',
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->from('/admin/companies/create')
            ->post('/admin/actions/companies', [
                'name' => 'Existing Pay',
                'category' => 'Pay',
                'is_active' => true,
            ])
            ->assertRedirect('/admin/companies/create')
            ->assertSessionHasErrors('form');
    }

    public function test_non_admin_cannot_use_admin_inertia_actions(): void
    {
        [, $token] = $this->userWithToken('teller');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/admin/actions/companies', [
                'name' => 'Blocked Pay',
                'category' => 'Pay',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('companies', ['name' => 'Blocked Pay']);
    }

    public function test_admin_console_read_endpoints_are_reachable(): void
    {
        [, $token] = $this->userWithToken('admin');
        $this->seedServiceAccount();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('transaction_count', 0);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/daily-summary?date=2026-07-26')
            ->assertOk()
            ->assertJsonPath('data.summary_date', '2026-07-26');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/companies?include_inactive=1')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Wave Money');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/service-types?include_inactive=1')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'WST');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/accounts?include_inactive=1')
            ->assertOk()
            ->assertJsonPath('data.0.account_name', 'Wave Main');

        foreach ([
            '/api/users?include_inactive=1',
            '/api/transactions?limit=200',
            '/api/activity-logs?per_page=200',
            '/api/cash-floats',
            '/api/vault/inventory',
            '/api/vault/log?per_page=100',
            '/api/exchange-rates?limit=50',
            '/api/reports/daily-reconciliations?per_page=20',
        ] as $endpoint) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson($endpoint)
                ->assertOk()
                ->assertJsonStructure(['data']);
        }
    }

    public function test_admin_console_management_flows_write_expected_records(): void
    {
        [, $token] = $this->userWithToken('admin');
        Storage::fake('public');

        $companyId = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/companies', [
                'name' => 'Console Pay',
                'category' => 'Pay',
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Console Pay')
            ->json('data.id');

        $logoPath = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/companies/'.$companyId.'/logo', [
                'logo' => UploadedFile::fake()->image('console-pay.png'),
            ])
            ->assertOk()
            ->assertJsonPath('data.logo_path', fn (string $path): bool => str_starts_with($path, 'company-logos/'))
            ->json('data.logo_path');

        Storage::disk('public')->assertExists($logoPath);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/companies/'.$companyId.'/logo')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/companies/'.$companyId.'/logo')
            ->assertOk();

        $serviceTypeId = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/service-types', [
                'company_id' => $companyId,
                'name' => 'P2P',
                'operation' => 'CashIn',
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'P2P')
            ->json('data.id');

        $accountId = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/accounts', [
                'service_type_id' => $serviceTypeId,
                'account_name' => 'P2P Main',
                'phone_number' => '0912345678',
                'balance' => 10000,
                'commission_rate' => 0,
                'is_fee_account' => false,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.account_name', 'P2P Main')
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/commission-tiers', [
                'service_type_id' => $serviceTypeId,
                'amount_from' => 1,
                'amount_to' => 999999999,
                'fee_amount_type' => 'FIXED',
                'fee_amount_deposit' => 100,
                'fee_amount_withdraw' => 100,
                'comm_type' => 'FIXED',
                'comm_deposit' => 0,
                'comm_withdraw' => 0,
                'additional_fee_type' => 'FIXED',
                'additional_fee_deposit_amount' => 0,
                'additional_fee_withdraw_amount' => 0,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.fee_amount_cash_in', '100.0000');

        $userId = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/users', [
                'username' => 'console_teller',
                'full_name' => 'Console Teller',
                'role' => 'teller',
                'password' => 'password123',
                'pin' => '1234',
            ])
            ->assertCreated()
            ->assertJsonPath('data.has_pin', true)
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/users/'.$userId.'/reset-password', [
                'new_password' => 'newpass123',
            ])
            ->assertOk()
            ->assertJsonPath('user_id', $userId);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/users/'.$userId.'/pin', [
                'pin' => '4321',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'PIN set');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/accounts/'.$accountId.'/balance-adjust', [
                'amount' => 500,
                'remark' => 'Console top-up',
            ])
            ->assertOk()
            ->assertJsonPath('data.new_balance', '10500.00');
    }

    public function test_non_admin_roles_cannot_use_admin_management_endpoints(): void
    {
        [, $token] = $this->userWithToken('teller');
        [, , $account] = $this->seedServiceAccount();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/users')
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/activity-logs')
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/companies', [
                'name' => 'Blocked Pay',
                'category' => 'Pay',
            ])
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/accounts/'.$account->id.'/balance-adjust', [
                'amount' => 100,
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function userWithToken(string $role): array
    {
        $user = User::factory()->create([
            'username' => $role.'_'.uniqid('', true),
            'full_name' => ucfirst($role).' User',
            'role' => $role,
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);

        return [$user, app(NgweLweTokenService::class)->create($user)];
    }

    /**
     * @return array{0: Company, 1: ServiceType, 2: Account}
     */
    private function seedServiceAccount(): array
    {
        $company = Company::query()->create([
            'name' => 'Wave Money',
            'category' => 'Pay',
            'is_active' => true,
        ]);
        $serviceType = ServiceType::query()->create([
            'company_id' => $company->id,
            'name' => 'WST',
            'operation' => 'CashIn',
            'is_active' => true,
        ]);
        $account = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'Wave Main',
            'phone_number' => '0900000000',
            'balance' => '10000.00',
            'is_active' => true,
        ]);

        return [$company, $serviceType, $account];
    }
}
