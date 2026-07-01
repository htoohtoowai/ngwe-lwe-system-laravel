<?php

namespace App\Services;

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
 *  - FIXED vs PERCENTAGE resolution via _calc_amount_by_type
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
        $tier = $this->tiers->findForAmount((int) $account->service_type_id, $amountFloat);

        if ($tier === null) {
            return [
                'customer_fee' => Money::normalize(0),
                'additional_fee' => Money::normalize(0),
            ];
        }

        [$baseRaw, $additionalRaw] = $this->rawFeeInputs($tier, $mode);

        $baseFee = $this->applyType($amountFloat, $baseRaw, $tier->fee_amount_type);
        $additionalFee = $this->applyType($amountFloat, $additionalRaw, $tier->additional_fee_type);

        $totalFee = (float) Money::roundMmkFee($baseFee + $additionalFee);

        return [
            'customer_fee' => Money::normalize($totalFee),
            'additional_fee' => Money::normalize($additionalFee),
        ];
    }

    public function commission(Account $account, float|string $amount, string $direction): string
    {
        $amountFloat = (float) $amount;
        $tier = $this->tiers->findForAmount((int) $account->service_type_id, $amountFloat);

        if ($tier === null) {
            return Money::normalize(0);
        }

        $raw = (float) ($direction === self::COMMISSION_SEND
            ? ($tier->comm_deposit ?? 0)
            : ($tier->comm_withdraw ?? 0));

        $value = $tier->comm_type === 'PERCENTAGE'
            ? round($amountFloat * $raw, 2)
            : $raw;

        return Money::normalize($value);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function rawFeeInputs(CommissionTier $tier, string $mode): array
    {
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
            ? round($amount * $value, 2)
            : $value;
    }
}
