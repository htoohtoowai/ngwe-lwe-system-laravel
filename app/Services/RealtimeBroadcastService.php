<?php

namespace App\Services;

use App\Events\BalanceUpdated;
use App\Events\BroadcastPing;
use App\Events\CashInPending;
use App\Events\FloatStatusChanged;
use App\Events\NewTransaction;
use App\Http\Resources\AccountResource;
use App\Http\Resources\CashFloatResource;
use App\Http\Resources\TransactionResource;
use App\Models\Account;
use App\Models\CashFloatAssignment;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\AccountRepository;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class RealtimeBroadcastService
{
    public function __construct(private readonly AccountRepository $accounts) {}

    public function balanceUpdated(?int $branchId = null): void
    {
        $branchId ??= $this->authenticatedBranchId();
        $accounts = $this->activeAccountsPayload($branchId);

        $this->dispatchSafely(
            fn () => BalanceUpdated::dispatch($accounts, $branchId)
        );
    }

    public function transactionCreated(Transaction $transaction): void
    {
        $payload = $this->transactionPayload($transaction);
        $branchId = (int) $transaction->branch_id;

        $this->dispatchSafely(
            fn () => NewTransaction::dispatch($payload, $branchId)
        );

        if (
            in_array($transaction->transaction_type, ['cash_in', 'send_money'], true)
            && $transaction->status === 'PENDING_CASHIER_CONFIRM'
        ) {
            $this->dispatchSafely(
                fn () => CashInPending::dispatch($payload, $branchId)
            );
        }

        $this->balanceUpdated($branchId);
    }

    public function floatStatusChanged(CashFloatAssignment $cashFloat): void
    {
        $this->dispatchSafely(fn () => FloatStatusChanged::dispatch(
            $this->cashFloatPayload($cashFloat),
            (int) $cashFloat->employee_id,
            (int) $cashFloat->branch_id,
        ));
    }

    public function ping(User $owner): void
    {
        $this->dispatchSafely(
            fn () => BroadcastPing::dispatch($owner->id, now()->toISOString())
        );
    }

    private function dispatchSafely(\Closure $dispatch): void
    {
        try {
            $dispatch();
        } catch (Throwable $exception) {
            Log::warning('Realtime broadcast failed.', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activeAccountsPayload(?int $branchId = null): array
    {
        if ($branchId === null) {
            return AccountResource::collection($this->accounts->active())->resolve();
        }

        $accounts = Account::query()
            ->withoutGlobalScopes()
            ->where('is_active', true)
            ->whereHas(
                'company',
                fn (Builder $query) => $query->where('is_active', true),
            )
            ->where(function (Builder $query) use ($branchId): void {
                $query->whereNull('branch_id')
                    ->orWhere('branch_id', $branchId);
            })
            ->with(['company', 'featureAssignments'])
            ->orderBy('account_name')
            ->get();

        return AccountResource::collection($accounts)->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionPayload(Transaction $transaction): array
    {
        $transaction = $transaction
            ->refresh()
            ->load([
                'creator',
                'agentCommissionEntries.account',
                'agentCommissionEntries.company',
            ]);

        $payload = (new TransactionResource($transaction))->resolve();

        if (! in_array($transaction->transaction_type, ['cash_in', 'send_money'], true)) {
            return $payload;
        }

        if ($transaction->transaction_type === 'send_money') {
            return array_merge($payload, [
                'teller' => $transaction->creator?->full_name
                    ?? $transaction->creator?->username
                    ?? 'Teller',
                'creator_role' => $transaction->creator?->role,
                'settlement_amount' => Money::normalize(
                    $transaction->customer_total ?? $transaction->amount ?? 0
                ),
            ]);
        }

        $settlementDenominations = $transaction->creator?->role === 'teller'
            ? ($transaction->handoff_denominations ?? [])
            : ($transaction->received_denominations ?? []);

        return array_merge($payload, [
            'teller' => $transaction->creator?->full_name
                ?? $transaction->creator?->username
                ?? 'Teller',
            'creator_role' => $transaction->creator?->role,
            'settlement_amount' => Money::normalize(
                Money::denominationTotal($settlementDenominations),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function cashFloatPayload(CashFloatAssignment $cashFloat): array
    {
        return (new CashFloatResource(
            $cashFloat->refresh()->load(['denominations', 'employee', 'issuer'])
        ))->resolve();
    }

    private function authenticatedBranchId(): ?int
    {
        $user = Auth::user();

        if ($user === null || $user->role === 'admin' || $user->branch_id === null) {
            return null;
        }

        return (int) $user->branch_id;
    }
}
