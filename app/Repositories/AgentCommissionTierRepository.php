<?php

namespace App\Repositories;

use App\Models\AgentCommissionTier;

class AgentCommissionTierRepository
{
    public function findForCompany(int $companyId, float|string $amount): ?AgentCommissionTier
    {
        if ($companyId <= 0) {
            return null;
        }

        $amount = (float) $amount;

        return AgentCommissionTier::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('amount_from', '<=', $amount)
            ->where('amount_to', '>=', $amount)
            ->orderByDesc('amount_from')
            ->orderBy('id')
            ->first();
    }
}
