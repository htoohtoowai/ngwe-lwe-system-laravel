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
use App\Models\CashFloatAssignment;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\AccountRepository;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Throwable;

class RealtimeBroadcastService
{
    public function __construct(private readonly AccountRepository $accounts) {}

    public function balanceUpdated(): void
    {
        $this->dispatchSafely(fn () => BalanceUpdated::dispatch($this->activeAccountsPayload()));
    }

    public function transactionCreated(Transaction $transaction): void
    {
        $payload = $this->transactionPayload($transaction);

        $this->dispatchSafely(fn () => NewTransaction::dispatch($payload));

        if ($transaction->transaction_type === 'cash_in' && $transaction->status === 'PENDING_CASHIER_CONFIRM') {
            $this->dispatchSafely(fn () => CashInPending::dispatch($payload));
        }

        $this->balanceUpdated();
    }

    public function floatStatusChanged(CashFloatAssignment $cashFloat): void
    {
        $this->dispatchSafely(fn () => FloatStatusChanged::dispatch(
            $this->cashFloatPayload($cashFloat),
            (int) $cashFloat->employee_id,
        ));
    }

    public function ping(User $owner): void
    {
        $this->dispatchSafely(fn () => BroadcastPing::dispatch($owner->id, now()->toISOString()));
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
    private function activeAccountsPayload(): array
    {
        return AccountResource::collection($this->accounts->active())->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionPayload(Transaction $transaction): array
    {
        $transaction = $transaction->refresh()->load('creator');
        $payload = (new TransactionResource($transaction))->resolve();

        if ($transaction->transaction_type !== 'cash_in') {
            return $payload;
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
}
