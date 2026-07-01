<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashFloatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee?->full_name,
            'issued_by' => $this->issued_by,
            'issued_by_name' => $this->issuer?->full_name,
            'status' => $this->status,
            'total_amount' => $this->total_amount,
            'current_balance' => $this->current_balance,
            'closing_total' => $this->closing_total,
            'return_denominations_json' => $this->return_denominations_json,
            'received_at' => $this->received_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
            'denominations' => $this->whenLoaded('denominations', function () {
                return $this->denominations->map(fn ($d) => [
                    'denomination' => (int) $d->denomination,
                    'quantity' => (int) $d->quantity,
                ]);
            }),
        ];
    }
}
