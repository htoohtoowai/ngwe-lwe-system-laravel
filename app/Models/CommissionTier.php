<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'feature',
    'amount_from',
    'amount_to',
    'fee_type',
    'fee_amount',
    'comm_type',
    'comm_amount',
    'additional_fee_type',
    'additional_fee_amount',
    'is_active',
])]
class CommissionTier extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount_from' => 'decimal:2',
            'amount_to' => 'decimal:2',
            'fee_amount' => 'decimal:4',
            'comm_amount' => 'decimal:4',
            'additional_fee_amount' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
