<?php

namespace App\Enums;

enum AccountFeature: string
{
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';
    case SendMoney = 'send_money';
    case ReceiveMoney = 'receive_money';
    case Transfer = 'transfer';
    case Exchange = 'exchange';

    public function label(): string
    {
        return match ($this) {
            self::CashIn => 'Cash In',
            self::CashOut => 'Cash Out',
            self::SendMoney => 'Send Money',
            self::ReceiveMoney => 'Receive Money',
            self::Transfer => 'Transfer',
            self::Exchange => 'Exchange',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $feature) => $feature->value, self::cases());
    }

    public static function labels(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $labels, self $feature) => $labels + [$feature->value => $feature->label()],
            []
        );
    }
}
