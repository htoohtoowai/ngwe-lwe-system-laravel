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
            'amount_from' => $this->amount_from,
            'amount_to' => $this->amount_to,
            'fee_type' => $this->fee_type,
            'fee_amount' => $this->fee_amount,
            'comm_type' => $this->comm_type,
            'comm_amount' => $this->comm_amount,
            'additional_fee_type' => $this->additional_fee_type,
            'additional_fee_amount' => $this->additional_fee_amount,
            'is_active' => $this->is_active,
            'company' => new CompanyResource($this->whenLoaded('company')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
