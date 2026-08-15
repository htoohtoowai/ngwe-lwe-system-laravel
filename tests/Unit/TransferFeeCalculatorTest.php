<?php

namespace Tests\Unit;

use App\Models\TransferFeeTier;
use App\Repositories\TransferFeeTierRepository;
use App\Services\TierValueCalculator;
use App\Services\TransferFeeCalculator;
use PHPUnit\Framework\TestCase;

class TransferFeeCalculatorTest extends TestCase
{
    public function test_fixed_transfer_fee_is_treated_as_mmk(): void
    {
        $calculator = new TransferFeeCalculator($this->repository($this->tier([
            'fee_type' => 'FIXED',
            'fee_value' => 200,
        ])), new TierValueCalculator());

        $this->assertSame('200.00', $calculator->resolve(1, 2, 210_000)['customer_fee']);
    }

    public function test_percentage_transfer_fee_uses_human_percent_and_mmk_rounding(): void
    {
        $calculator = new TransferFeeCalculator($this->repository($this->tier([
            'fee_type' => 'PERCENTAGE',
            'fee_value' => 0.2,
        ])), new TierValueCalculator());

        // 210,000 x 0.2% = 420 raw, then the shared MMK fee rule rounds to 400.
        $this->assertSame('400.00', $calculator->resolve(1, 2, 210_000)['customer_fee']);
    }

    public function test_transfer_fee_supports_four_decimal_percentage_values(): void
    {
        $calculator = new TransferFeeCalculator($this->repository($this->tier([
            'fee_type' => 'PERCENTAGE',
            'fee_value' => 0.0001,
        ])), new TierValueCalculator());

        // Raw fee is 1 MMK, then the customer-fee minimum/rounding rule applies.
        $this->assertSame('100.00', $calculator->resolve(1, 2, 1_000_000)['customer_fee']);
    }

    public function test_additional_fee_can_use_percentage_independently(): void
    {
        $calculator = new TransferFeeCalculator($this->repository($this->tier([
            'fee_type' => 'FIXED',
            'fee_value' => 0,
            'additional_fee_type' => 'PERCENTAGE',
            'additional_fee_value' => 0.0001,
        ])), new TierValueCalculator());

        $result = $calculator->resolve(1, 2, 1_000_000);

        $this->assertSame('100.00', $result['customer_fee']);
        $this->assertSame('1.00', $result['additional_fee']);
    }

    public function test_missing_company_route_has_no_transfer_fee(): void
    {
        $calculator = new TransferFeeCalculator($this->repository(null), new TierValueCalculator());

        $this->assertSame([
            'customer_fee' => '0.00',
            'additional_fee' => '0.00',
        ], $calculator->resolve(1, 2, 50_000));
    }

    /** @param array<string, int|float|string> $overrides */
    private function tier(array $overrides): TransferFeeTier
    {
        $tier = new TransferFeeTier;

        foreach (array_merge([
            'fee_type' => 'FIXED',
            'fee_value' => 0,
            'additional_fee_type' => 'FIXED',
            'additional_fee_value' => 0,
        ], $overrides) as $key => $value) {
            $tier->setAttribute($key, $value);
        }

        return $tier;
    }

    private function repository(?TransferFeeTier $tier): TransferFeeTierRepository
    {
        return new class($tier) extends TransferFeeTierRepository
        {
            public function __construct(private readonly ?TransferFeeTier $tier) {}

            public function findForRoute(int $fromCompanyId, int $toCompanyId, float|string $amount): ?TransferFeeTier
            {
                return $this->tier;
            }
        };
    }
}
