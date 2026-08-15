<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CashFloatAssignment;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Repositories\CashFloatRepository;
use App\Repositories\VaultTransactionRepository;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

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
 * `vault_transactions` rows are written as an immutable audit trail, one row
 * per denomination quantity moved through the float lifecycle.
 */
class CashFloatService
{
    public function __construct(
        private readonly CashFloatRepository $floats,
        private readonly CashDenominationRepository $vault,
        private readonly VaultTransactionRepository $vaultTransactions,
        private readonly PinVerifier $pinVerifier,
        private readonly RealtimeBroadcastService $broadcasts,
    ) {}

    /**
     * Cashier issues a new float to an employee.
     *
     * @param  array<int, int>  $denominations
     */
    public function issue(User $cashier, int $employeeId, array $denominations, ?string $note = null): CashFloatAssignment
    {
        $this->guardNonEmptyDenominations($denominations);

        $float = DB::transaction(function () use ($cashier, $employeeId, $denominations, $note): CashFloatAssignment {
            User::query()->whereKey($employeeId)->lockForUpdate()->firstOrFail();

            $hasOpenFloat = CashFloatAssignment::query()
                ->where('employee_id', $employeeId)
                ->whereIn('status', ['PENDING_RECEIPT', 'ACTIVE', 'PENDING_RECONCILIATION'])
                ->exists();

            if ($hasOpenFloat) {
                throw new RuntimeException(
                    'This Teller already has an open float. Close the current float before issuing another one.',
                );
            }

            $float = $this->floats->issue($employeeId, $cashier->id, $denominations, $note);

            $this->vault->recordBulk(
                entryType: 'vault_out',
                denominations: $denominations,
                createdBy: $cashier->id,
                floatId: $float->id,
                note: $note ?? "Float #{$float->id} issued to employee #{$employeeId}",
            );

            $this->vaultTransactions->recordBulk(
                txnType: 'float_issue',
                denominations: $denominations,
                performedBy: $cashier->id,
                floatId: $float->id,
                note: $note,
            );

            $this->log($cashier->id, 'float_issued', $float->id, [
                'employee_id' => $employeeId,
                'total_amount' => Money::normalize($float->total_amount),
                'denominations' => $denominations,
                'note' => $note,
            ]);

            return $float;
        });

        $this->broadcasts->floatStatusChanged($float);

        return $float;
    }

    /**
     * Employee accepts a PENDING_RECEIPT float and marks it ACTIVE.
     * Requires the employee's PIN.
     *
     * @param  array<int|string, int|string>  $verifiedDenominations
     */
    public function activate(
        User $employee,
        CashFloatAssignment $float,
        ?string $pin,
        array $verifiedDenominations,
    ): CashFloatAssignment {
        if ($float->employee_id !== $employee->id) {
            throw new InvalidArgumentException("Float #{$float->id} does not belong to this employee.");
        }

        $this->pinVerifier->verify($employee, $pin);
        $this->assertVerifiedDenominationsMatch($float, $verifiedDenominations);

        $activated = DB::transaction(function () use ($employee, $float): CashFloatAssignment {
            $activated = $this->floats->activate($float);
            $denominations = $this->denominationsFromFloat($activated);

            $this->vaultTransactions->recordBulk(
                txnType: 'float_receipt',
                denominations: $denominations,
                performedBy: $employee->id,
                floatId: $activated->id,
                note: "Float #{$activated->id} receipt completed",
            );

            $this->log($employee->id, 'float_activated', $activated->id, [
                'total_amount' => Money::normalize($activated->total_amount),
                'current_balance' => Money::normalize($activated->current_balance ?? 0),
            ]);

            return $activated;
        });

        $this->broadcasts->floatStatusChanged($activated);

        return $activated;
    }

