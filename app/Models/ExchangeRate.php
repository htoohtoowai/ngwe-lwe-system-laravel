<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'base_currency',
    'quote_currency',
    'base_amount',
    'buy_rate',
    'sell_rate',
])]
class ExchangeRate extends Model
{
    protected function casts(): array
    {
        return [
            'base_amount' => 'decimal:2',
            'buy_rate' => 'decimal:4',
            'sell_rate' => 'decimal:4',
        ];
    }
}
