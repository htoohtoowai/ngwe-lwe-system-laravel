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

    /**
     * Bridge old service_types rows into the new enum-key feature model.
     *
     * @return list<self>
     */
    public static function fromLegacy(?string $operation, ?string $name = null): array
    {
        $normalizedName = strtolower(str_replace([' ', '-', '_'], '', $name ?? ''));

        if ($normalizedName === 'wst') {
            return [self::SendMoney];
        }

        if (in_array($normalizedName, ['p2p', 'paytopay'], true)) {
            return [self::ReceiveMoney];
        }

        return match ($operation) {
            'CashIn' => [self::CashIn],
            'CashOut' => [self::CashOut],
            'Transfer' => [self::Transfer],
            'Exchange' => [self::Exchange],
            'All' => self::cases(),
            default => [],
        };
    }
}
