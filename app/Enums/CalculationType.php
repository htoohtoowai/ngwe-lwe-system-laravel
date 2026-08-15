<?php

namespace App\Enums;

enum CalculationType: string
{
    case Fixed = 'FIXED';
    case Percentage = 'PERCENTAGE';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed',
            self::Percentage => 'Percentage',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
