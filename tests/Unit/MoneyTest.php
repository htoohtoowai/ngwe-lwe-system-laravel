<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_money_normalize_matches_python_half_up_boundaries(): void
    {
        $this->assertSame('100.01', Money::normalize('100.005'));
        $this->assertSame('100.00', Money::normalize('100.004'));
        $this->assertSame('128.2100', Money::normalize('128.21004', 4));
        $this->assertSame('128.2101', Money::normalize('128.21005', 4));
    }

    public function test_mmk_fee_rounding_matches_python_rule(): void
    {
        $this->assertSame(100, Money::roundMmkFee(120.0));
        $this->assertSame(1000, Money::roundMmkFee(1020.0));
        $this->assertSame(100, Money::roundMmkFee(20.0));
        $this->assertSame(0, Money::roundMmkFee(0.0));
        $this->assertSame(200, Money::roundMmkFee(120.1));
        $this->assertSame(200, Money::roundMmkFee(199.99));
        $this->assertSame(1100, Money::roundMmkFee(1020.1));
        $this->assertSame(100, Money::roundMmkFee(21.0));
    }

    public function test_denomination_total_accepts_supported_notes(): void
    {
        $this->assertSame(35000, Money::denominationTotal([
            20000 => 1,
            10000 => 1,
            5000 => 1,
        ]));
    }

    public function test_denomination_total_rejects_invalid_notes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::denominationTotal([300 => 1]);
    }

    public function test_denomination_total_rejects_negative_quantities(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::denominationTotal([1000 => -1]);
    }
}
