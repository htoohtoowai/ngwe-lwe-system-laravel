<?php

namespace App\Services;

use App\Enums\AccountFeature;
use App\Models\Account;
use App\Models\CommissionTier;
use App\Repositories\CommissionTierRepository;
use App\Support\Money;

class TransactionFeeCalculator
{
    public const MODE_CASH_IN = 'cash_in';

    public const MODE_CASH_OUT = 'cash_out';

    public const COMMISSION_SEND = 'send';

    public const COMMISSION_RECEIVE = 'receive';

    public function __construct(private readonly CommissionTierRepository $tiers) {}

    /**
     * @return array{customer_fee: string, additional_fee: string}
     */
    public function resolveFees(Account $account, float|string $amount, string $mode): array
    {
        $feature = match ($mode) {
            self::MODE_CASH_IN => AccountFeature::CashIn,
            self::MODE_CASH_OUT => AccountFeature::CashOut,
            default => null,
        };
        $tier = $this->resolveTier($account, (float) $amount, $feature);

        if ($tier === null) {
            return [
                'customer_fee' => Money::normalize(0),
                'additional_fee' => Money::normalize(0),
            ];
        }

        $amountValue = (float) $amount;
        $baseFee = $this->applyType($amountValue, (float) $tier->fee_amount, $tier->fee_type);
        $additionalFee = $this->applyType(
            $amountValue,
            (float) $tier->additional_fee_amount,
            $tier->additional_fee_type,
        );

        return [
            'customer_fee' => Money::normalize(Money::roundMmkFee($baseFee + $additionalFee)),
            'additional_fee' => Money::normalize($additionalFee),
        ];
    }

    public function commission(Account $account, float|string $amount, string $direction): string
    {
        $feature = match ($direction) {
            self::COMMISSION_SEND => AccountFeature::CashIn,
            self::COMMISSION_RECEIVE => AccountFeature::CashOut,
            default => null,
        };

        return $feature === null
            ? Money::normalize(0)
            : $this->commissionForFeature($account, $amount, $feature);
    }

    public function commissionForFeature(
        Account $account,
        float|string $amount,
        AccountFeature $feature,
    ): string {
        if (! $account->is_agent) {
            return Money::normalize(0);
        }

        $tier = $this->resolveTier($account, (float) $amount, $feature);
        if ($tier === null) {
            return Money::normalize(0);
        }

        $commission = strtoupper($tier->comm_type) === 'PERCENTAGE'
            ? round((float) $amount * ((float) $tier->comm_amount / 100), 2)
            : (float) $tier->comm_amount;

        return Money::normalize($commission);
    }

    private function applyType(float $amount, float $value, ?string $type): float
    {
        return strtoupper($type ?? 'FIXED') === 'PERCENTAGE'
            ? round($amount * ($value / 100), 2)
            : $value;
    }

    private function resolveTier(
        Account $account,
        float $amount,
        ?AccountFeature $feature,
    ): ?CommissionTier {
        if ($account->company_id === null || $feature === null) {
            return null;
        }

        return $this->tiers->findForCompanyFeature(
            (int) $account->company_id,
            $feature->value,
            $amount,
        );
    }
}
