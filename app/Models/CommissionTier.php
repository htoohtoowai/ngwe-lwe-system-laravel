<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'service_type_id',
    'amount_from',
    'amount_to',
    'fee_amount_type',
    'fee_amount_deposit',
    'fee_amount_withdraw',
    'comm_type',
    'comm_deposit',
    'comm_withdraw',
    'additional_fee_type',
    'additional_fee_deposit_amount',
    'additional_fee_withdraw_amount',
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
            'fee_amount_deposit' => 'decimal:4',
            'fee_amount_withdraw' => 'decimal:4',
            'comm_deposit' => 'decimal:4',
            'comm_withdraw' => 'decimal:4',
            'additional_fee_deposit_amount' => 'decimal:4',
            'additional_fee_withdraw_amount' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }
}
