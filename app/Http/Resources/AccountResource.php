<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'service_type_id' => $this->service_type_id,
            'account_name' => $this->account_name,
            'phone_number' => $this->phone_number,
            'balance' => $this->balance,
            'commission_rate' => $this->commission_rate,
            'is_active' => $this->is_active,
            'is_fee_account' => $this->is_fee_account,
            'is_agent' => $this->is_agent,
            'company' => new CompanyResource($this->whenLoaded('company')),
            'service_type' => new ServiceTypeResource($this->whenLoaded('serviceType')),
            'features' => $this->whenLoaded(
                'featureAssignments',
                fn () => $this->featureAssignments
                    ->pluck('feature')
                    ->map(fn ($feature) => $feature instanceof \BackedEnum ? $feature->value : $feature)
                    ->values()
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
