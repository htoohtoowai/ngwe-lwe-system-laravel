<?php

namespace App\Models;

use App\Enums\CalculationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'amount_from',
    'amount_to',
    'commission_type',
    'out_commission_value',
    'in_commission_value',
    'is_active',
])]
class AgentCommissionTier extends Model
{
    protected function casts(): array
    {
        return [
            'amount_from' => 'decimal:2',
            'amount_to' => 'decimal:2',
            'commission_type' => CalculationType::class,
            'out_commission_value' => 'decimal:4',
            'in_commission_value' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