    /**
     * Employee rejects an issued float before receiving it.
     */
    public function rejectReceipt(
        User $employee,
        CashFloatAssignment $float,
        ?string $pin,
        ?string $note = null,
    ): CashFloatAssignment {
        if ($float->employee_id !== $employee->id) {
            throw new InvalidArgumentException("Float #{$float->id} does not belong to this employee.");
        }

        $this->pinVerifier->verify($employee, $pin);

        $denominations = $this->denominationsFromFloat($float->loadMissing('denominations'));
        $this->guardNonEmptyDenominations($denominations);

        $auditNote = trim((string) $note);
        if ($auditNote === '') {
            $auditNote = "Float #{$float->id} rejected by Teller before receipt";
        }

        $rejected = DB::transaction(function () use ($employee, $float, $denominations, $auditNote): CashFloatAssignment {
            $rejected = $this->floats->rejectPendingReceipt($float, $denominations, $auditNote);

            $this->vault->recordBulk(
                entryType: 'float_returned',
                denominations: $denominations,
                createdBy: $employee->id,
                floatId: $rejected->id,
                note: $auditNote,
            );

            $this->vaultTransactions->recordBulk(
                txnType: 'float_reject',
                denominations: $denominations,
                performedBy: $employee->id,
                floatId: $rejected->id,
                verifiedBy: $employee->id,
                note: $auditNote,
            );

            $this->log($employee->id, 'float_receipt_rejected', $rejected->id, [
                'total_amount' => Money::normalize($rejected->total_amount),
                'return_denominations' => $denominations,
                'note' => $auditNote,
            ]);

            return $rejected;
        });

        $this->broadcasts->floatStatusChanged($rejected);

        return $rejected;
    }

    /**
     * @param  array<int|string, int|string>  $verifiedDenominations
     */
    private function assertVerifiedDenominationsMatch(CashFloatAssignment $float, array $verifiedDenominations): void
    {
        $issued = $this->denominationsFromFloat($float->loadMissing('denominations'));
        $verified = $this->normalizeReturnDenominations($verifiedDenominations);

        Money::denominationTotal($verified);

        foreach (Money::supportedDenominations() as $denomination) {
            $issuedQuantity = $issued[$denomination] ?? 0;
            $verifiedQuantity = $verified[$denomination] ?? 0;

            if ($issuedQuantity !== $verifiedQuantity) {
                throw new InvalidArgumentException(
                    "Denomination {$denomination} MMK — Issued: {$issuedQuantity}, You counted: {$verifiedQuantity}"
                );
            }
        }
    }

    /**
     * Employee reports the denominations they are returning to the cashier.
     *
     * @param  array<int|string, int|string>  $returnDenominations
     */
    public function initiateReturn(User $employee, CashFloatAssignment $float, array $returnDenominations, ?string $pin = null): CashFloatAssignment
    {
        if ($float->employee_id !== $employee->id) {
            throw new InvalidArgumentException("Float #{$float->id} does not belong to this employee.");
        }

        $this->pinVerifier->verify($employee, $pin);
        $returnDenominations = $this->normalizeReturnDenominations($returnDenominations);
        $this->assertReturnDenominationsMatchFloat($float, $returnDenominations, 'ACTIVE');

        $updated = DB::transaction(function () use ($employee, $float, $returnDenominations): CashFloatAssignment {
            $updated = $this->floats->initiateReturn($float, $returnDenominations);

            $this->vaultTransactions->recordBulk(
                txnType: 'return_initiate',
                denominations: $returnDenominations,
                performedBy: $employee->id,
                floatId: $updated->id,
                note: "Return initiated for float #{$updated->id}",
            );

            $this->log($employee->id, 'float_return_initiated', $updated->id, [
                'return_denominations' => $returnDenominations,
                'return_total' => Money::normalize(Money::denominationTotal($returnDenominations)),
            ]);

            return $updated;
        });

        $this->broadcasts->floatStatusChanged($updated);

        return $updated;
    }

