<?php

namespace App\Models;

use App\Enums\AccountFeature;
use App\Enums\AccountType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'account_name', 'account_type', 'account_identifier', 'balance', 'is_active', 'is_fee_account', 'is_agent'])]
class Account extends Model
{
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'balance' => 'decimal:2',
            'is_active' => 'boolean',
            'is_fee_account' => 'boolean',
            'is_agent' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function featureAssignments(): HasMany
    {
        return $this->hasMany(AccountFeatureAssignment::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function agentCommissionEntries(): HasMany
    {
        return $this->hasMany(AgentCommissionEntry::class);
    }

    public function supportsFeature(AccountFeature $feature): bool
    {
        if ($this->relationLoaded('featureAssignments')) {
            return $this->featureAssignments->contains(
                fn (AccountFeatureAssignment $assignment): bool => $assignment->feature === $feature,
            );
        }

        return $this->featureAssignments()
            ->where('feature', $feature->value)
            ->exists();
    }
}
