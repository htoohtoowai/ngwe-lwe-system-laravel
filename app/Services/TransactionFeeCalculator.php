<?php

namespace App\Services;

use App\Enums\AccountFeature;
use App\Models\Account;
use App\Models\CommissionTier;
use App\Repositories\CommissionTierRepository;
use App\Support\Money;

/**
 * Ports the fee / commission math from
 * repositories/transaction_operation_base.py in the Python source.
 *
 * The Laravel side mirrors the Python semantics:
 *  - lookup tier by (service_type_id, amount)
 *  - FIXED values are MMK amounts; PERCENTAGE values are human percentages
 *    (1 = 1%, 0.2 = 0.2%)
 *  - customer_fee = round_fee(base_fee + additional_fee); additional returned raw
 *  - commission is rounded to 2dp when PERCENTAGE, kept as-is when FIXED
 */
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
        $amountFloat = (float) $amount;
        $tier = $this->resolveTier($account, $amountFloat, $this->featureForMode($mode));

        if ($tier === null) {
            return [
                'customer_fee' => Money::normalize(0),
                'additional_fee' => Money::normalize(0),
            ];
        }

        [$baseRaw, $additionalRaw] = $this->rawFeeInputs($tier, $mode);

        $baseFee = $this->applyType($amountFloat, $baseRaw, $tier->fee_type ?? $tier->fee_amount_type);
        $additionalFee = $this->applyType($amountFloat, $additionalRaw, $tier->additional_fee_type);

        $totalFee = (float) Money::roundMmkFee($baseFee + $additionalFee);

        return [
            'customer_fee' => Money::normalize($totalFee),
            'additional_fee' => Money::normalize($additionalFee),
        ];
    }

    public function commission(Account $account, float|string $amount, string $direction): string
    {
        return $this->commissionForFeature($account, $amount, $this->featureForCommissionDirection($direction), $direction);
    }

    public function commissionForFeature(Account $account, float|string $amount, AccountFeature $feature, string $legacyDirection): string
    {
        if (! (bool) $account->is_agent) {
            return Money::normalize(0);
        }

        $amountFloat = (float) $amount;
        $tier = $this->resolveTier($account, $amountFloat, $feature);

        if ($tier === null) {
            return Money::normalize(0);
        }

        $raw = $this->rawCommissionInput($tier, $legacyDirection);

        $value = $tier->comm_type === 'PERCENTAGE'
            ? round($amountFloat * ($raw / 100), 2)
            : $raw;

        return Money::normalize($value);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function rawFeeInputs(CommissionTier $tier, string $mode): array
    {
        if ($this->isFeatureTier($tier)) {
            return [
                (float) ($tier->fee_amount ?? 0),
                (float) ($tier->additional_fee_amount ?? 0),
            ];
        }

        if ($mode === self::MODE_CASH_OUT) {
            return [
                (float) ($tier->fee_amount_withdraw ?? 0),
                (float) ($tier->additional_fee_withdraw_amount ?? 0),
            ];
        }

        return [
            (float) ($tier->fee_amount_deposit ?? 0),
            (float) ($tier->additional_fee_deposit_amount ?? 0),
        ];
    }

    private function applyType(float $amount, float $value, ?string $type): float
    {
        return strtoupper($type ?? 'FIXED') === 'PERCENTAGE'
            ? round($amount * ($value / 100), 2)
            : $value;
    }

    private function resolveTier(Account $account, float $amount, ?AccountFeature $feature): ?CommissionTier
    {
        $companyId = $account->company_id;

        if ($companyId === null && $account->relationLoaded('serviceType')) {
            $companyId = $account->serviceType?->company_id;
        }

        if ($companyId !== null && $feature !== null) {
            $tier = $this->tiers->findForCompanyFeature((int) $companyId, $feature->value, $amount);

            if ($tier !== null) {
                return $tier;
            }
        }

        return $this->tiers->findForAmount((int) $account->service_type_id, $amount);
    }

    private function featureForMode(string $mode): ?AccountFeature
    {
        return match ($mode) {
            self::MODE_CASH_IN => AccountFeature::CashIn,
            self::MODE_CASH_OUT => AccountFeature::CashOut,
            default => null,
        };
    }

    private function featureForCommissionDirection(string $direction): ?AccountFeature
    {
        return match ($direction) {
            self::COMMISSION_SEND => AccountFeature::CashIn,
            self::COMMISSION_RECEIVE => AccountFeature::CashOut,
            default => null,
        };
    }

    private function rawCommissionInput(CommissionTier $tier, string $direction): float
    {
        if ($this->isFeatureTier($tier)) {
            return (float) ($tier->comm_amount ?? 0);
        }

        return (float) ($direction === self::COMMISSION_SEND
            ? ($tier->comm_deposit ?? 0)
            : ($tier->comm_withdraw ?? 0));
    }

    private function isFeatureTier(CommissionTier $tier): bool
    {
        return $tier->feature !== null;
    }
}