    /**
     * Cashier confirms receipt of the returned float and closes it.
     */
    public function confirmReturn(
        User $cashier,
        CashFloatAssignment $float,
        float|string $closingTotal,
        ?string $pin = null,
        ?array $cashierReturnDenominations = null,
    ): CashFloatAssignment {
        $this->pinVerifier->verify($cashier, $pin);

        $closed = DB::transaction(function () use ($cashier, $float, $closingTotal, $cashierReturnDenominations): CashFloatAssignment {
            $returnDenominations = $this->normalizeReturnDenominations(
                $float->return_denominations_json ?? []
            );
            $this->assertReturnDenominationsMatchFloat($float, $returnDenominations, 'PENDING_RECONCILIATION');

            if ($cashierReturnDenominations !== null) {
                $cashierReturnDenominations = $this->normalizeReturnDenominations($cashierReturnDenominations);
                $this->assertCashierReturnDenominationsMatchTellerReturn(
                    $returnDenominations,
                    $cashierReturnDenominations,
                );
            }

            $returnTotal = Money::denominationTotal($returnDenominations);
            $closingTotal = Money::normalize($closingTotal);
            if (abs($returnTotal - (float) $closingTotal) > 1) {
                throw new InvalidArgumentException(
                    "closing_total {$closingTotal} does not match return denomination total {$returnTotal}."
                );
            }

            $closed = $this->floats->confirmReturn($float, $returnTotal);

            if ($returnDenominations !== []) {
                $this->vault->recordBulk(
                    entryType: 'float_returned',
                    denominations: $returnDenominations,
                    createdBy: $cashier->id,
                    floatId: $closed->id,
                    note: "Float #{$closed->id} return completed by cashier",
                );
            }

            $this->vaultTransactions->recordBulk(
                txnType: 'return_confirm',
                denominations: $returnDenominations,
                performedBy: $cashier->id,
                floatId: $closed->id,
                verifiedBy: $cashier->id,
                note: "Float #{$closed->id} return completed",
            );

            $this->log($cashier->id, 'float_return_confirmed', $closed->id, [
                'closing_total' => Money::normalize($closed->closing_total ?? 0),
                'total_amount' => Money::normalize($closed->total_amount),
                'return_denominations' => $returnDenominations,
            ]);

            return $closed;
        });

        $this->broadcasts->floatStatusChanged($closed);

        return $closed;
    }

    /**
     * @return array<int, int>
     */
    private function denominationsFromFloat(CashFloatAssignment $float): array
    {
        $denominations = [];
        foreach ($float->denominations as $line) {
            $denominations[(int) $line->denomination] = (int) $line->quantity;
        }

        return $denominations;
    }

    /**
     * @param  array<int|string, int|string>  $raw
     * @return array<int, int>
     */
    private function normalizeReturnDenominations(array $raw): array
    {
        $supported = Money::supportedDenominations();
        $normalized = [];
        foreach ($raw as $denom => $qty) {
            $denom = (int) $denom;
            $qty = (int) $qty;
            if (! in_array($denom, $supported, true)) {
                throw new InvalidArgumentException("Invalid denomination: {$denom}");
            }
            if ($qty < 0) {
                throw new InvalidArgumentException('Denomination quantity cannot be negative.');
            }
            if ($qty <= 0) {
                continue;
            }

            $normalized[$denom] = ($normalized[$denom] ?? 0) + $qty;
        }

        return $normalized;
    }

    /**
     * @param  array<int, int>  $returnDenominations
     */
    private function assertReturnDenominationsMatchFloat(CashFloatAssignment $float, array $returnDenominations, string $expectedStatus): void
    {
        $currentFloat = $float->fresh() ?? $float;
        if ($currentFloat->status !== $expectedStatus) {
            throw new RuntimeException("Float #{$currentFloat->id} is not {$expectedStatus}.");
        }

        $returnTotal = Money::denominationTotal($returnDenominations);
        $floatBalance = (float) Money::normalize($currentFloat->current_balance ?? 0);
        if (abs($returnTotal - $floatBalance) > 1) {
            throw new InvalidArgumentException(
                "Return total {$returnTotal} does not match float balance ".Money::normalize($floatBalance).'.'
            );
        }

        $currentDenominations = $this->floats->getDenominationBalance($currentFloat->id);
        foreach (Money::supportedDenominations() as $denomination) {
            $expectedQuantity = (int) ($currentDenominations[$denomination] ?? 0);
            $returnQuantity = (int) ($returnDenominations[$denomination] ?? 0);

            if ($returnQuantity !== $expectedQuantity) {
                throw new InvalidArgumentException(
                    "Return denomination {$denomination} MMK must match float stock. System on hand: {$expectedQuantity}, Teller counted: {$returnQuantity}."
                );
            }
        }
    }

    /**
     * @param  array<int, int>  $tellerReturnDenominations
     * @param  array<int, int>  $cashierReturnDenominations
     */
    private function assertCashierReturnDenominationsMatchTellerReturn(
        array $tellerReturnDenominations,
        array $cashierReturnDenominations,
    ): void {
        foreach (Money::supportedDenominations() as $denomination) {
            $tellerQuantity = (int) ($tellerReturnDenominations[$denomination] ?? 0);
            $cashierQuantity = (int) ($cashierReturnDenominations[$denomination] ?? 0);

            if ($cashierQuantity !== $tellerQuantity) {
                throw new InvalidArgumentException(
                    "Cashier counted return denomination {$denomination} MMK must match Teller handback. Teller reported: {$tellerQuantity}, Cashier counted: {$cashierQuantity}."
                );
            }
        }
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
