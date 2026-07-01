<?php

namespace App\Repositories;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class TransactionRepository
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Transaction
    {
        return Transaction::query()->create($data)->refresh();
    }

    public function find(int $id): ?Transaction
    {
        return Transaction::query()->find($id);
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function filter(
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $type = null,
        ?int $accountId = null,
        int $limit = 200,
    ): Collection {
        return Transaction::query()
            ->when($dateFrom !== null, fn ($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->when($type !== null, fn ($q) => $q->where('transaction_type', $type))
            ->when($accountId !== null, function ($q) use ($accountId): void {
                $q->where(function ($sub) use ($accountId): void {
                    $sub->where('account_id', $accountId)
                        ->orWhere('to_account_id', $accountId);
                });
            })
            ->orderByDesc('created_at')
            ->limit(min($limit, 1000))
            ->get();
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function recentForUser(User $user, int $limit = 50): Collection
    {
        return Transaction::query()
            ->where('created_by', $user->id)
            ->orderByDesc('created_at')
            ->limit(min($limit, 1000))
            ->get();
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function recent(int $limit = 50): Collection
    {
        return Transaction::query()
            ->orderByDesc('created_at')
            ->limit(min($limit, 1000))
            ->get();
    }

    public function confirmPendingCashIn(Transaction $transaction, int $cashierId): ?Transaction
    {
        $affected = Transaction::query()
            ->where('id', $transaction->id)
            ->where('transaction_type', 'cash_in')
            ->where('status', 'PENDING_CASHIER_CONFIRM')
            ->update([
                'status' => 'COMPLETED',
                'vault_impact' => 'main_vault_increase',
                'confirmed_by' => $cashierId,
                'confirmed_at' => now(),
                'cash_approved_by' => $cashierId,
                'cash_approved_at' => now(),
            ]);

        return $affected > 0 ? $transaction->refresh() : null;
    }

    public function cancelPendingCashIn(Transaction $transaction, int $cashierId, ?string $note = null): ?Transaction
    {
        $update = [
            'status' => 'CANCELLED',
            'vault_impact' => 'none',
            'confirmed_by' => $cashierId,
            'confirmed_at' => now(),
        ];

        if ($note !== null && $note !== '') {
            $update['note'] = $note;
        }

        $affected = Transaction::query()
            ->where('id', $transaction->id)
            ->where('transaction_type', 'cash_in')
            ->where('status', 'PENDING_CASHIER_CONFIRM')
            ->update($update);

        return $affected > 0 ? $transaction->refresh() : null;
    }
}
