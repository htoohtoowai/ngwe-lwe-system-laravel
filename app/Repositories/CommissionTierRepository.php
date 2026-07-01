<?php

namespace App\Repositories;

use App\Models\CommissionTier;
use Illuminate\Database\Eloquent\Collection;

class CommissionTierRepository
{
    /**
     * Return the single active commission tier that covers the given amount for a service type.
     * Matches Python get_tier_for_amount: half-open [amount_from, amount_to) range,
     * with catch-all (1 to >= 999_999_999_999) de-prioritized behind specific tiers.
     */
    public function findForAmount(int $serviceTypeId, float|string $amount): ?CommissionTier
    {
        $amount = (float) $amount;

        return CommissionTier::query()
            ->where('service_type_id', $serviceTypeId)
            ->where('is_active', true)
            ->where(function ($query) use ($amount): void {
                $query->whereNull('amount_from')->orWhere('amount_from', '<=', $amount);
            })
            ->where(function ($query) use ($amount): void {
                $query->whereNull('amount_to')->orWhere('amount_to', '>', $amount);
            })
            ->orderByRaw(
                'CASE WHEN amount_from = 1 AND amount_to >= 999999999999 THEN 1 ELSE 0 END ASC, '
                .'amount_from ASC, id ASC'
            )
            ->first();
    }

    /**
     * @return Collection<int, CommissionTier>
     */
    public function forServiceType(int $serviceTypeId): Collection
    {
        return CommissionTier::query()
            ->where('service_type_id', $serviceTypeId)
            ->where('is_active', true)
            ->orderBy('amount_from')
            ->get();
    }
}
