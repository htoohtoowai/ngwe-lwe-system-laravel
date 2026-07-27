<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'transaction_type',
    'account_id',
    'to_account_id',
    'from_company_id',
    'to_company_id',
    'customer_name',
    'customer_phone',
    'source_account_type',
    'source_provider',
    'source_account_number',
    'destination_provider',
    'destination_customer_name',
    'destination_account_number',
    'amount',
    'commission_amount',
    'receive_commission_amount',
    'payout_commission_amount',
    'customer_fee',
    'additional_fee_amount',
    'balance_change',
    'currency',
    'exchange_rate',
    'fee_account_id',
    'fee_payment_method',
    'screenshot_path',
    'note',
    'created_by',
    'cash_approved_by',
    'cash_approved_at',
    'status',
    'vault_impact',
    'confirmed_by',
    'confirmed_at',
    'change_given',
    'change_denominations',
    'received_denominations',
    'handoff_denominations',
])]
class Transaction extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'receive_commission_amount' => 'decimal:2',
            'payout_commission_amount' => 'decimal:2',
            'customer_fee' => 'decimal:2',
            'additional_fee_amount' => 'decimal:2',
            'balance_change' => 'decimal:2',
            'exchange_rate' => 'decimal:4',
            'fee_payment_method' => 'string',
            'cash_approved_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'change_given' => 'decimal:2',
            'change_denominations' => 'array',
            'received_denominations' => 'array',
            'handoff_denominations' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
