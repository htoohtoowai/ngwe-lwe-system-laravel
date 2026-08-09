<?php

namespace App\Repositories;

use App\Models\TransferFeeTier;

class TransferFeeTierRepository
{
    public function findForRoute(int $fromCompanyId, int $toCompanyId, float|string $amount): ?TransferFeeTier
    {
        if ($fromCompanyId <= 0 || $toCompanyId <= 0) {
            return null;
        }

        $amount = (float) $amount;

        return TransferFeeTier::query()
            ->where('company_from_id', $fromCompanyId)
            ->where('company_to_id', $toCompanyId)
            ->where('is_active', true)
            ->where('amount_from', '<=', $amount)
            ->where('amount_to', '>=', $amount)
            ->orderByRaw(
                'CASE WHEN amount_from = 1 AND amount_to >= 999999999 THEN 1 ELSE 0 END ASC, '
                .'amount_from ASC, id ASC'
            )
            ->first();
    }
}
