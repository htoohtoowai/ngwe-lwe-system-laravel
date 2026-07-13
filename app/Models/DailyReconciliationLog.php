<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'recon_date',
    'closed_by',
    'total_cash_in',
    'total_cash_out',
    'total_transfer',
    'total_exchange',
    'total_commission',
    'total_customer_fees',
    'main_vault_total',
    'employee_floats_total',
    'total_cash',
    'total_digital',
    'grand_total',
    'employee_snapshots',
    'account_snapshots',
    'vault_snapshot',
    'notes',
])]
class DailyReconciliationLog extends Model
{
    protected $table = 'daily_reconciliation_logs';

    public const CREATED_AT = 'closed_at';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'recon_date' => 'date',
            'closed_at' => 'datetime',
            'total_cash_in' => 'decimal:2',
            'total_cash_out' => 'decimal:2',
            'total_transfer' => 'decimal:2',
            'total_exchange' => 'decimal:2',
            'total_commission' => 'decimal:2',
            'total_customer_fees' => 'decimal:2',
            'main_vault_total' => 'decimal:2',
            'employee_floats_total' => 'decimal:2',
            'total_cash' => 'decimal:2',
            'total_digital' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'employee_snapshots' => 'array',
            'account_snapshots' => 'array',
            'vault_snapshot' => 'array',
        ];
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
