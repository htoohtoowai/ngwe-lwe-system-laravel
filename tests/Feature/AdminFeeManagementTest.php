<?php

namespace Tests\Feature;

use App\Models\AgentCommissionTier;
use App\Models\Company;
use App\Models\ProviderFeeTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFeeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('admin fee management tests');
        parent::setUp();
    }

    public function test_admin_can_create_provider_percentage_fee_with_four_decimal_precision(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = Company::query()->create(['name' => 'Wave Money', 'category' => 'Pay', 'is_active' => true]);

        $this->actingAs($admin)->post('/admin/fees/provider', [
            'company_id' => $company->id,
            'feature' => 'cash_in',
            'amount_from' => 1,
            'amount_to' => 100000,
            'fee_type' => 'PERCENTAGE',
            'fee_value' => '0.0001',
            'additional_fee_type' => 'FIXED',
            'additional_fee_value' => '0.0000',
            'is_active' => true,
        ])->assertRedirect();

        $tier = ProviderFeeTier::query()->firstOrFail();
        $this->assertSame('0.0001', $tier->fee_value);
    }

    public function test_agent_tier_stores_one_range_with_both_out_and_in_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = Company::query()->create(['name' => 'Wave Money', 'category' => 'Pay', 'is_active' => true]);

        $this->actingAs($admin)->post('/admin/fees/agent', [
            'company_id' => $company->id,
            'amount_from' => 10001,
            'amount_to' => 25000,
            'commission_type' => 'FIXED',
            'out_commission_value' => '123.0000',
            'in_commission_value' => '117.0000',
            'is_active' => true,
        ])->assertRedirect();

        $tier = AgentCommissionTier::query()->firstOrFail();
        $this->assertSame('123.0000', $tier->out_commission_value);
        $this->assertSame('117.0000', $tier->in_commission_value);
        $this->assertArrayNotHasKey('feature', $tier->getAttributes());
    }

    public function test_bank_provider_cannot_have_agent_commission_tier(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $bank = Company::query()->create(['name' => 'AYA Bank', 'category' => 'Bank', 'is_active' => true]);

        $this->actingAs($admin)->from('/admin/fees/agent/create')->post('/admin/fees/agent', [
            'company_id' => $bank->id,
            'amount_from' => 1,
            'amount_to' => 10000,
            'commission_type' => 'FIXED',
            'out_commission_value' => 80,
            'in_commission_value' => 80,
            'is_active' => true,
        ])->assertSessionHasErrors('company_id');
    }
}
