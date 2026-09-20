<?php

namespace App\Repositories;

use App\Enums\AccountFeature;
use App\Models\AgentCommissionTier;

class AgentCommissionTierRepository
{
    public function findForCompanyFeature(
        int $companyId,
        AccountFeature|string $feature,
        float|string $amount,
    ): ?AgentCommissionTier {
        if ($companyId <= 0) {
            return null;
        }

        $featureValue = $feature instanceof AccountFeature ? $feature->value : (string) $feature;
        $amount = (float) $amount;

        return AgentCommissionTier::query()
            ->where('company_id', $companyId)
            ->where('feature', $featureValue)
            ->where('is_active', true)
            ->where('amount_from', '<=', $amount)
            ->where('amount_to', '>=', $amount)
            ->orderByDesc('amount_from')
            ->orderBy('id')
            ->first();
    }
}
