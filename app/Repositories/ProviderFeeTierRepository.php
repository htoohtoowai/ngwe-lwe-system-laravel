<?php

namespace App\Repositories;

use App\Models\ProviderFeeTier;
use Illuminate\Database\Eloquent\Collection;

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
    /** @return Collection<int, ProviderFeeTier> */
    public function activeForCompanyFeature(int $companyId, string $feature): Collection
    {
        if ($companyId <= 0 || $feature === '') {
            return new Collection();
        }

        return ProviderFeeTier::query()
            ->where('company_id', $companyId)
            ->where('feature', $feature)
            ->where('is_active', true)
            ->orderBy('amount_from')
            ->orderBy('id')
            ->get();
    }

}
