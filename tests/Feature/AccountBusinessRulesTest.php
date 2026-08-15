<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountBusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('account business rule tests');
        parent::setUp();
    }

    public function test_same_identifier_is_allowed_across_different_providers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kbz = Company::query()->create(['name' => 'KBZ Pay', 'category' => 'Pay', 'is_active' => true]);
        $wave = Company::query()->create(['name' => 'Wave Money', 'category' => 'Pay', 'is_active' => true]);

        $payload = [
            'account_name' => 'Main',
            'account_type' => 'PAY',
            'account_identifier' => '09256149967',
            'balance' => 0,
            'is_active' => true,
            'is_fee_account' => false,
            'is_agent' => false,
            'features' => ['cash_in'],
        ];

        $this->actingAs($admin)->post('/admin/actions/accounts', [...$payload, 'company_id' => $kbz->id])->assertRedirect();
        $this->actingAs($admin)->post('/admin/actions/accounts', [...$payload, 'company_id' => $wave->id])->assertRedirect();

        $this->assertSame(2, Account::query()->where('account_identifier', '09256149967')->count());
    }

    public function test_same_identifier_is_rejected_inside_same_provider(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = Company::query()->create(['name' => 'KBZ Pay', 'category' => 'Pay', 'is_active' => true]);

        $payload = [
            'company_id' => $company->id,
            'account_name' => 'Main',
            'account_type' => 'PAY',
            'account_identifier' => '09256149967',
            'balance' => 0,
            'is_active' => true,
            'is_fee_account' => false,
            'is_agent' => false,
            'features' => ['cash_in'],
        ];

        $this->actingAs($admin)->post('/admin/actions/accounts', $payload)->assertRedirect();
        $this->actingAs($admin)->from('/admin/accounts/create')->post('/admin/actions/accounts', [
            ...$payload,
            'account_name' => 'Duplicate',
        ])->assertSessionHasErrors('account_identifier');
    }

    public function test_bank_account_cannot_be_agent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $bank = Company::query()->create(['name' => 'KBZ Bank', 'category' => 'Bank', 'is_active' => true]);

        $this->actingAs($admin)
            ->from('/admin/accounts/create')
            ->post('/admin/actions/accounts', [
                'company_id' => $bank->id,
                'account_name' => 'KBZ Bank Main',
                'account_type' => 'BANK',
                'account_identifier' => '0123456789012',
                'balance' => 0,
                'is_active' => true,
                'is_fee_account' => false,
                'is_agent' => true,
                'features' => ['transfer'],
            ])
            ->assertSessionHasErrors('is_agent');
    }
}
