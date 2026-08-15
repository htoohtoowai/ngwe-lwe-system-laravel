<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'logo_path', 'category', 'is_active'])]
class Company extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function providerFeeTiers(): HasMany
    {
        return $this->hasMany(ProviderFeeTier::class);
    }

    public function agentCommissionTiers(): HasMany
    {
        return $this->hasMany(AgentCommissionTier::class);
    }

    public function agentCommissionEntries(): HasMany
    {
        return $this->hasMany(AgentCommissionEntry::class);
    }
}
