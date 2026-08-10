<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NgweLweSetupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('setup API tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('c', 32));
    }

    public function test_owner_can_create_and_list_company(): void
    {
        $token = $this->tokenForRole('admin');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/companies', ['name' => 'KBZ Pay', 'category' => 'Pay'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'KBZ Pay')
            ->assertJsonPath('data.category', 'Pay');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/companies')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'KBZ Pay');
    }

    public function test_employee_can_read_but_cannot_create_company(): void
    {
        Company::query()->create(['name' => 'AYA Pay', 'category' => 'Pay']);
        $token = $this->tokenForRole('teller');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/companies')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'AYA Pay');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/companies', ['name' => 'CB Pay', 'category' => 'Pay'])
            ->assertForbidden();
    }

    public function test_company_list_orders_active_first_then_by_name(): void
    {
        Company::query()->create(['name' => 'Zulu Pay', 'category' => 'Pay', 'is_active' => true]);
        Company::query()->create(['name' => 'Alpha Bank', 'category' => 'Bank', 'is_active' => false]);
        Company::query()->create(['name' => 'Beta Pay', 'category' => 'Pay', 'is_active' => true]);

        $token = $this->tokenForRole('admin');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/companies?include_inactive=1')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Beta Pay')
            ->assertJsonPath('data.1.name', 'Zulu Pay')
            ->assertJsonPath('data.2.name', 'Alpha Bank');
    }

    public function test_inactive_company_accounts_are_excluded_from_select_data(): void
    {
        $company = Company::query()->create([
            'name' => 'Inactive Provider',
            'category' => 'Pay',
            'is_active' => false,
        ]);
        Account::query()->create([
            'company_id' => $company->id,
            'account_name' => 'Hidden Account',
            'phone_number' => '09111111111',
            'is_active' => true,
        ]);

        $token = $this->tokenForRole('admin');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_service_type_api_is_removed(): void
    {
        $token = $this->tokenForRole('admin');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/service-types')
            ->assertNotFound();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/service-types', [])
            ->assertNotFound();
    }

    public function test_owner_can_create_account_with_company_and_features(): void
    {
        $token = $this->tokenForRole('admin');
        $company = Company::query()->create(['name' => 'Wave Money', 'category' => 'Pay']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/accounts', [
                'company_id' => $company->id,
                'features' => ['cash_in', 'cash_out'],
                'account_name' => 'Wave Main',
                'phone_number' => '09999999999',
                'balance' => 1000.555,
                'is_fee_account' => true,
                'is_agent' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $company->id)
            ->assertJsonPath('data.balance', '1000.56')
            ->assertJsonPath('data.is_agent', true)
            ->assertJsonPath('data.features.0', 'cash_in')
            ->assertJsonPath('data.features.1', 'cash_out');
    }

    public function test_account_features_can_be_updated_without_resetting_balance(): void
    {
        $token = $this->tokenForRole('admin');
        [$account] = $this->createCompanyAccountFixture(5000, 'KBZ Cash In', false, []);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/accounts/'.$account->id, ['features' => ['cash_in']])
            ->assertOk()
            ->assertJsonPath('data.balance', '5000.00')
            ->assertJsonPath('data.features.0', 'cash_in');
    }

    public function test_account_company_name_and_number_combination_must_be_unique(): void
    {
        $token = $this->tokenForRole('admin');
        $company = Company::query()->create(['name' => 'KBZ Pay', 'category' => 'Pay']);
        $payload = [
            'company_id' => $company->id,
            'features' => ['cash_in'],
            'account_name' => 'Main Account',
            'phone_number' => '09123456789',
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/accounts', $payload)
            ->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/accounts', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone_number');
    }

    public function test_delete_routes_soft_deactivate_account_and_company(): void
    {
        $token = $this->tokenForRole('admin');
        [$account, $company] = $this->createCompanyAccountFixture(0, 'KBZ Main');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/accounts/'.$account->id)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/companies/'.$company->id)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    private function tokenForRole(string $role): string
    {
        $user = User::factory()->create([
            'username' => $role.'_user',
            'full_name' => ucfirst($role).' User',
            'role' => $role,
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);

        return app(NgweLweTokenService::class)->create($user);
    }
}
