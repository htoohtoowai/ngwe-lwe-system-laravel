<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'txn_type',
    'float_id',
    'denomination',
    'quantity',
    'transaction_id',
    'performed_by',
    'verified_by',
    'note',
])]
class VaultTransaction extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'denomination' => 'integer',
            'quantity' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function float(): BelongsTo
    {
        return $this->belongsTo(CashFloatAssignment::class, 'float_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
