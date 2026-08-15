<?php

namespace App\Services;

use App\Repositories\CashDenominationRepository;
use App\Repositories\CashFloatRepository;
use App\Support\Money;

class VaultInventoryService
{
    public function __construct(
        private readonly CashDenominationRepository $vault,
        private readonly CashFloatRepository $floats,
    ) {}

    /** @return array<string, mixed> */
    public function inventory(): array
    {
        $vault = $this->vault->getVaultBalance();
        $vaultTotal = $this->denominationTotal($vault);

        $openFloats = $this->floats->list(status: null)
            ->whereIn('status', ['PENDING_RECEIPT', 'ACTIVE', 'PENDING_RECONCILIATION']);

        $employeeInventory = [];
        $employeeTotal = 0;

        foreach ($openFloats as $float) {
            $denominationBalance = [];
            foreach (Money::supportedDenominations() as $denom) {
                $denominationBalance[(string) $denom] = 0;
            }
            foreach ($float->denominations as $line) {
                $denominationBalance[(string) $line->denomination] = (int) $line->quantity;
            }

            $lineTotal = $this->denominationTotal($denominationBalance);
            $employeeTotal += $lineTotal;

            $employeeInventory[] = [
                'float_id' => $float->id,
                'employee_id' => $float->employee_id,
                'employee_name' => $float->employee?->full_name,
                'status' => $float->status,
                'current_balance' => Money::normalize($float->current_balance ?? 0),
                'total_amount' => Money::normalize($float->total_amount),
                'denomination_balance' => $denominationBalance,
                'denom_total' => $lineTotal,
            ];
        }

        return [
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

    /** @param array<int, int> $balance @return array<string, int> */
    private function stringifyKeys(array $balance): array
    {
        $out = [];
        foreach ($balance as $denom => $qty) {
            $out[(string) $denom] = (int) $qty;
        }

        return $out;
    }
}
