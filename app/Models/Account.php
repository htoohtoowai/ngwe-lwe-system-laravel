<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['service_type_id', 'account_name', 'phone_number', 'balance', 'commission_rate', 'is_active', 'is_fee_account'])]
class Account extends Model
{
    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'commission_rate' => 'decimal:4',
            'is_active' => 'boolean',
            'is_fee_account' => 'boolean',
        ];
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
