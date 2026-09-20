<?php

namespace App\Models;

use App\Models\Concerns\HasBranchScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'branch_id',
    'summary_date',
    'total_cash_in',
    'total_cash_out',
    'total_send_money',
    'total_receive_money',
    'total_transfer',
    'total_exchange',
    'total_commission',
    'total_customer_fees',
    'total_profit',
    'transaction_count',
])]
class DailySummary extends Model
{
    use HasBranchScope;

    protected $table = 'daily_summary';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'summary_date' => 'date',
            'total_cash_in' => 'decimal:2',
            'total_cash_out' => 'decimal:2',
            'total_send_money' => 'decimal:2',
            'total_receive_money' => 'decimal:2',
            'total_transfer' => 'decimal:2',
            'total_exchange' => 'decimal:2',
            'total_commission' => 'decimal:2',
            'total_customer_fees' => 'decimal:2',
            'total_profit' => 'decimal:2',
            'transaction_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
