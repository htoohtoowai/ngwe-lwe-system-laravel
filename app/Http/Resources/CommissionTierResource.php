<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionTierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'feature' => $this->feature,
            'service_type_id' => $this->service_type_id,
            'amount_from' => $this->amount_from,
            'amount_to' => $this->amount_to,
            'fee_type' => $this->fee_type,
            'fee_amount' => $this->fee_amount,
            'fee_amount_type' => $this->fee_amount_type,
            'fee_amount_deposit' => $this->fee_amount_deposit,
            'fee_amount_withdraw' => $this->fee_amount_withdraw,
            'fee_amount_cash_in' => $this->fee_amount_deposit,
            'fee_amount_cash_out' => $this->fee_amount_withdraw,
            'comm_type' => $this->comm_type,
            'comm_amount' => $this->comm_amount,
            'comm_deposit' => $this->comm_deposit,
            'comm_withdraw' => $this->comm_withdraw,
            'comm_cash_in' => $this->comm_deposit,
            'comm_cash_out' => $this->comm_withdraw,
            'additional_fee_type' => $this->additional_fee_type,
            'additional_fee_amount' => $this->additional_fee_amount,
            'additional_fee_deposit_amount' => $this->additional_fee_deposit_amount,
            'additional_fee_withdraw_amount' => $this->additional_fee_withdraw_amount,
            'additional_fee_cash_in_amount' => $this->additional_fee_deposit_amount,
            'additional_fee_cash_out_amount' => $this->additional_fee_withdraw_amount,
            'is_active' => $this->is_active,
            'company' => new CompanyResource($this->whenLoaded('company')),
            'service_type' => new ServiceTypeResource($this->whenLoaded('serviceType')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
