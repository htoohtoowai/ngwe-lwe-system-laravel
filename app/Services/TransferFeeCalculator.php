<?php

namespace App\Services;

use App\Repositories\TransferFeeTierRepository;
use App\Support\Money;

class TransferFeeCalculator
{
    public function __construct(
        private readonly TransferFeeTierRepository $tiers,
        private readonly TierValueCalculator $values,
    ) {}

    /**
     * @return array{customer_fee:string,additional_fee:string}
     */
    public function resolve(int $fromCompanyId, int $toCompanyId, float|string $amount): array
    {
        $tier = $this->tiers->findForRoute($fromCompanyId, $toCompanyId, (float) $amount);

        if ($tier === null) {
            return [
                'customer_fee' => Money::normalize(0),
                'additional_fee' => Money::normalize(0),
            ];
        }

        $baseFee = $this->values->calculate($amount, $tier->fee_value, $tier->fee_type);
        $additionalFee = $this->values->calculate(
            $amount,
            $tier->additional_fee_value,
            $tier->additional_fee_type,
        );

        return [
            'customer_fee' => Money::normalize(Money::roundMmkFee($baseFee + $additionalFee)),
            'additional_fee' => Money::normalize($additionalFee),
        ];
    }
}
