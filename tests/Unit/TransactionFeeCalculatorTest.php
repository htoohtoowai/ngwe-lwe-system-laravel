<?php

namespace Tests\Unit;

use App\Enums\AccountFeature;
use App\Enums\AccountType;
use App\Enums\AgentCommissionDirection;
use App\Models\Account;
use App\Models\AgentCommissionTier;
use App\Models\ProviderFeeTier;
use App\Repositories\AgentCommissionTierRepository;
use App\Repositories\ProviderFeeTierRepository;
use App\Services\AgentCommissionCalculator;
use App\Services\TierValueCalculator;
use App\Services\TransactionFeeCalculator;
use PHPUnit\Framework\TestCase;

class TransactionFeeCalculatorTest extends TestCase
{
    public function test_returns_zero_when_no_provider_fee_tier_matches(): void
    {
        $calc = new TransactionFeeCalculator($this->feeRepo(), new TierValueCalculator());

        $this->assertSame('0.00', $calc->resolveFees($this->account(null), 5000, TransactionFeeCalculator::MODE_CASH_IN)['customer_fee']);
        $this->assertSame('0.00', $calc->resolveFees($this->account(1), 5000, TransactionFeeCalculator::MODE_CASH_IN)['customer_fee']);
    }

    public function test_fixed_fee_uses_feature_tier_and_mmk_rounding(): void
    {
        $tier = $this->feeTier(AccountFeature::CashIn, [
            'fee_type' => 'FIXED',
            'fee_value' => 1020,
        ]);

        $result = (new TransactionFeeCalculator($this->feeRepo($tier), new TierValueCalculator()))
            ->resolveFees($this->account(1), 100_000, TransactionFeeCalculator::MODE_CASH_IN);

        $this->assertSame('1000.00', $result['customer_fee']);
        $this->assertSame('0.00', $result['additional_fee']);
    }

    public function test_percentage_fee_supports_four_decimal_human_percent_values(): void
    {
        $tier = $this->feeTier(AccountFeature::CashIn, [
            'fee_type' => 'PERCENTAGE',
            'fee_value' => 0.0001,
        ]);

        $result = (new TransactionFeeCalculator($this->feeRepo($tier), new TierValueCalculator()))
            ->resolveFees($this->account(1), 1_000_000, TransactionFeeCalculator::MODE_CASH_IN);

        // Raw fee is 1 MMK, then the customer-fee minimum/rounding rule applies.
        $this->assertSame('100.00', $result['customer_fee']);
    }

    public function test_additional_provider_fee_can_use_percentage_independently(): void
    {
        $tier = $this->feeTier(AccountFeature::CashOut, [
            'fee_type' => 'FIXED',
            'fee_value' => 0,
            'additional_fee_type' => 'PERCENTAGE',
            'additional_fee_value' => 0.0001,
        ]);

        $result = (new TransactionFeeCalculator($this->feeRepo($tier), new TierValueCalculator()))
            ->resolveFees($this->account(1), 1_000_000, TransactionFeeCalculator::MODE_CASH_OUT);

        $this->assertSame('100.00', $result['customer_fee']);
        $this->assertSame('1.00', $result['additional_fee']);
    }

    public function test_cash_in_and_cash_out_use_independent_provider_fee_rows(): void
    {
        $cashIn = $this->feeTier(AccountFeature::CashIn, ['fee_value' => 100]);
        $cashOut = $this->feeTier(AccountFeature::CashOut, ['fee_value' => 500]);
        $calc = new TransactionFeeCalculator($this->feeRepo($cashIn, $cashOut), new TierValueCalculator());

        $this->assertSame('100.00', $calc->resolveFees($this->account(1), 50_000, TransactionFeeCalculator::MODE_CASH_IN)['customer_fee']);
        $this->assertSame('500.00', $calc->resolveFees($this->account(1), 50_000, TransactionFeeCalculator::MODE_CASH_OUT)['customer_fee']);
    }

