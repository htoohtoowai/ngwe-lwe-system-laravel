<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_type' => $this->transaction_type,
            'account_id' => $this->account_id,
            'to_account_id' => $this->to_account_id,
            'from_company_id' => $this->from_company_id,
            'to_company_id' => $this->to_company_id,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'amount' => $this->amount,
            'commission_amount' => $this->commission_amount,
            'customer_fee' => $this->customer_fee,
            'additional_fee_amount' => $this->additional_fee_amount,
            'balance_change' => $this->balance_change,
            'change_given' => $this->change_given,
            'change_denominations' => is_array($this->change_denominations)
                ? (object) $this->change_denominations
                : $this->change_denominations,
            'received_denominations' => is_array($this->received_denominations)
                ? (object) $this->received_denominations
                : $this->received_denominations,
            'handoff_denominations' => is_array($this->handoff_denominations)
                ? (object) $this->handoff_denominations
                : $this->handoff_denominations,
            'currency' => $this->currency,
            'exchange_rate' => $this->exchange_rate,
            'fee_account_id' => $this->fee_account_id,
            'fee_payment_method' => $this->fee_payment_method,
            'screenshot_path' => $this->screenshot_path,
            'note' => $this->note,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'cash_approved_by' => $this->cash_approved_by,
            'cash_approved_at' => $this->cash_approved_at?->toISOString(),
            'status' => $this->status,
            'vault_impact' => $this->vault_impact,
            'confirmed_by' => $this->confirmed_by,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
        ];
    }
}
