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
            'service_type_id' => $this->service_type_id,
            'account_name' => $this->account_name,
            'phone_number' => $this->phone_number,
            'balance' => $this->balance,
            'commission_rate' => $this->commission_rate,
            'is_active' => $this->is_active,
            'is_fee_account' => $this->is_fee_account,
            'service_type' => new ServiceTypeResource($this->whenLoaded('serviceType')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
