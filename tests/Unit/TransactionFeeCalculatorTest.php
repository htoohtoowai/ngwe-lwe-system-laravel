<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\CommissionTier;
use App\Repositories\CommissionTierRepository;
use App\Services\TransactionFeeCalculator;
use PHPUnit\Framework\TestCase;

class TransactionFeeCalculatorTest extends TestCase
{
    public function test_returns_zero_fees_when_no_tier_matches(): void
    {
        $calc = new TransactionFeeCalculator($this->tierRepo(null));
        $account = $this->account(1);

        $result = $calc->resolveFees($account, 5000, TransactionFeeCalculator::MODE_CASH_IN);

        $this->assertSame('0.00', $result['customer_fee']);
        $this->assertSame('0.00', $result['additional_fee']);
        $this->assertSame('0.00', $calc->commission($account, 5000, TransactionFeeCalculator::COMMISSION_SEND));
    }

    public function test_fixed_fee_uses_tier_amount_and_mmk_rounding(): void
    {
        $tier = $this->tier([
            'fee_amount_type' => 'FIXED',
            'fee_amount_deposit' => 1020,
            'additional_fee_type' => 'FIXED',
            'additional_fee_deposit_amount' => 0,
        ]);
        $calc = new TransactionFeeCalculator($this->tierRepo($tier));

        // 1020 rounds DOWN to 1000 under the MMK <=20 remainder rule.
        $result = $calc->resolveFees($this->account(1), 100_000, TransactionFeeCalculator::MODE_CASH_IN);

        $this->assertSame('1000.00', $result['customer_fee']);
        $this->assertSame('0.00', $result['additional_fee']);
    }

    public function test_percentage_fee_computes_from_amount_and_rounds_mmk(): void
    {
        $tier = $this->tier([
            'fee_amount_type' => 'PERCENTAGE',
            'fee_amount_deposit' => 0.01,
            'additional_fee_type' => 'FIXED',
            'additional_fee_deposit_amount' => 250,
        ]);
        $calc = new TransactionFeeCalculator($this->tierRepo($tier));

        // 1% of 100_000 = 1000 base + 250 additional = 1250; MMK rounds up (rem 50 > 20) => 1300
        $result = $calc->resolveFees($this->account(1), 100_000, TransactionFeeCalculator::MODE_CASH_IN);

        $this->assertSame('1300.00', $result['customer_fee']);
        $this->assertSame('250.00', $result['additional_fee']);
    }

    public function test_cash_out_uses_withdraw_columns(): void
    {
        $tier = $this->tier([
            'fee_amount_type' => 'FIXED',
            'fee_amount_deposit' => 0,
            'fee_amount_withdraw' => 500,
            'additional_fee_type' => 'FIXED',
            'additional_fee_deposit_amount' => 0,
            'additional_fee_withdraw_amount' => 0,
        ]);
        $calc = new TransactionFeeCalculator($this->tierRepo($tier));

        $result = $calc->resolveFees($this->account(1), 50_000, TransactionFeeCalculator::MODE_CASH_OUT);

        $this->assertSame('500.00', $result['customer_fee']);
    }

    public function test_commission_fixed_uses_raw_value(): void
    {
        $tier = $this->tier([
            'comm_type' => 'FIXED',
            'comm_deposit' => 750,
            'comm_withdraw' => 900,
        ]);
        $calc = new TransactionFeeCalculator($this->tierRepo($tier));
        $account = $this->account(1);

        $this->assertSame('750.00', $calc->commission($account, 50_000, TransactionFeeCalculator::COMMISSION_SEND));
        $this->assertSame('900.00', $calc->commission($account, 50_000, TransactionFeeCalculator::COMMISSION_RECEIVE));
    }

    public function test_commission_percentage_scales_with_amount(): void
    {
        $tier = $this->tier([
            'comm_type' => 'PERCENTAGE',
            'comm_deposit' => 0.005,
            'comm_withdraw' => 0.01,
        ]);
        $calc = new TransactionFeeCalculator($this->tierRepo($tier));
        $account = $this->account(1);

        $this->assertSame('500.00', $calc->commission($account, 100_000, TransactionFeeCalculator::COMMISSION_SEND));
        $this->assertSame('1000.00', $calc->commission($account, 100_000, TransactionFeeCalculator::COMMISSION_RECEIVE));
    }

    private function account(int $serviceTypeId): Account
    {
        $account = new Account;
        $account->id = 42;
        $account->service_type_id = $serviceTypeId;

        return $account;
    }

    /**
     * @param  array<string, int|float|string|null>  $overrides
     */
    private function tier(array $overrides): CommissionTier
    {
        $defaults = [
            'service_type_id' => 1,
            'amount_from' => 1,
            'amount_to' => 999_999_999_999,
            'fee_amount_type' => 'FIXED',
            'fee_amount_deposit' => 0,
            'fee_amount_withdraw' => 0,
            'comm_type' => 'FIXED',
            'comm_deposit' => 0,
            'comm_withdraw' => 0,
            'additional_fee_type' => 'FIXED',
            'additional_fee_deposit_amount' => 0,
            'additional_fee_withdraw_amount' => 0,
            'is_active' => true,
        ];

        $tier = new CommissionTier;
        foreach (array_merge($defaults, $overrides) as $key => $value) {
            $tier->setAttribute($key, $value);
        }

        return $tier;
    }

    private function tierRepo(?CommissionTier $tier): CommissionTierRepository
    {
        return new class($tier) extends CommissionTierRepository
        {
            public function __construct(private readonly ?CommissionTier $tier) {}

            public function findForAmount(int $serviceTypeId, float|string $amount): ?CommissionTier
            {
                return $this->tier;
            }
        };
    }
}
