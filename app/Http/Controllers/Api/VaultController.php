<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VaultLogRequest;
use App\Http\Resources\VaultTransactionResource;
use App\Repositories\CashDenominationRepository;
use App\Repositories\CashFloatRepository;
use App\Repositories\VaultTransactionRepository;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VaultController extends Controller
{
    public function __construct(
        private readonly CashDenominationRepository $vault,
        private readonly CashFloatRepository $floats,
        private readonly VaultTransactionRepository $vaultTransactions,
    ) {}

    public function balance(): JsonResponse
    {
        $vault = $this->vault->getVaultBalance();
        $pending = $this->vault->getPendingReserved();
        $available = $this->vault->getAvailableBalance();

        return response()->json([
            'data' => [
                'vault' => $this->stringifyKeys($vault),
                'pending_reserved' => $this->stringifyKeys($pending),
                'available' => $this->stringifyKeys($available),
                'vault_total' => $this->denominationTotal($vault),
                'available_total' => $this->denominationTotal($available),
            ],
        ]);
    }

    public function inventory(): JsonResponse
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
            $lineTotal = 0;
            foreach ($denominationBalance as $denom => $qty) {
                $lineTotal += ((int) $denom) * $qty;
            }
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

        return response()->json([
            'data' => [
                'main_vault' => $this->stringifyKeys($vault),
                'main_vault_total' => $vaultTotal,
                'employee_floats' => $employeeInventory,
                'total_employee_cash' => $employeeTotal,
                'grand_physical_total' => $vaultTotal + $employeeTotal,
            ],
        ]);
    }

    public function log(VaultLogRequest $request): AnonymousResourceCollection
    {
        return VaultTransactionResource::collection(
            $this->vaultTransactions->paginateLog(
                txnType: $request->string('txn_type')->trim()->value() ?: null,
                floatId: $request->integer('float_id') ?: null,
                dateFrom: $request->string('date_from')->trim()->value() ?: null,
                dateTo: $request->string('date_to')->trim()->value() ?: null,
                perPage: $request->integer('per_page') ?: 50,
            )
        );
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

    /**
     * @param  array<int, int>  $balance
     */
    private function denominationTotal(array $balance): int
    {
        $total = 0;
        foreach ($balance as $denom => $qty) {
            $total += ((int) $denom) * ((int) $qty);
        }

        return $total;
    }
}
