<?php

namespace App\Services;

use App\Repositories\TransferFeeTierRepository;
use App\Support\Money;

class TransferFeeCalculator
{
    public function __construct(private readonly TransferFeeTierRepository $tiers) {}

    /**
     * @return array{customer_fee:string,additional_fee:string}
     */
    public function resolve(int $fromCompanyId, int $toCompanyId, float|string $amount): array
    {
        $amountFloat = (float) $amount;
        $tier = $this->tiers->findForRoute($fromCompanyId, $toCompanyId, $amountFloat);

        if ($tier === null) {
            return [
                'customer_fee' => Money::normalize(0),
                'additional_fee' => Money::normalize(0),
            ];
        }

        $baseFee = $this->applyType($amountFloat, (float) $tier->fee_amount, $tier->fee_type);
        $additionalFee = $this->applyType(
            $amountFloat,
            (float) $tier->additional_fee_amount,
            $tier->additional_fee_type,
        );

        return [
            'customer_fee' => Money::normalize(Money::roundMmkFee($baseFee + $additionalFee)),
            'additional_fee' => Money::normalize($additionalFee),
        ];
    }

    private function applyType(float $amount, float $value, ?string $type): float
    {
        return strtoupper($type ?? 'FIXED') === 'PERCENTAGE'
            ? round($amount * ($value / 100), 2)
            : $value;
    }
}
