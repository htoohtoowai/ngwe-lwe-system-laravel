<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BalanceAdjustmentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => (int) $this->branch_id,
            'branch_name' => $this->branch?->name,
            'branch_code' => $this->branch?->code,
            'target_type' => $this->target_type,
            'account_id' => $this->account_id !== null ? (int) $this->account_id : null,
            'account_name' => $this->account?->account_name,
            'account_identifier' => $this->account?->account_identifier,
            'provider_name' => $this->account?->company?->name,
            'account_balance' => $this->account?->balance,
            'account_type' => $this->account?->account_type instanceof \BackedEnum
                ? $this->account?->account_type->value
                : $this->account?->account_type,
            'direction' => $this->direction,
            'amount' => $this->amount,
            'denominations' => $this->denominations_json ?? [],
            'note' => $this->note,
            'status' => $this->status,
            'requested_by' => (int) $this->requested_by,
            'requested_by_name' => $this->requester?->full_name ?? $this->requester?->username,
            'requester_role' => $this->requester?->role,
            'assigned_cashier_id' => (int) $this->assigned_cashier_id,
            'assigned_cashier_name' => $this->assignedCashier?->full_name ?? $this->assignedCashier?->username,
            'approver_id' => $this->approver_id !== null ? (int) $this->approver_id : null,
            'approver_name' => $this->approver?->full_name ?? $this->approver?->username,
            'approver_role' => $this->approver?->role,
            'approved_at' => $this->approved_at?->toISOString(),
            'confirmed_by' => $this->confirmed_by !== null ? (int) $this->confirmed_by : null,
            'confirmed_by_name' => $this->confirmer?->full_name ?? $this->confirmer?->username,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'rejected_by' => $this->rejected_by !== null ? (int) $this->rejected_by : null,
            'rejected_by_name' => $this->rejecter?->full_name ?? $this->rejecter?->username,
            'rejected_at' => $this->rejected_at?->toISOString(),
            'rejection_note' => $this->rejection_note,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