    public function test_agent_commission_uses_out_or_in_value_from_same_amount_tier(): void
    {
        $tier = $this->commissionTier([
            'commission_type' => 'FIXED',
            'out_commission_value' => 123,
            'in_commission_value' => 117,
        ]);
        $calc = new AgentCommissionCalculator($this->commissionRepo($tier), new TierValueCalculator());
        $account = $this->account(1, true, AccountType::Pay);

        $out = $calc->resolveForMovement($account, 20_000, -20_000);
        $in = $calc->resolveForMovement($account, 20_000, 20_000);

        $this->assertSame('123.00', $out['amount']);
        $this->assertSame(AgentCommissionDirection::Out, $out['direction']);
        $this->assertSame('117.00', $in['amount']);
        $this->assertSame(AgentCommissionDirection::In, $in['direction']);
    }

    public function test_non_agent_and_bank_accounts_never_receive_agent_commission(): void
    {
        $tier = $this->commissionTier([
            'out_commission_value' => 123,
            'in_commission_value' => 117,
        ]);
        $calc = new AgentCommissionCalculator($this->commissionRepo($tier), new TierValueCalculator());

        $this->assertSame('0.00', $calc->resolveForMovement($this->account(1, false, AccountType::Pay), 20_000, -20_000)['amount']);
        $this->assertSame('0.00', $calc->resolveForMovement($this->account(1, true, AccountType::Bank), 20_000, -20_000)['amount']);
    }

    public function test_percentage_agent_commission_supports_four_decimal_percent_value(): void
    {
        $tier = $this->commissionTier([
            'company_id' => 7,
            'commission_type' => 'PERCENTAGE',
            'out_commission_value' => 0.0001,
            'in_commission_value' => 0.0001,
        ]);
        $calc = new AgentCommissionCalculator($this->commissionRepo($tier), new TierValueCalculator());

        $this->assertSame('1.00', $calc->resolveForMovement($this->account(7), 1_000_000, 1_000_000)['amount']);
    }

    private function account(?int $companyId, bool $isAgent = true, AccountType $accountType = AccountType::Pay): Account
    {
        $account = new Account();
        $account->id = 42;
        $account->company_id = $companyId;
        $account->account_type = $accountType;
        $account->is_agent = $isAgent;

        return $account;
    }

    /** @param array<string, int|float|string|bool> $overrides */
    private function feeTier(AccountFeature $feature, array $overrides = []): ProviderFeeTier
    {
        $tier = new ProviderFeeTier();
        $tier->forceFill(array_merge([
            'company_id' => 1,
            'feature' => $feature->value,
            'amount_from' => 1,
            'amount_to' => 999_999_999,
            'fee_type' => 'FIXED',
            'fee_value' => 0,
            'additional_fee_type' => 'FIXED',
            'additional_fee_value' => 0,
            'is_active' => true,
        ], $overrides));

        return $tier;
    }

    /** @param array<string, int|float|string|bool> $overrides */
    private function commissionTier(array $overrides = []): AgentCommissionTier
    {
        $tier = new AgentCommissionTier();
        $tier->forceFill(array_merge([
            'company_id' => 1,
            'amount_from' => 1,
            'amount_to' => 999_999_999,
            'commission_type' => 'FIXED',
            'out_commission_value' => 0,
            'in_commission_value' => 0,
            'is_active' => true,
        ], $overrides));

        return $tier;
    }

    private function feeRepo(ProviderFeeTier ...$tiers): ProviderFeeTierRepository
    {
        return new class($tiers) extends ProviderFeeTierRepository
        {
            /** @param list<ProviderFeeTier> $tiers */
            public function __construct(private readonly array $tiers) {}

            public function findForCompanyFeature(int $companyId, string $feature, float|string $amount): ?ProviderFeeTier
            {
                foreach ($this->tiers as $tier) {
                    if ((int) $tier->company_id === $companyId && $tier->feature === $feature) {
                        return $tier;
                    }
                }

                return null;
            }
        };
    }

    private function commissionRepo(AgentCommissionTier ...$tiers): AgentCommissionTierRepository
    {
        return new class($tiers) extends AgentCommissionTierRepository
        {
            /** @param list<AgentCommissionTier> $tiers */
            public function __construct(private readonly array $tiers) {}

            public function findForCompany(int $companyId, float|string $amount): ?AgentCommissionTier
            {
                foreach ($this->tiers as $tier) {
                    if ((int) $tier->company_id === $companyId
                        && (float) $tier->amount_from <= (float) $amount
                        && (float) $tier->amount_to >= (float) $amount) {
                        return $tier;
                    }
                }

                return null;
            }
        };
    }
}
