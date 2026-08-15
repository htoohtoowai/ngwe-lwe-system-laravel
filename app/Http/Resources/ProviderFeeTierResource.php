<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderFeeTierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'feature' => $this->feature,
            'amount_from' => $this->amount_from,
            'amount_to' => $this->amount_to,
            'fee_type' => $this->fee_type->value,
            'fee_value' => $this->fee_value,
            'additional_fee_type' => $this->additional_fee_type->value,
            'additional_fee_value' => $this->additional_fee_value,
            'is_active' => $this->is_active,
            'company' => new CompanyResource($this->whenLoaded('company')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
