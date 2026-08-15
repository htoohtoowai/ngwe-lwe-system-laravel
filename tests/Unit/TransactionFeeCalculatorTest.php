<?php

namespace Tests\Unit;

use App\Enums\AccountFeature;
use App\Models\Account;
use App\Models\CommissionTier;
use App\Repositories\CommissionTierRepository;
use App\Services\TransactionFeeCalculator;
use PHPUnit\Framework\TestCase;

class TransactionFeeCalculatorTest extends TestCase
{
    public function test_returns_zero_when_no_company_or_tier_matches(): void
    {
        $calc = new TransactionFeeCalculator($this->tierRepo());

        $this->assertSame('0.00', $calc->resolveFees($this->account(null), 5000, TransactionFeeCalculator::MODE_CASH_IN)['customer_fee']);
        $this->assertSame('0.00', $calc->resolveFees($this->account(1), 5000, TransactionFeeCalculator::MODE_CASH_IN)['customer_fee']);
    }

    public function test_fixed_fee_uses_feature_tier_and_mmk_rounding(): void
    {
        $tier = $this->tier(AccountFeature::CashIn, [
            'fee_type' => 'FIXED',
            'fee_amount' => 1020,
        ]);

        $result = (new TransactionFeeCalculator($this->tierRepo($tier)))
            ->resolveFees($this->account(1), 100_000, TransactionFeeCalculator::MODE_CASH_IN);

        $this->assertSame('1000.00', $result['customer_fee']);
        $this->assertSame('0.00', $result['additional_fee']);
    }

    public function test_percentage_fee_supports_human_percent_values_and_additional_fee(): void
    {
        $tier = $this->tier(AccountFeature::CashIn, [
            'fee_type' => 'PERCENTAGE',
            'fee_amount' => 1,
            'additional_fee_type' => 'FIXED',
            'additional_fee_amount' => 250,
        ]);

        $result = (new TransactionFeeCalculator($this->tierRepo($tier)))
            ->resolveFees($this->account(1), 100_000, TransactionFeeCalculator::MODE_CASH_IN);

        $this->assertSame('1300.00', $result['customer_fee']);
        $this->assertSame('250.00', $result['additional_fee']);
    }

    public function test_cash_out_looks_up_cash_out_feature(): void
    {
        $cashIn = $this->tier(AccountFeature::CashIn, ['fee_amount' => 100]);
        $cashOut = $this->tier(AccountFeature::CashOut, ['fee_amount' => 500]);
        $calc = new TransactionFeeCalculator($this->tierRepo($cashIn, $cashOut));

        $this->assertSame(
            '500.00',
            $calc->resolveFees($this->account(1), 50_000, TransactionFeeCalculator::MODE_CASH_OUT)['customer_fee'],
        );
    }

    public function test_agent_commission_uses_feature_tier_commission(): void
    {
        $cashIn = $this->tier(AccountFeature::CashIn, ['comm_type' => 'FIXED', 'comm_amount' => 750]);
        $cashOut = $this->tier(AccountFeature::CashOut, ['comm_type' => 'PERCENTAGE', 'comm_amount' => 1]);
        $calc = new TransactionFeeCalculator($this->tierRepo($cashIn, $cashOut));
        $account = $this->account(1);

        $this->assertSame('750.00', $calc->commission($account, 100_000, TransactionFeeCalculator::COMMISSION_SEND));
        $this->assertSame('1000.00', $calc->commission($account, 100_000, TransactionFeeCalculator::COMMISSION_RECEIVE));
        $this->assertSame('0.00', $calc->commission($this->account(1, false), 100_000, TransactionFeeCalculator::COMMISSION_SEND));
    }

    public function test_point_two_percent_cash_out_calculates_from_company_feature_tier(): void
    {
        $tier = $this->tier(AccountFeature::CashOut, [
            'company_id' => 7,
            'fee_type' => 'PERCENTAGE',
            'fee_amount' => 0.2,
        ]);
        $calc = new TransactionFeeCalculator($this->tierRepo($tier));

        $result = $calc->resolveFees($this->account(7), 210_000, TransactionFeeCalculator::MODE_CASH_OUT);

        $this->assertSame('400.00', $result['customer_fee']);
    }

    public function test_send_money_commission_uses_requested_feature(): void
    {
        $tier = $this->tier(AccountFeature::SendMoney, [
            'company_id' => 7,
            'comm_type' => 'PERCENTAGE',
            'comm_amount' => 0.1,
        ]);
        $calc = new TransactionFeeCalculator($this->tierRepo($tier));

        $this->assertSame(
            '210.00',
            $calc->commissionForFeature($this->account(7), 210_000, AccountFeature::SendMoney),
        );
    }

    private function account(?int $companyId, bool $isAgent = true): Account
    {
        $account = new Account;
        $account->id = 42;
        $account->company_id = $companyId;
        $account->is_agent = $isAgent;

        return $account;
    }

    /** @param array<string, int|float|string|null> $overrides */
    private function tier(AccountFeature $feature, array $overrides = []): CommissionTier
    {
        $tier = new CommissionTier;
        foreach (array_merge([
            'company_id' => 1,
            'feature' => $feature->value,
            'amount_from' => 1,
            'amount_to' => 999_999_999_999,
            'fee_type' => 'FIXED',
            'fee_amount' => 0,
            'comm_type' => 'FIXED',
            'comm_amount' => 0,
            'additional_fee_type' => 'FIXED',
            'additional_fee_amount' => 0,
            'is_active' => true,
        ], $overrides) as $key => $value) {
            $tier->setAttribute($key, $value);
        }

        return $tier;
    }

    private function tierRepo(?CommissionTier ...$tiers): CommissionTierRepository
    {
        return new class(array_filter($tiers)) extends CommissionTierRepository
        {
            /** @param list<CommissionTier> $tiers */
            public function __construct(private readonly array $tiers) {}

            public function findForCompanyFeature(int $companyId, string $feature, float|string $amount): ?CommissionTier
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
}