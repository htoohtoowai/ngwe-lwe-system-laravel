<?php

namespace App\Repositories;

use App\Models\ProviderFeeTier;

class ProviderFeeTierRepository
{
    public function findForCompanyFeature(int $companyId, string $feature, float|string $amount): ?ProviderFeeTier
    {
        if ($companyId <= 0 || $feature === '') {
            return null;
        }

        $amount = (float) $amount;

        return ProviderFeeTier::query()
            ->where('company_id', $companyId)
            ->where('feature', $feature)
            ->where('is_active', true)
            ->where('amount_from', '<=', $amount)
            ->where('amount_to', '>=', $amount)
            ->orderByDesc('amount_from')
            ->orderBy('id')
            ->first();
    }
}
