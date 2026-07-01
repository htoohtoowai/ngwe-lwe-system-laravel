<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CashFloatAssignment;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Repositories\CashFloatRepository;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ports the cash float lifecycle from Python
 * repositories/cash_float_repository.py, backend/routes/cashier.py, and
 * services/vault_service.py (issue/confirm_return).
 *
 * Lifecycle:
 *   PENDING_RECEIPT → ACTIVE → PENDING_RECONCILIATION → CLOSED
 *
 * Vault ledger side-effects:
 *   - issue         → cash_denomination_logs (`vault_out`)
 *   - confirmReturn → cash_denomination_logs (`float_returned`)
 *
 * PIN verification, per-transaction cash-out denomination guarding, and
 * vault_transactions log are deferred to a later slice.
 */
class CashFloatService
{
    public function __construct(
        private readonly CashFloatRepository $floats,
        private readonly CashDenominationRepository $vault,
        private readonly PinVerifier $pinVerifier,
    ) {}

    /**
     * Cashier issues a new float to an employee.
     *
     * @param  array<int, int>  $denominations
     */
    public function issue(User $cashier, int $employeeId, array $denominations, ?string $note = null): CashFloatAssignment
    {
        $this->guardNonEmptyDenominations($denominations);

        return DB::transaction(function () use ($cashier, $employeeId, $denominations, $note): CashFloatAssignment {
            $float = $this->floats->issue($employeeId, $cashier->id, $denominations, $note);

            $this->vault->recordBulk(
                entryType: 'vault_out',
                denominations: $denominations,
                createdBy: $cashier->id,
                floatId: $float->id,
                note: $note ?? "Float #{$float->id} issued to employee #{$employeeId}",
            );

            $this->log($cashier->id, 'float_issued', $float->id, [
                'employee_id' => $employeeId,
                'total_amount' => Money::normalize($float->total_amount),
                'denominations' => $denominations,
                'note' => $note,
            ]);

            return $float;
        });
    }

    /**
     * Employee accepts a PENDING_RECEIPT float and marks it ACTIVE.
     * Requires the employee's PIN.
     */
    public function activate(User $employee, CashFloatAssignment $float, ?string $pin = null): CashFloatAssignment
    {
        if ($float->employee_id !== $employee->id) {
            throw new InvalidArgumentException("Float #{$float->id} does not belong to this employee.");
        }

        $this->pinVerifier->verify($employee, $pin);

        return DB::transaction(function () use ($employee, $float): CashFloatAssignment {
            $activated = $this->floats->activate($float);

            $this->log($employee->id, 'float_activated', $activated->id, [
                'total_amount' => Money::normalize($activated->total_amount),
                'current_balance' => Money::normalize($activated->current_balance ?? 0),
            ]);

            return $activated;
        });
    }

    /**
     * Employee reports the denominations they are returning to the cashier.
     *
     * @param  array<int, int>  $returnDenominations
     */
    public function initiateReturn(User $employee, CashFloatAssignment $float, array $returnDenominations): CashFloatAssignment
    {
        if ($float->employee_id !== $employee->id) {
            throw new InvalidArgumentException("Float #{$float->id} does not belong to this employee.");
        }

        Money::denominationTotal($returnDenominations);

        return DB::transaction(function () use ($employee, $float, $returnDenominations): CashFloatAssignment {
            $updated = $this->floats->initiateReturn($float, $returnDenominations);

            $this->log($employee->id, 'float_return_initiated', $updated->id, [
                'return_denominations' => $returnDenominations,
                'return_total' => Money::normalize(Money::denominationTotal($returnDenominations)),
            ]);

            return $updated;
        });
    }

    /**
     * Cashier confirms receipt of the returned float and closes it.
     */
    public function confirmReturn(User $cashier, CashFloatAssignment $float, float|string $closingTotal, ?string $pin = null): CashFloatAssignment
    {
        $this->pinVerifier->verify($cashier, $pin);

        return DB::transaction(function () use ($cashier, $float, $closingTotal): CashFloatAssignment {
            $returnDenominations = $this->normalizeReturnDenominations(
                $float->return_denominations_json ?? []
            );

            $closed = $this->floats->confirmReturn($float, $closingTotal);

            if ($returnDenominations !== []) {
                $this->vault->recordBulk(
                    entryType: 'float_returned',
                    denominations: $returnDenominations,
                    createdBy: $cashier->id,
                    floatId: $closed->id,
                    note: "Float #{$closed->id} return completed by cashier",
                );
            }

            $this->log($cashier->id, 'float_return_confirmed', $closed->id, [
                'closing_total' => Money::normalize($closed->closing_total ?? 0),
                'total_amount' => Money::normalize($closed->total_amount),
                'return_denominations' => $returnDenominations,
            ]);

            return $closed;
        });
    }

    /**
     * @param  array<int|string, int|string>  $raw
     * @return array<int, int>
     */
    private function normalizeReturnDenominations(array $raw): array
    {
        $normalized = [];
        foreach ($raw as $denom => $qty) {
            $normalized[(int) $denom] = (int) $qty;
        }

        return $normalized;
    }

    /**
     * @param  array<int, int>  $denominations
     */
    private function guardNonEmptyDenominations(array $denominations): void
    {
        $total = Money::denominationTotal($denominations);
        if ($total <= 0) {
            throw new InvalidArgumentException('Float must contain at least one denomination with quantity > 0.');
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function log(int $userId, string $action, int $entityId, array $details): void
    {
        ActivityLog::query()->create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => 'cash_float',
            'entity_id' => $entityId,
            'details' => $details,
        ]);
    }
}
