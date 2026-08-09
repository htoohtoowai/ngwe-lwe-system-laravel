<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_from_id',
    'company_to_id',
    'amount_from',
    'amount_to',
    'fee_type',
    'fee_amount',
    'additional_fee_type',
    'additional_fee_amount',
    'is_active',
])]
class TransferFeeTier extends Model
{
    protected function casts(): array
    {
        return [
            'amount_from' => 'decimal:2',
            'amount_to' => 'decimal:2',
            'fee_amount' => 'decimal:4',
            'additional_fee_amount' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function fromCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_from_id');
    }

    public function toCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_to_id');
    }
}
