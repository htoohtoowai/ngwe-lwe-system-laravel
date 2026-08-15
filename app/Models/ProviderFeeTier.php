<?php

namespace App\Models;

use App\Enums\CalculationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'feature',
    'amount_from',
    'amount_to',
    'fee_type',
    'fee_value',
    'additional_fee_type',
    'additional_fee_value',
    'is_active',
])]
class ProviderFeeTier extends Model
{
    protected function casts(): array
    {
        return [
            'amount_from' => 'decimal:2',
            'amount_to' => 'decimal:2',
            'fee_type' => CalculationType::class,
            'fee_value' => 'decimal:4',
            'additional_fee_type' => CalculationType::class,
            'additional_fee_value' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
