<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'full_name' => $this->full_name,
            'role' => $this->role,
            'branch_id' => $this->branch_id !== null ? (int) $this->branch_id : null,
            'branch' => $this->whenLoaded('branch', function (): ?array {
                if ($this->branch === null) {
                    return null;
                }

                return [
                    'id' => (int) $this->branch->id,
                    'code' => $this->branch->code,
                    'name' => $this->branch->name,
                    'is_active' => (bool) $this->branch->is_active,
                ];
            }),
            'is_active' => (bool) $this->is_active,
            'auth_version' => (int) $this->auth_version,
            'has_pin' => $this->pin_hash !== null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
