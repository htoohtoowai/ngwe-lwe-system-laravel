<?php

namespace App\Repositories;

use App\Models\CommissionTier;
use Illuminate\Database\Eloquent\Collection;

class CommissionTierRepository
{
    public function findForCompanyFeature(int $companyId, string $feature, float|string $amount): ?CommissionTier
    {
        if ($companyId <= 0 || $feature === '') {
            return null;
        }

        $amount = (float) $amount;

        return CommissionTier::query()
            ->where('company_id', $companyId)
            ->where('feature', $feature)
            ->where('is_active', true)
            ->where(function ($query) use ($amount): void {
                $query->whereNull('amount_from')->orWhere('amount_from', '<=', $amount);
            })
            ->where(function ($query) use ($amount): void {
                $query->whereNull('amount_to')->orWhere('amount_to', '>=', $amount);
            })
            ->orderByRaw(
                'CASE WHEN amount_from = 1 AND amount_to >= 999999999999 THEN 1 ELSE 0 END ASC, '
                .'amount_from ASC, id ASC'
            )
            ->first();
    }

}