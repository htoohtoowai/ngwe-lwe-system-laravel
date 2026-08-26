<?php

namespace App\Services;

use App\Enums\AccountFeature;
use App\Enums\CalculationType;
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

    /**
     * Resolve an "all-in" customer total into principal + configured fee.
     * This is important around tier boundaries: using the total itself as the
     * tier lookup amount can select the wrong fee row.
     *
     * @return array{principal:string,customer_total:string,customer_fee:string,additional_fee:string}
     */
    public function resolveIncludedTotal(Account $account, float|string $customerTotal, AccountFeature $feature): array
    {
        $total = (float) Money::normalize($customerTotal);
        if ($total <= 0) {
            return [
                'principal' => Money::normalize(0),
                'customer_total' => Money::normalize(0),
                ...$this->zeroFees(),
            ];
        }

        if ($account->company_id === null) {
            return [
                'principal' => Money::normalize($total),
                'customer_total' => Money::normalize($total),
                ...$this->zeroFees(),
            ];
        }

        $tiers = $this->tiers->activeForCompanyFeature((int) $account->company_id, $feature->value);
        if ($tiers->isEmpty()) {
            return [
                'principal' => Money::normalize($total),
                'customer_total' => Money::normalize($total),
                ...$this->zeroFees(),
            ];
        }

        foreach ($tiers as $tier) {
            $fixed = 0.0;
            $percentage = 0.0;

            foreach ([
                [$tier->fee_type, $tier->fee_value],
                [$tier->additional_fee_type, $tier->additional_fee_value],
            ] as [$type, $value]) {
                $calculationType = $type instanceof CalculationType
                    ? $type
                    : CalculationType::from(strtoupper((string) $type));

                if ($calculationType === CalculationType::Fixed) {
                    $fixed += (float) $value;
                } else {
                    $percentage += (float) $value;
                }
            }

            $divisor = 1 + ($percentage / 100);
            if ($divisor <= 0) {
                continue;
            }

            $principal = ($total - $fixed) / $divisor;
            if ($principal <= 0) {
                continue;
            }

            if ($principal + 0.00001 < (float) $tier->amount_from || $principal - 0.00001 > (float) $tier->amount_to) {
                continue;
            }

            $fees = $this->resolveForFeature($account, $principal, $feature);
            $roundedPrincipal = Money::normalize(Money::roundMmkFee($principal));
            $resolvedTotal = Money::normalize((float) $roundedPrincipal + (float) $fees['customer_fee']);

            // MMK customer totals are whole-note values. If rounding the principal
            // moves us by one kyat, adjust it so principal + fee equals the input.
            $difference = Money::roundMmkFee($total - (float) $resolvedTotal);
            if ($difference !== 0.0) {
                $adjustedPrincipal = Money::normalize((float) $roundedPrincipal + $difference);
                $adjustedFees = $this->resolveForFeature($account, $adjustedPrincipal, $feature);
                $adjustedTotal = Money::normalize((float) $adjustedPrincipal + (float) $adjustedFees['customer_fee']);
                if ((float) $adjustedPrincipal > 0 && abs((float) $adjustedTotal - $total) < 0.01) {
                    return [
                        'principal' => $adjustedPrincipal,
                        'customer_total' => Money::normalize($total),
                        ...$adjustedFees,
                    ];
                }
            }

            if (abs((float) $resolvedTotal - $total) < 0.01) {
                return [
                    'principal' => $roundedPrincipal,
                    'customer_total' => Money::normalize($total),
                    ...$fees,
                ];
            }
        }

        throw new \InvalidArgumentException('The all-in amount cannot be matched to the configured fee tiers.');
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
