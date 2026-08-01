<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\ServiceType;
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
            ->postJson('/api/companies', [
                'name' => 'KBZ Pay',
                'category' => 'Pay',
            ])
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
        Company::query()->create([
            'name' => 'AYA Pay',
            'category' => 'Pay',
        ]);

        $token = $this->tokenForRole('teller');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/companies')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'AYA Pay');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/companies', [
                'name' => 'CB Pay',
                'category' => 'Pay',
            ])
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
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonPath('data.1.name', 'Zulu Pay')
            ->assertJsonPath('data.1.is_active', true)
            ->assertJsonPath('data.2.name', 'Alpha Bank')
            ->assertJsonPath('data.2.is_active', false);
    }

    public function test_inactive_company_children_are_excluded_from_select_data(): void
    {
        $company = Company::query()->create([
            'name' => 'Inactive Provider',
            'category' => 'Pay',
            'is_active' => false,
        ]);
        $serviceType = ServiceType::query()->create([
            'company_id' => $company->id,
            'name' => 'Cash In',
            'operation' => 'CashIn',
            'is_active' => true,
        ]);
        Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'Hidden Account',
            'phone_number' => '09111111111',
            'is_active' => true,
        ]);

        $token = $this->tokenForRole('admin');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/service-types')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_owner_can_create_service_type_and_account(): void
    {
        $token = $this->tokenForRole('admin');
        $company = Company::query()->create([
            'name' => 'Wave Money',
            'category' => 'Pay',
        ]);

        $serviceTypeId = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/service-types', [
                'company_id' => $company->id,
                'name' => 'Cash In',
                'operation' => 'CashIn',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Cash In')
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/accounts', [
                'service_type_id' => $serviceTypeId,
                'account_name' => 'Wave Main',
                'phone_number' => '09999999999',
                'balance' => 1000.555,
                'commission_rate' => 1.25,
                'is_fee_account' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.account_name', 'Wave Main')
            ->assertJsonPath('data.balance', '1000.56')
            ->assertJsonPath('data.commission_rate', '1.2500')
            ->assertJsonPath('data.is_fee_account', true);
    }

    public function test_service_types_list_active_records_before_inactive_records(): void
    {
        $token = $this->tokenForRole('admin');
        $company = Company::query()->create([
            'name' => 'Wave Money',
            'category' => 'Pay',
        ]);

        ServiceType::query()->create([
            'company_id' => $company->id,
            'name' => 'A Inactive',
            'operation' => 'CashIn',
            'is_active' => false,
        ]);
        ServiceType::query()->create([
            'company_id' => $company->id,
            'name' => 'B Active',
            'operation' => 'CashOut',
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/service-types?include_inactive=1')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'B Active')
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonPath('data.1.name', 'A Inactive')
            ->assertJsonPath('data.1.is_active', false);
    }

    public function test_account_service_name_and_number_combination_must_be_unique(): void
    {
        $token = $this->tokenForRole('admin');
        $company = Company::query()->create([
            'name' => 'KBZ Pay',
            'category' => 'Pay',
        ]);
        $serviceType = ServiceType::query()->create([
            'company_id' => $company->id,
            'name' => 'K Pay',
            'operation' => 'CashIn',
        ]);
        $payload = [
            'service_type_id' => $serviceType->id,
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

    public function test_delete_routes_soft_deactivate_setup_records(): void
    {
        $token = $this->tokenForRole('admin');
        $company = Company::query()->create([
            'name' => 'KBZ Bank',
            'category' => 'Bank',
        ]);
        $serviceType = ServiceType::query()->create([
            'company_id' => $company->id,
            'name' => 'Transfer',
            'operation' => 'Transfer',
        ]);
        $account = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'KBZ Main',
            'phone_number' => '09111111111',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/accounts/'.$account->id)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/service-types/'.$serviceType->id)
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
