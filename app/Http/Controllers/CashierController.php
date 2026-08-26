<?php

namespace App\Http\Controllers;

use App\Models\CashFloatAssignment;
use App\Models\CashFloatIssue;
use App\Models\Transaction;
use App\Models\TransactionNotificationRead;
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
    private const DEFAULT_SECTION = 'dashboard';

    private const SECTIONS = [
        self::DEFAULT_SECTION,
        'teller-entry-notifications',
        'main-vault-denomination-stock',
        'morning-issue',
        'end-of-day',
        'teller-entry-history',
        'teller-entry-history-cash-in',
        'teller-entry-history-cash-out',
        'teller-entry-history-transfer',
        'teller-entry-history-exchange',
        'teller-entry-history-send-money',
        'teller-entry-history-receive-money',
        'main-vault-audit-log',
    ];

    private const PAGE_COMPONENTS = [
        'dashboard' => 'cashier/Dashboard',
        'teller-entry-notifications' => 'cashier/Notifications',
        'main-vault-denomination-stock' => 'cashier/VaultStock',
        'morning-issue' => 'cashier/MorningIssue',
        'end-of-day' => 'cashier/EndOfDay',
        'teller-entry-history' => 'cashier/history/All',
        'teller-entry-history-cash-in' => 'cashier/history/CashIn',
        'teller-entry-history-cash-out' => 'cashier/history/CashOut',
        'teller-entry-history-transfer' => 'cashier/history/Transfer',
        'teller-entry-history-exchange' => 'cashier/history/Exchange',
        'teller-entry-history-send-money' => 'cashier/history/SendMoney',
        'teller-entry-history-receive-money' => 'cashier/history/ReceiveMoney',
        'main-vault-audit-log' => 'cashier/VaultAuditLog',
    ];

    public function __construct(
        private readonly CashDenominationRepository $vault,
        private readonly CashFloatRepository $floats,
        private readonly VaultTransactionRepository $vaultTransactions,
    ) {}

    public function __invoke(Request $request, ?string $section = null): Response
    {
        $section ??= self::DEFAULT_SECTION;
        abort_unless(in_array($section, self::SECTIONS, true), 404);

        $vault = $this->vault->getVaultBalance();
        $floatRows = $this->floats->list();
        $pendingAdditionalIssueCounts = CashFloatIssue::query()
            ->where('issue_type', 'ADDITIONAL')
            ->where('status', 'PENDING_RECEIPT')
            ->selectRaw('float_id, COUNT(*) as aggregate')
            ->groupBy('float_id')
            ->pluck('aggregate', 'float_id');

        return Inertia::render(self::PAGE_COMPONENTS[$section], [
            'role' => $request->user()->role,
            'announcement' => 'Manage the main vault, Teller floats and end-of-day returns.',
            'notificationCount' => $this->unreadNotificationCount((int) $request->user()->id),
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
                ->map(function (User $user) use ($floatRows, $pendingAdditionalIssueCounts): array {
                    $openFloat = $floatRows->first(
                        fn (CashFloatAssignment $float): bool =>
                            $float->employee_id === $user->id
                            && in_array($float->status, ['PENDING_RECEIPT', 'ACTIVE', 'PENDING_RECONCILIATION'], true),
                    );

                    return [
                        'id' => $user->id,
                        'name' => $user->full_name ?: $user->username,
                        'open_float_id' => $openFloat?->id,
                        'open_float_status' => $openFloat?->status,
                        'pending_additional_issues' => $openFloat
                            ? (int) ($pendingAdditionalIssueCounts[$openFloat->id] ?? 0)
                            : 0,
                    ];
                })->values()->all(),
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
            'notificationCount' => $this->unreadNotificationCount((int) $request->user()->id),
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
     * Cash In entries created by Tellers and waiting for Cashier cash count.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pendingCashIns(): array
    {
        return Transaction::query()
            ->with('creator')
            ->whereIn('transaction_type', ['cash_in', 'send_money'])
            ->where('status', 'PENDING_CASHIER_CONFIRM')
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'transaction_type' => $transaction->transaction_type,
                'amount' => Money::normalize($transaction->amount ?? 0),
                'customer_name' => $transaction->customer_name,
                'teller' => $transaction->creator?->full_name ?? $transaction->creator?->username ?? 'Teller',
                'creator_role' => $transaction->creator?->role,
                'settlement_amount' => $this->cashCollectionSettlementAmount($transaction),
                'customer_fee' => Money::normalize($transaction->customer_fee ?? 0),
                'fee_payment_method' => $transaction->fee_payment_method,
                'received_denominations' => $transaction->received_denominations ?? [],
                'handoff_denominations' => $transaction->handoff_denominations ?? [],
                'change_denominations' => $transaction->change_denominations ?? [],
                'change_given' => Money::normalize($transaction->change_given ?? 0),
                'created_at' => $transaction->created_at?->toISOString(),
            ])
            ->values()
            ->all();
    }

    private function unreadNotificationCount(int $userId): int
    {
        $readTransactionIds = TransactionNotificationRead::query()
            ->where('user_id', $userId)
            ->select('transaction_id');

        return Transaction::query()
            ->whereIn('transaction_type', ['cash_in', 'send_money'])
            ->where('status', 'PENDING_CASHIER_CONFIRM')
            ->whereNotIn('id', $readTransactionIds)
            ->count();
    }

    private function cashCollectionSettlementAmount(Transaction $transaction): string
    {
        if ($transaction->transaction_type === 'send_money') {
            return Money::normalize($transaction->customer_total ?? $transaction->amount ?? 0);
        }

        $cashFee = $transaction->fee_payment_method === 'cash'
            ? (float) ($transaction->customer_fee ?? 0)
            : 0.0;

        return Money::normalize((float) ($transaction->amount ?? 0) + $cashFee);
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
        return collect($this->vaultTransactions->groupedLog(limit: 200))
            ->map(fn (array $row): array => [
                'id' => $row['id'],
                'batch_id' => $row['batch_id'],
                'type' => $row['txn_type'],
                'movement_type' => $row['movement_type'],
                'source_type' => $row['source_type'],
                'source_id' => $row['source_id'],
                'destination_type' => $row['destination_type'],
                'destination_id' => $row['destination_id'],
                'float_id' => $row['float_id'],
                'transaction_id' => $row['transaction_id'],
                'total_amount' => $row['total_amount'],
                'denomination_count' => $row['denomination_count'],
                'details' => $row['details'],
                'note' => $row['note'],
                'performed_by' => $row['performed_by_name'],
                'verified_by' => $row['verified_by_name'],
                'created_at' => $row['created_at'],
                'reconciliation_status' => $row['reconciliation_status'],
                'reconciliation_issues' => $row['reconciliation_issues'],
            ])->values()->all();
    }
}
