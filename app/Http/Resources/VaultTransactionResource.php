<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VaultTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'txn_type' => $this->txn_type,
            'movement_type' => $this->movement_type,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'destination_type' => $this->destination_type,
            'destination_id' => $this->destination_id,
            'float_id' => $this->float_id,
            'denomination' => $this->denomination,
            'quantity' => $this->quantity,
            'transaction_id' => $this->transaction_id,
            'performed_by' => $this->performed_by,
            'performed_by_name' => $this->performer?->full_name,
            'verified_by' => $this->verified_by,
            'verified_by_name' => $this->verifier?->full_name,
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
