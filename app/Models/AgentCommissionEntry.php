<?php

namespace App\Models;

use App\Enums\AgentCommissionDirection;
use App\Enums\CalculationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'transaction_id',
    'account_id',
    'company_id',
    'agent_commission_tier_id',
    'direction',
    'base_amount',
    'calculation_type',
    'configured_value',
    'commission_amount',
    'status',
    'reversed_at',
    'reversed_by',
])]
class AgentCommissionEntry extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'direction' => AgentCommissionDirection::class,
            'base_amount' => 'decimal:2',
            'calculation_type' => CalculationType::class,
            'configured_value' => 'decimal:4',
            'commission_amount' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(AgentCommissionTier::class, 'agent_commission_tier_id');
    }
}
