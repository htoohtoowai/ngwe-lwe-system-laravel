<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\CashFloatAssignment;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * @var array<int, string>
     */
    private array $accountLabels = [];

    public function __invoke(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->role === 'cashier') {
            return redirect()->route('cashier');
        }
        $range = $this->range($request);

        return Inertia::render('Dashboard', [
            'role' => $user->role,
            'announcement' => 'Counter dashboard is ready for today.',
            'notificationCount' => $this->notificationCount($user),
            'range' => $range,
            'chart' => $this->chart($user, $range),
            'companies' => $this->companies(),
            'accounts' => $this->accounts(),
            'floats' => $this->floats($user),
            'recent' => $this->recent($user),
            'pendingCashIns' => $user->role === 'cashier' ? $this->pendingCashIns() : [],
        ]);
    }

    private function range(Request $request): string
    {
        $range = $request->query('range', '1m');

        return in_array($range, ['1y', '6m', '1m', '1w'], true) ? $range : '1m';
    }

    private function notificationCount(User $user): int
    {
        if ($user->role === 'teller') {
            return (int) Transaction::query()
                ->where('created_by', $user->id)
                ->whereIn('transaction_type', ['cash_in', 'send_money'])
                ->where('status', 'PENDING_CASHIER_CONFIRM')
                ->count();
        }

        return (int) Transaction::query()
            ->whereIn('transaction_type', ['cash_in', 'send_money'])
            ->where('status', 'PENDING_CASHIER_CONFIRM')
            ->count();
    }

    /**
     * @return array<int, string>
     */
    private function companies(): array
    {
        return Company::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,company:string,name:string,number:string|null,balance:string,is_fee_account:bool}>
     */
    private function accounts(): array
    {
        return Account::query()
            ->with('company')
            ->where('is_active', true)
            ->orderBy('account_name')
            ->get()
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'company' => $account->company?->name ?? 'Account',
                'name' => $account->account_name,
                'number' => $account->account_identifier,
                'balance' => Money::normalize($account->balance ?? 0),
                'is_fee_account' => (bool) $account->is_fee_account,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,holder:string,status:string,amount:string,issued_at:string}>
     */
    private function floats(User $user): array
    {
        return CashFloatAssignment::query()
            ->with('employee')
            ->when($user->role === 'teller', fn (Builder $query) => $query->where('employee_id', $user->id))
            ->whereIn('status', ['ACTIVE', 'PENDING_RECEIPT', 'PENDING_RECONCILIATION'])
            ->orderByRaw("CASE WHEN status = 'ACTIVE' THEN 0 WHEN status = 'PENDING_RECEIPT' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->limit($user->role === 'teller' ? 1 : 12)
            ->get()
            ->map(fn (CashFloatAssignment $float): array => [
                'id' => $float->id,
                'holder' => $float->employee?->full_name ?? $float->employee?->username ?? 'Teller',
                'status' => $float->status,
                'amount' => Money::normalize($float->current_balance ?? $float->total_amount ?? 0),
                'issued_at' => $float->created_at?->toDateTimeString() ?? '',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{labels:array<int, string>,cashIn:array<int, float>,cashOut:array<int, float>}
     */
    private function chart(User $user, string $range): array
    {
        [$buckets, $start, $monthly] = $this->buckets($range);
        $cashIn = array_fill_keys(array_keys($buckets), 0.0);
        $cashOut = array_fill_keys(array_keys($buckets), 0.0);

        $this->scopedTransactions($user)
            ->where('created_at', '>=', $start)
            ->get()
            ->each(function (Transaction $transaction) use (&$cashIn, &$cashOut, $monthly): void {
                $createdAt = $transaction->created_at instanceof Carbon ? $transaction->created_at : Carbon::parse($transaction->created_at);
                $key = $monthly ? $createdAt->format('Y-m') : $createdAt->format('Y-m-d');

                if (! array_key_exists($key, $cashIn)) {
                    return;
                }

                if (in_array($transaction->transaction_type, ['cash_in', 'send_money'], true)) {
                    $cashIn[$key] += (float) $transaction->amount;

                    return;
                }

                $cashOut[$key] += (float) $transaction->amount;
            });

        return [
            'labels' => array_values($buckets),
            'cashIn' => array_values($cashIn),
            'cashOut' => array_values($cashOut),
        ];
    }

    /**
     * @return array{0:array<string, string>,1:Carbon,2:bool}
     */
    private function buckets(string $range): array
    {
        $now = now();
        $buckets = [];

        if ($range === '1y' || $range === '6m') {
            $months = $range === '1y' ? 12 : 6;
            $start = $now->copy()->startOfMonth()->subMonths($months - 1);

            for ($i = $months - 1; $i >= 0; $i--) {
                $date = $now->copy()->startOfMonth()->subMonths($i);
                $buckets[$date->format('Y-m')] = $date->format('M Y');
            }

            return [$buckets, $start, true];
        }

        $days = $range === '1w' ? 7 : 30;
        $start = $now->copy()->startOfDay()->subDays($days - 1);

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $now->copy()->startOfDay()->subDays($i);
            $buckets[$date->format('Y-m-d')] = $date->format('M j');
        }

        return [$buckets, $start, false];
    }

    /**
     * @return array<int, array{id:int,type:string,label:string,amount:string,direction:string,time:string}>
     */
    private function recent(User $user): array
    {
        return $this->scopedTransactions($user)
            ->latest('created_at')
            ->limit(12)
            ->get()
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'type' => str_replace('_', ' ', $transaction->transaction_type),
                'label' => $this->transactionLabel($transaction),
                'amount' => Money::normalize($transaction->amount ?? 0),
                'direction' => in_array($transaction->transaction_type, ['cash_in', 'send_money'], true) ? 'in' : 'out',
                'time' => $transaction->created_at?->diffForHumans() ?? '',
            ])
            ->values()
            ->all();
    }

    /**
     * Cashier-only Cash In work queue. Physical denominations are intentionally
     * empty until the Cashier counts the cash during confirmation.
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
            ->limit(50)
            ->get()
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'amount' => Money::normalize($transaction->amount ?? 0),
                'customer_name' => $transaction->customer_name,
                'teller' => $transaction->creator?->full_name ?? $transaction->creator?->username ?? 'Admin',
                'creator_role' => $transaction->creator?->role,
                'settlement_amount' => $this->cashInSettlementAmount($transaction),
                'customer_fee' => Money::normalize($transaction->customer_fee ?? 0),
                'fee_payment_method' => $transaction->fee_payment_method,
                'received_denominations' => $transaction->received_denominations ?? [],
                'handoff_denominations' => $transaction->handoff_denominations ?? [],
                'change_denominations' => $transaction->change_denominations ?? [],
                'change_given' => Money::normalize($transaction->change_given ?? 0),
                'created_at' => $transaction->created_at?->toDateTimeString() ?? '',
            ])
            ->values()
            ->all();
    }

    private function cashInSettlementAmount(Transaction $transaction): string
    {
        $cashFee = $transaction->fee_payment_method === 'cash'
            ? (float) ($transaction->customer_fee ?? 0)
            : 0.0;

        return Money::normalize((float) ($transaction->amount ?? 0) + $cashFee);
    }

    private function scopedTransactions(User $user): Builder
    {
        return Transaction::query()
            ->with('account.company')
            ->when($user->role === 'teller', fn (Builder $query) => $query->where('created_by', $user->id));
    }

    private function transactionLabel(Transaction $transaction): string
    {
        $from = $this->accountLabel($transaction->account_id);
        $to = $this->accountLabel($transaction->to_account_id);

        if ($from !== null && $to !== null) {
            return "{$from} to {$to}";
        }

        return $from
            ?? $to
            ?? ($transaction->customer_name ?: ucfirst(str_replace('_', ' ', $transaction->transaction_type)));
    }

    private function accountLabel(?int $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }

        if (array_key_exists($accountId, $this->accountLabels)) {
            return $this->accountLabels[$accountId];
        }

        $account = Account::query()
            ->with('company')
            ->find($accountId);

        if ($account === null) {
            return null;
        }

        $company = $account->company?->name;
        $label = trim(($company ? "{$company} - " : '').$account->account_name);
        $this->accountLabels[$accountId] = $label;

        return $label;
    }
}
