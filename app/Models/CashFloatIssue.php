<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['float_id', 'employee_id', 'issued_by', 'issue_type', 'status', 'amount', 'denominations_json', 'note', 'received_at', 'rejected_at'])]
class CashFloatIssue extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'denominations_json' => 'array',
            'received_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function float(): BelongsTo
    {
        return $this->belongsTo(CashFloatAssignment::class, 'float_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
