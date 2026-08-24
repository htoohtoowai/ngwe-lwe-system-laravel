<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'batch_id',
    'entry_type',
    'movement_type',
    'source_type',
    'source_id',
    'destination_type',
    'destination_id',
    'affects_main_vault',
    'denomination',
    'quantity',
    'float_id',
    'transaction_id',
    'created_by',
    'note',
])]
class CashDenominationLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cash_denomination_logs';

    protected function casts(): array
    {
        return [
            'denomination' => 'integer',
            'quantity' => 'integer',
            'source_id' => 'integer',
            'destination_id' => 'integer',
            'affects_main_vault' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function float(): BelongsTo
    {
        return $this->belongsTo(CashFloatAssignment::class, 'float_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
