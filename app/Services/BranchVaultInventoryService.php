<?php

namespace App\Services;

use App\Models\CashFloatAssignment;
use App\Repositories\CashDenominationRepository;
use App\Support\Money;

class BranchVaultInventoryService
{
    public function __construct(
        private readonly CashDenominationRepository $vault,
    ) {}

    /** @return array<string, mixed> */
    public function inventory(int $branchId): array
    {
        $vault = $this->vault->getVaultBalance($branchId);
        $vaultTotal = $this->denominationTotal($vault);

        $openFloats = CashFloatAssignment::query()
            ->withoutGlobalScopes()
            ->with(['denominations', 'employee', 'issuer'])
            ->where('branch_id', $branchId)
            ->whereIn(
                'status',
                ['PENDING_RECEIPT', 'ACTIVE', 'PENDING_RECONCILIATION'],
            )
            ->orderByDesc('created_at')
            ->get();

        $employeeInventory = [];
        $employeeTotal = 0;

        foreach ($openFloats as $float) {
            $denominationBalance = [];

            foreach (Money::supportedDenominations() as $denom) {
                $denominationBalance[(string) $denom] = 0;
            }

            foreach ($float->denominations as $line) {
                $denominationBalance[(string) $line->denomination] =
                    (int) $line->quantity;
            }

            $lineTotal = $this->denominationTotal($denominationBalance);
            $employeeTotal += $lineTotal;

            $employeeInventory[] = [
                'float_id' => $float->id,
                'branch_id' => (int) $float->branch_id,
                'employee_id' => $float->employee_id,
                'employee_name' => $float->employee?->full_name,
                'status' => $float->status,
                'current_balance' => Money::normalize(
                    $float->current_balance ?? 0,
                ),
                'total_amount' => Money::normalize($float->total_amount),
                'denomination_balance' => $denominationBalance,
                'denom_total' => $lineTotal,
            ];
        }

        return [
            'branch_id' => $branchId,
            'main_vault' => $this->stringifyKeys($vault),
            'main_vault_total' => $vaultTotal,
            'employee_floats' => $employeeInventory,
            'total_employee_cash' => $employeeTotal,
            'grand_physical_total' => $vaultTotal + $employeeTotal,
        ];
    }

    /** @param array<int|string, int> $balance */
    private function denominationTotal(array $balance): int
    {
        $total = 0;

        foreach ($balance as $denom => $qty) {
            $total += ((int) $denom) * ((int) $qty);
        }

        return $total;
    }

    /**
     * @param  array<int, int>  $balance
     * @return array<string, int>
     */
    private function stringifyKeys(array $balance): array
    {
        $out = [];

        foreach ($balance as $denom => $qty) {
            $out[(string) $denom] = (int) $qty;
        }

        return $out;
    }
}
