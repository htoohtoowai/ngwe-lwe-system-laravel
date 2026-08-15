<?php

namespace Tests\Feature;

use App\Enums\AgentCommissionDirection;
use App\Models\AgentCommissionTier;
use App\Services\AgentCommissionCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentCommissionBusinessRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('agent commission business rule tests');
        parent::setUp();
    }

    public function test_pay_agent_uses_out_value_when_principal_leaves_account_and_in_value_when_it_enters(): void
    {
        [$account, $company] = $this->createCompanyAccountFixture(100000, isAgent: true);
        AgentCommissionTier::query()->create([
            'company_id' => $company->id,
            'amount_from' => 10001,
            'amount_to' => 25000,
            'commission_type' => 'FIXED',
            'out_commission_value' => 123,
            'in_commission_value' => 117,
            'is_active' => true,
        ]);

        $calculator = app(AgentCommissionCalculator::class);
        $out = $calculator->resolveForMovement($account, 20000, -20000);
        $in = $calculator->resolveForMovement($account, 20000, 20000);

        $this->assertSame('123.00', $out['amount']);
        $this->assertSame(AgentCommissionDirection::Out, $out['direction']);
        $this->assertSame('117.00', $in['amount']);
        $this->assertSame(AgentCommissionDirection::In, $in['direction']);
    }

    public function test_non_agent_pay_account_never_earns_commission(): void
    {
        [$account, $company] = $this->createCompanyAccountFixture(100000, isAgent: false);
        AgentCommissionTier::query()->create([
            'company_id' => $company->id,
            'amount_from' => 1,
            'amount_to' => 999999,
            'commission_type' => 'FIXED',
            'out_commission_value' => 500,
            'in_commission_value' => 500,
            'is_active' => true,
        ]);

        $result = app(AgentCommissionCalculator::class)->resolveForMovement($account, 20000, -20000);
        $this->assertSame('0.00', $result['amount']);
        $this->assertNull($result['tier']);
    }

    public function test_percentage_agent_commission_supports_zero_point_zero_zero_zero_one_percent(): void
    {
        [$account, $company] = $this->createCompanyAccountFixture(2000000, isAgent: true);
        AgentCommissionTier::query()->create([
            'company_id' => $company->id,
            'amount_from' => 1,
            'amount_to' => 999999999,
            'commission_type' => 'PERCENTAGE',
            'out_commission_value' => '0.0001',
            'in_commission_value' => '0.0001',
            'is_active' => true,
        ]);

        $result = app(AgentCommissionCalculator::class)->resolveForMovement($account, 1000000, 1000000);
        $this->assertSame('1.00', $result['amount']);
    }
}
