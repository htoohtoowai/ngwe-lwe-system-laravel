<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashDenominationLog;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashierController extends Controller
{
    public function __construct(private readonly CashDenominationRepository $vault) {}

    public function employees(Request $request): JsonResponse
    {
        $this->guardCashier($request);

        return response()->json([
            'data' => User::query()
                ->where('role', 'teller')
                ->where('is_active', true)
                ->orderBy('full_name')
                ->get(['id', 'username', 'full_name'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'full_name' => $user->full_name,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function pendingCashIns(Request $request): JsonResponse
    {
        $this->guardCashier($request);

        return response()->json([
            'data' => Transaction::query()
                ->with('creator')
                ->where('transaction_type', 'cash_in')
                ->where('status', 'PENDING_CASHIER_CONFIRM')
                ->latest('created_at')
                ->limit(100)
                ->get()
                ->map(fn (Transaction $transaction): array => [
                    'id' => $transaction->id,
                    'amount' => Money::normalize($transaction->amount ?? 0),
                    'customer_name' => $transaction->customer_name,
                    'teller' => $transaction->creator?->full_name ?? $transaction->creator?->username ?? 'Teller',
                    'received_denominations' => $transaction->received_denominations ?? [],
                    'handoff_denominations' => $transaction->handoff_denominations ?? [],
                    'change_denominations' => $transaction->change_denominations ?? [],
                    'change_given' => Money::normalize($transaction->change_given ?? 0),
                    'created_at' => $transaction->created_at?->toISOString(),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function vault(Request $request): JsonResponse
    {
        $this->guardCashier($request, allowAdmin: true);
        $balance = $this->vault->getVaultBalance();

        return response()->json([
            'denominations' => $this->stringify($balance),
            'total' => $this->total($balance),
        ]);
    }

    public function denominations(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        return response()->json([
            'data' => collect(Money::supportedDenominations())
                ->sortDesc()
                ->map(fn (int $denomination): array => [
                    'denomination' => $denomination,
                    'label' => number_format($denomination).' MMK',
                ])
                ->values()
                ->all(),
        ]);
    }

    public function vaultLogs(Request $request): JsonResponse
    {
        $this->guardCashier($request, allowAdmin: true);

        return response()->json([
            'data' => CashDenominationLog::query()
                ->with('creator')
                ->latest('created_at')
                ->limit(100)
                ->get()
                ->map(fn (CashDenominationLog $log): array => [
                    'id' => $log->id,
                    'entry_type' => $log->entry_type,
                    'denomination' => (int) $log->denomination,
                    'quantity' => (int) $log->quantity,
                    'transaction_id' => $log->transaction_id,
                    'float_id' => $log->float_id,
                    'performed_by' => $log->creator?->full_name ?? $log->creator?->username,
                    'note' => $log->note,
                    'created_at' => $log->created_at?->toISOString(),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function stringify(array $balance): array
    {
        return collect($balance)->mapWithKeys(
            fn ($quantity, $denomination): array => [(string) $denomination => (int) $quantity]
        )->all();
    }

    private function total(array $balance): int
    {
        return (int) collect($balance)->reduce(
            fn (int $total, $quantity, $denomination): int => $total + ((int) $denomination * (int) $quantity),
            0,
        );
    }

    private function guardCashier(Request $request, bool $allowAdmin = false): void
    {
        $role = $request->user()?->role;
        $allowed = $allowAdmin ? ['cashier', 'admin'] : ['cashier'];

        abort_unless(in_array($role, $allowed, true), 403, 'Cashier access required.');
    }
}
