<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentCommissionEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'account_id' => $this->account_id,
            'account_name' => $this->whenLoaded('account', fn () => $this->account?->account_name),
            'company_id' => $this->company_id,
            'company_name' => $this->whenLoaded('company', fn () => $this->company?->name),
            'agent_commission_tier_id' => $this->agent_commission_tier_id,
            'direction' => $this->direction instanceof \BackedEnum ? $this->direction->value : $this->direction,
            'base_amount' => $this->base_amount,
            'calculation_type' => $this->calculation_type->value,
            'configured_value' => $this->configured_value,
            'commission_amount' => $this->commission_amount,
            'status' => $this->status,
            'reversed_at' => $this->reversed_at?->toISOString(),
            'reversed_by' => $this->reversed_by,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
