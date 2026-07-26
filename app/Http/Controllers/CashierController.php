<?php

namespace App\Http\Controllers;

use App\Models\CashFloatAssignment;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Repositories\CashFloatRepository;
use App\Repositories\VaultTransactionRepository;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashierController extends Controller
{
    public function __construct(
        private readonly CashDenominationRepository $vault,
        private readonly CashFloatRepository $floats,
        private readonly VaultTransactionRepository $vaultTransactions,
    ) {}

    public function __invoke(Request $request): Response
    {
        $vault = $this->vault->getVaultBalance();
        $floatRows = $this->floats->list();

        return Inertia::render('cashier/Operations', [
            'role' => $request->user()->role,
            'announcement' => 'Manage the main vault, Teller floats and end-of-day returns.',
            'notificationCount' => Transaction::query()
                ->where('transaction_type', 'cash_in')
                ->where('status', 'PENDING_CASHIER_CONFIRM')
                ->count(),
            'pendingCashIns' => $this->pendingCashIns(),
            'notes' => $this->notes(),
            'mainVault' => $this->stringify($vault),
            'availableVault' => $this->stringify($this->vault->getAvailableBalance()),
            'vaultTotal' => $this->total($vault),
            'vaultLogs' => $this->vaultLogs(),
            'floats' => $floatRows->map(fn (CashFloatAssignment $float): array => [
                'id' => $float->id,
                'employee_id' => $float->employee_id,
                'employee_name' => $float->employee?->full_name ?? $float->employee?->username ?? 'Teller',
                'status' => $float->status,
                'total_amount' => Money::normalize($float->total_amount),
                'current_balance' => Money::normalize($float->current_balance ?? 0),
                'closing_total' => Money::normalize($float->closing_total ?? 0),
                'return_denominations_json' => $float->return_denominations_json,
                'created_at' => $float->created_at?->toISOString(),
                'received_at' => $float->received_at?->toISOString(),
                'closed_at' => $float->closed_at?->toISOString(),
                'denominations' => $float->denominations->map(fn ($row): array => [
                    'denomination' => (int) $row->denomination,
                    'quantity' => (int) $row->quantity,
                ])->values()->all(),
            ])->values()->all(),
            'tellers' => User::query()
                ->where('role', 'teller')
                ->where('is_active', true)
                ->orderBy('full_name')
                ->get(['id', 'username', 'full_name'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->full_name ?: $user->username,
                ])->values()->all(),
            'transactions' => Transaction::query()
                ->with('creator')
                ->whereHas('creator', fn ($query) => $query->where('role', 'teller'))
                ->latest('created_at')
                ->limit(100)
                ->get()
                ->map(fn (Transaction $transaction): array => [
                    'id' => $transaction->id,
                    'type' => $transaction->transaction_type,
                    'amount' => Money::normalize($transaction->amount ?? 0),
                    'fee' => Money::normalize($transaction->customer_fee ?? 0),
                    'status' => $transaction->status,
                    'customer' => $transaction->customer_name,
                    'teller' => $transaction->creator?->full_name ?? $transaction->creator?->username ?? 'Teller',
                    'created_at' => $transaction->created_at?->toISOString(),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function profile(Request $request): Response
    {
        return Inertia::render('cashier/Profile', [
            'role' => 'cashier',
            'announcement' => 'Keep your Cashier PIN private and update it regularly.',
            'user' => [
                'id' => $request->user()->id,
                'username' => $request->user()->username,
                'full_name' => $request->user()->full_name,
                'role' => $request->user()->role,
                'has_pin' => $request->user()->pin_hash !== null,
            ],
        ]);
    }

    /**
     * Cash In entries created by Tellers and waiting for Cashier review.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pendingCashIns(): array
    {
        return Transaction::query()
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
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function notes(): array
    {
        $notes = Money::supportedDenominations();
        rsort($notes);

        return $notes;
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
            0
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function vaultLogs(): array
    {
        return collect($this->vaultTransactions->paginateLog(perPage: 80)->items())
            ->map(fn ($row): array => [
                'id' => $row->id,
                'type' => $row->txn_type,
                'float_id' => $row->float_id,
                'denomination' => (int) $row->denomination,
                'quantity' => (int) $row->quantity,
                'note' => $row->note,
                'performed_by' => $row->performer?->full_name ?? $row->performer?->username,
                'created_at' => $row->created_at?->toISOString(),
            ])->values()->all();
    }
}
