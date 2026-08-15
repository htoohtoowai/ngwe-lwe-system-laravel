<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentCommissionTierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'amount_from' => $this->amount_from,
            'amount_to' => $this->amount_to,
            'commission_type' => $this->commission_type->value,
            'out_commission_value' => $this->out_commission_value,
            'in_commission_value' => $this->in_commission_value,
            'is_active' => $this->is_active,
            'company' => new CompanyResource($this->whenLoaded('company')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
