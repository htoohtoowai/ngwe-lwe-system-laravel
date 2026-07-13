<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyReconciliationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recon_date' => $this->recon_date?->toDateString(),
            'closed_by' => $this->closed_by,
            'closed_by_name' => $this->closer?->full_name,
            'closed_at' => $this->closed_at?->toISOString(),
            'total_cash_in' => $this->total_cash_in,
            'total_cash_out' => $this->total_cash_out,
            'total_transfer' => $this->total_transfer,
            'total_exchange' => $this->total_exchange,
            'total_commission' => $this->total_commission,
            'total_customer_fees' => $this->total_customer_fees,
            'main_vault_total' => $this->main_vault_total,
            'employee_floats_total' => $this->employee_floats_total,
            'total_cash' => $this->total_cash,
            'total_digital' => $this->total_digital,
            'grand_total' => $this->grand_total,
            'employee_snapshots' => $this->employee_snapshots,
            'account_snapshots' => $this->account_snapshots,
            'vault_snapshot' => $this->vault_snapshot,
            'notes' => $this->notes,
        ];
    }
}
