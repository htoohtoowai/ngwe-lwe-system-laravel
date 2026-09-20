<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
            'is_active' => (bool) $this->is_active,
            'cashier' => $this->whenLoaded('cashier', function (): ?array {
                if ($this->cashier === null) {
                    return null;
                }

                return [
                    'id' => $this->cashier->id,
                    'username' => $this->cashier->username,
                    'full_name' => $this->cashier->full_name,
                    'is_active' => (bool) $this->cashier->is_active,
                ];
            }),
            'staff_count' => (int) ($this->staff_count ?? 0),
            'active_staff_count' => (int) ($this->active_staff_count ?? 0),
            'teller_count' => (int) ($this->teller_count ?? 0),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
