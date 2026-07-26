<?php

namespace App\Services;

use App\Exceptions\InsufficientFloatDenominationException;
use App\Exceptions\InsufficientFloatException;
use App\Models\CashFloatAssignment;
use App\Repositories\CashFloatRepository;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Pure validation helper — no writes. Ports the read-side of Python
 * VaultService.validate_cash_out. The same checks apply to cash-out,
 * transfer, and exchange when the initiating user is an employee, so this
 * method is operation-agnostic:
 *   - active float belongs to the employee
 *   - denomination map is well-formed and non-empty
 *   - per-denomination stock covers the requested breakdown
 *   - denomination total matches the transaction amount within 1 MMK
 *   - float `current_balance` covers the total
 */
class EmployeeFloatValidator
{
    public function __construct(private readonly CashFloatRepository $floats) {}

    /**
     * @param  array<int|string, int|string>  $denominations
     * @return array<int, int> normalized denomination map (positive quantities only)
     */
    public function validateFloatOperation(int $employeeId, array $denominations, float|string $amount, string $operationLabel = 'this operation'): array
    {
        $normalized = $this->normalizeDenominations($denominations);
        if ($normalized === []) {
            throw new InvalidArgumentException(
                "Denomination breakdown is required for employee {$operationLabel}."
            );
        }

        $active = $this->floats->activeForEmployee($employeeId);
        if ($active === null) {
            throw new InvalidArgumentException(
                'No active float. Receive your float from the cashier first.'
            );
        }

        $this->guardPerDenominationStock($active, $normalized);

        $denominationTotal = Money::denominationTotal($normalized);
        $amountFloat = (float) $amount;

        if (abs($denominationTotal - $amountFloat) > 1) {
            throw new InvalidArgumentException(
                "Denomination total {$denominationTotal} does not match {$operationLabel} amount {$amountFloat}."
            );
        }

        $floatBalance = (float) ($active->current_balance ?? 0);
        if ($denominationTotal > $floatBalance) {
            throw new InsufficientFloatException(
                Money::normalize($floatBalance),
                Money::normalize($denominationTotal),
            );
        }

        return $normalized;
    }

    /**
     * Normalize a denomination map without checking float stock.
     * Used when cash is received into a float rather than paid out of it.
     *
     * @param  array<int|string, int|string>  $raw
     * @return array<int, int>
     */
    public function normalizeReceivedDenominations(array $raw): array
    {
        return $this->normalizeDenominations($raw);
    }

    /**
     * @param  array<int|string, int|string>  $raw
     * @return array<int, int>
     */
    private function normalizeDenominations(array $raw): array
    {
        $supported = Money::supportedDenominations();
        $normalized = [];
        foreach ($raw as $denomination => $quantity) {
            $denomination = (int) $denomination;
            $quantity = (int) $quantity;
            if ($quantity <= 0) {
                continue;
            }
            if (! in_array($denomination, $supported, true)) {
                throw new InvalidArgumentException("Unsupported denomination: {$denomination}");
            }
            $normalized[$denomination] = ($normalized[$denomination] ?? 0) + $quantity;
        }

        return $normalized;
    }

    /**
     * @param  array<int, int>  $requested
     */
    private function guardPerDenominationStock(CashFloatAssignment $float, array $requested): void
    {
        $current = $this->floats->getDenominationBalance($float->id);
        foreach ($requested as $denomination => $quantity) {
            $available = (int) ($current[$denomination] ?? 0);
            if ($quantity > $available) {
                throw new InsufficientFloatDenominationException(
                    $denomination,
                    $available,
                    $quantity,
                );
            }
        }
    }
}
