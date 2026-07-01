<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function normalize(int|float|string $value, int $places = 2): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Invalid money amount.');
        }

        $amount = (float) $value;

        if (! is_finite($amount)) {
            throw new InvalidArgumentException('Invalid money amount.');
        }

        return number_format(round($amount, $places, PHP_ROUND_HALF_UP), $places, '.', '');
    }

    public static function roundMmkFee(int|float|string $amount): int
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Invalid money amount.');
        }

        $amount = (float) $amount;

        if (! is_finite($amount)) {
            throw new InvalidArgumentException('Invalid money amount.');
        }

        if ($amount <= 0) {
            return 0;
        }

        $base = (int) floor($amount / 100) * 100;
        $remainder = $amount - $base;
        $fee = $remainder <= 20 ? $base : $base + 100;

        return max($fee, 100);
    }

    public static function denominationTotal(array $denominations): int
    {
        $total = 0;

        foreach ($denominations as $denomination => $quantity) {
            $denomination = (int) $denomination;
            $quantity = (int) $quantity;

            if (! in_array($denomination, self::supportedDenominations(), true)) {
                throw new InvalidArgumentException("Invalid denomination: {$denomination}");
            }

            if ($quantity < 0) {
                throw new InvalidArgumentException('Denomination quantity cannot be negative.');
            }

            $total += $denomination * $quantity;
        }

        return $total;
    }

    public static function supportedDenominations(): array
    {
        return [50, 100, 200, 500, 1000, 5000, 10000, 20000];
    }
}
