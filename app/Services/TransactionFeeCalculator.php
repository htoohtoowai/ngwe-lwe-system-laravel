<?php

namespace App\Services;

use App\Enums\AccountFeature;
use App\Models\Account;
use App\Models\ProviderFeeTier;
use App\Repositories\ProviderFeeTierRepository;
use App\Support\Money;

class TransactionFeeCalculator
{
    public const MODE_CASH_IN = 'cash_in';

    public const MODE_CASH_OUT = 'cash_out';

    public function __construct(
        private readonly ProviderFeeTierRepository $tiers,
        private readonly TierValueCalculator $values,
    ) {}

    /**
     * @return array{customer_fee:string,additional_fee:string}
     */
    public function resolveFees(Account $account, float|string $amount, string $mode): array
    {
        $feature = match ($mode) {
            self::MODE_CASH_IN => AccountFeature::CashIn,
            self::MODE_CASH_OUT => AccountFeature::CashOut,
            default => null,
        };

        if ($feature === null) {
            return $this->zeroFees();
        }

        return $this->resolveForFeature($account, $amount, $feature);
    }

    /**
     * Resolve any provider customer-fee feature row, including Send/Receive Money.
     *
     * @return array{customer_fee:string,additional_fee:string}
     */
    public function resolveForFeature(Account $account, float|string $amount, AccountFeature $feature): array
    {
        $tier = $this->resolveTier($account, $amount, $feature);

        if ($tier === null) {
            return $this->zeroFees();
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

    private function resolveTier(
        Account $account,
        float|string $amount,
        AccountFeature $feature,
    ): ?ProviderFeeTier {
        if ($account->company_id === null) {
            return null;
        }

        return $this->tiers->findForCompanyFeature(
            (int) $account->company_id,
            $feature->value,
            $amount,
        );
    }

    /** @return array{customer_fee:string,additional_fee:string} */
    private function zeroFees(): array
    {
        return [
            'customer_fee' => Money::normalize(0),
            'additional_fee' => Money::normalize(0),
        ];
    }
}
