<?php

namespace Tests\Feature;

use App\Enums\AccountFeature;
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

    public function test_non_send_receive_uses_cash_in_or_cash_out_feature_from_principal_movement(): void
    {
        [$account, $company] = $this->createCompanyAccountFixture(100000, isAgent: true);

        AgentCommissionTier::query()->create([
            'company_id' => $company->id,
            'feature' => AccountFeature::CashOut->value,
            'amount_from' => 10001,
            'amount_to' => 25000,
            'commission_type' => 'FIXED',
            'out_commission_value' => 123,
            'in_commission_value' => 0,
            'is_active' => true,
        ]);

        AgentCommissionTier::query()->create([
            'company_id' => $company->id,
            'feature' => AccountFeature::CashIn->value,
            'amount_from' => 10001,
            'amount_to' => 25000,
            'commission_type' => 'FIXED',
            'out_commission_value' => 0,
            'in_commission_value' => 117,
            'is_active' => true,
        ]);

        $calculator = app(AgentCommissionCalculator::class);

        $out = $calculator->resolveForMovement(
            $account,
            20000,
            -20000,
            AccountFeature::Transfer,
        );
        $in = $calculator->resolveForMovement(
            $account,
            20000,
            20000,
            AccountFeature::Exchange,
        );

        $this->assertSame('123.00', $out['amount']);
        $this->assertSame(AgentCommissionDirection::Out, $out['direction']);
        $this->assertSame(AccountFeature::CashOut->value, $out['tier']?->feature);

        $this->assertSame('117.00', $in['amount']);
        $this->assertSame(AgentCommissionDirection::In, $in['direction']);
        $this->assertSame(AccountFeature::CashIn->value, $in['tier']?->feature);
    }

    public function test_send_and_receive_money_use_dedicated_features(): void
    {
        [$account, $company] = $this->createCompanyAccountFixture(100000, isAgent: true);

        AgentCommissionTier::query()->create([
            'company_id' => $company->id,
            'feature' => AccountFeature::SendMoney->value,
            'amount_from' => 1,
            'amount_to' => 999999,
            'commission_type' => 'FIXED',
            'out_commission_value' => 88,
            'in_commission_value' => 0,
            'is_active' => true,
        ]);

        AgentCommissionTier::query()->create([
            'company_id' => $company->id,
            'feature' => AccountFeature::ReceiveMoney->value,
            'amount_from' => 1,
            'amount_to' => 999999,
            'commission_type' => 'FIXED',
            'out_commission_value' => 0,
            'in_commission_value' => 99,
            'is_active' => true,
        ]);

        $calculator = app(AgentCommissionCalculator::class);

        $send = $calculator->resolveForMovement(
            $account,
            20000,
            -20000,
            AccountFeature::SendMoney,
        );
        $receive = $calculator->resolveForMovement(
            $account,
            20000,
            20000,
            AccountFeature::ReceiveMoney,
        );

        $this->assertSame('88.00', $send['amount']);
        $this->assertSame(AccountFeature::SendMoney->value, $send['tier']?->feature);
        $this->assertSame('99.00', $receive['amount']);
        $this->assertSame(AccountFeature::ReceiveMoney->value, $receive['tier']?->feature);
    }

    public function test_non_agent_pay_account_never_earns_commission(): void
    {
        [$account, $company] = $this->createCompanyAccountFixture(100000, isAgent: false);

        AgentCommissionTier::query()->create([
            'company_id' => $company->id,
            'feature' => AccountFeature::SendMoney->value,
            'amount_from' => 1,
            'amount_to' => 999999,
            'commission_type' => 'FIXED',
            'out_commission_value' => 500,
            'in_commission_value' => 500,
            'is_active' => true,
        ]);

        $result = app(AgentCommissionCalculator::class)->resolveForMovement(
            $account,
            20000,
            -20000,
            AccountFeature::SendMoney,
        );

        $this->assertSame('0.00', $result['amount']);
        $this->assertNull($result['tier']);
    }

    public function test_percentage_agent_commission_supports_zero_point_zero_zero_zero_one_percent(): void
    {
        [$account, $company] = $this->createCompanyAccountFixture(2000000, isAgent: true);

        AgentCommissionTier::query()->create([
            'company_id' => $company->id,
            'feature' => AccountFeature::CashIn->value,
            'amount_from' => 1,
            'amount_to' => 999999999,
            'commission_type' => 'PERCENTAGE',
            'out_commission_value' => '0.0001',
            'in_commission_value' => '0.0001',
            'is_active' => true,
        ]);

        $result = app(AgentCommissionCalculator::class)->resolveForMovement(
            $account,
            1000000,
            1000000,
            AccountFeature::Exchange,
        );

        $this->assertSame('1.00', $result['amount']);
    }
}
