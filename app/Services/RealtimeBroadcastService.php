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

class RealtimeBroadcastService
{
    public function __construct(private readonly AccountRepository $accounts) {}

    public function balanceUpdated(): void
    {
        BalanceUpdated::dispatch($this->activeAccountsPayload());
    }

    public function transactionCreated(Transaction $transaction): void
    {
        $payload = $this->transactionPayload($transaction);

        NewTransaction::dispatch($payload);

        if ($transaction->transaction_type === 'cash_in' && $transaction->status === 'PENDING_CASHIER_CONFIRM') {
            CashInPending::dispatch($payload);
        }

        $this->balanceUpdated();
    }

    public function floatStatusChanged(CashFloatAssignment $cashFloat): void
    {
        FloatStatusChanged::dispatch(
            $this->cashFloatPayload($cashFloat),
            (int) $cashFloat->employee_id,
        );
    }

    public function ping(User $owner): void
    {
        BroadcastPing::dispatch($owner->id, now()->toISOString());
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
        return (new TransactionResource($transaction->refresh()))->resolve();
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
