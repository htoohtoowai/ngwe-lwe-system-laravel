<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['float_id', 'denomination', 'quantity'])]
class CashFloatDenomination extends Model
{
    public $timestamps = false;

    protected $table = 'cash_float_denominations';

    protected function casts(): array
    {
        return [
            'denomination' => 'integer',
            'quantity' => 'integer',
        ];
    }

    public function float(): BelongsTo
    {
        return $this->belongsTo(CashFloatAssignment::class, 'float_id');
    }
}
