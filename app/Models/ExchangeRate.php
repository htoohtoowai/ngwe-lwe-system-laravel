<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'base_currency',
    'quote_currency',
    'base_amount',
    'buy_rate',
    'sell_rate',
    'is_active',
])]
class ExchangeRate extends Model
{
    protected function casts(): array
    {
        return [
            'base_amount' => 'decimal:2',
            'buy_rate' => 'decimal:4',
            'sell_rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
