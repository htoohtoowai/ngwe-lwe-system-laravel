<?php

namespace App\Enums;

enum AccountType: string
{
    case Pay = 'PAY';
    case Bank = 'BANK';

    public function label(): string
    {
        return match ($this) {
            self::Pay => 'Pay',
            self::Bank => 'Bank',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
