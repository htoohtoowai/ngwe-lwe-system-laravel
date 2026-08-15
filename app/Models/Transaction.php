<?php

namespace App\Models;

use App\Enums\AgentCommissionDirection;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function agentCommissionEntries(): HasMany
    {
        return $this->hasMany(AgentCommissionEntry::class);
    }

    public function earnedAgentCommissionTotal(): string
    {
        return Money::normalize($this->earnedAgentCommissionSum());
    }

    public function earnedAgentCommissionForDirection(AgentCommissionDirection $direction): string
    {
        return Money::normalize($this->earnedAgentCommissionSum($direction));
    }

    private function earnedAgentCommissionSum(?AgentCommissionDirection $direction = null): float
    {
        if ($this->relationLoaded('agentCommissionEntries')) {
            return (float) $this->agentCommissionEntries
                ->filter(function (AgentCommissionEntry $entry) use ($direction): bool {
                    if ($entry->status !== 'EARNED') {
                        return false;
                    }

                    return $direction === null || $entry->direction === $direction;
                })
                ->sum(fn (AgentCommissionEntry $entry): float => (float) $entry->commission_amount);
        }

        $query = $this->agentCommissionEntries()->where('status', 'EARNED');

        if ($direction !== null) {
            $query->where('direction', $direction->value);
        }

        return (float) $query->sum('commission_amount');
    }
}
