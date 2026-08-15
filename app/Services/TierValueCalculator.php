<?php

namespace App\Services;

use App\Enums\CalculationType;

class TierValueCalculator
{
    public function calculate(float|string $baseAmount, float|string $configuredValue, CalculationType|string $type): float
    {
        $calculationType = $type instanceof CalculationType
            ? $type
            : CalculationType::from(strtoupper($type));

        return match ($calculationType) {
            CalculationType::Fixed => (float) $configuredValue,
            CalculationType::Percentage => (float) $baseAmount * ((float) $configuredValue / 100),
        };
    }
}
