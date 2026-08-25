<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'actor_name' => $this->actor_name ?? $this->user?->full_name ?? $this->user?->username,
            'actor_role' => $this->actor_role ?? $this->user?->role,
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user ? [
                'username' => $this->user->username,
                'full_name' => $this->user->full_name,
                'role' => $this->user->role,
            ] : null),
            'action' => $this->action,
            'category' => $this->category,
            'module' => $this->module,
            'status' => $this->status,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'description' => $this->description,
            'details' => $this->details,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'changed_fields' => $this->changed_fields,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'route' => $this->route,
            'http_method' => $this->http_method,
            'request_id' => $this->request_id,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
