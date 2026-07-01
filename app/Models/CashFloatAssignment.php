<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['employee_id', 'issued_by', 'status', 'total_amount', 'current_balance', 'return_denominations_json', 'received_at', 'closed_at', 'closing_total', 'note'])]
class CashFloatAssignment extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'return_denominations_json' => 'array',
            'received_at' => 'datetime',
            'closed_at' => 'datetime',
            'closing_total' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function denominations(): HasMany
    {
        return $this->hasMany(CashFloatDenomination::class, 'float_id')->orderBy('denomination');
    }
}
