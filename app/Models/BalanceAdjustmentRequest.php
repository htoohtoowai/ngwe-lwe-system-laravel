<?php

namespace App\Models;

use App\Models\Concerns\HasBranchScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'branch_id',
    'target_type',
    'account_id',
    'direction',
    'amount',
    'denominations_json',
    'note',
    'status',
    'requested_by',
    'assigned_cashier_id',
    'confirmed_by',
    'confirmed_at',
    'rejected_by',
    'rejected_at',
    'rejection_note',
])]
class BalanceAdjustmentRequest extends Model
{
    use HasBranchScope;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_CONFIRMED = 'CONFIRMED';
    public const STATUS_REJECTED = 'REJECTED';

    public const TARGET_CASH = 'cash';
    public const TARGET_ACCOUNT = 'account';

    public const DIRECTION_DEPOSIT = 'deposit';
    public const DIRECTION_WITHDRAW = 'withdraw';

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'account_id' => 'integer',
            'amount' => 'decimal:2',
            'denominations_json' => 'array',
            'requested_by' => 'integer',
            'assigned_cashier_id' => 'integer',
            'confirmed_by' => 'integer',
            'confirmed_at' => 'datetime',
            'rejected_by' => 'integer',
            'rejected_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function assignedCashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_cashier_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
