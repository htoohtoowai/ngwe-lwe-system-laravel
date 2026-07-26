<?php

namespace App\Repositories;

use App\Models\VaultTransaction;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class VaultTransactionRepository
{
    public const TYPES = [
        'float_issue',
        'float_receipt',
        'float_reject',
        'cash_in',
        'cash_in_received',
        'cash_in_handoff',
        'cash_in_change',
        'cash_out',
        'cash_out_fee_received',
        'return_initiate',
        'return_confirm',
        'adjustment',
    ];

    /**
     * @param  array<int, int>  $denominations
     */
    public function recordBulk(
        string $txnType,
        array $denominations,
        int $performedBy,
        ?int $floatId = null,
        ?int $verifiedBy = null,
        ?int $transactionId = null,
        ?string $note = null,
    ): void {
        if (! in_array($txnType, self::TYPES, true)) {
            throw new InvalidArgumentException("Invalid vault transaction type: {$txnType}");
        }

        Money::denominationTotal($denominations);

        $rows = [];
        foreach ($denominations as $denomination => $quantity) {
            $denomination = (int) $denomination;
            $quantity = (int) $quantity;
            if ($quantity <= 0) {
                continue;
            }

            $rows[] = [
                'txn_type' => $txnType,
                'float_id' => $floatId,
                'denomination' => $denomination,
                'quantity' => $quantity,
                'transaction_id' => $transactionId,
                'performed_by' => $performedBy,
                'verified_by' => $verifiedBy,
                'note' => $note,
            ];
        }

        if ($rows === []) {
            return;
        }

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                VaultTransaction::query()->create($row);
            }
        });
    }

    public function paginateLog(
        ?string $txnType = null,
        ?int $floatId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $perPage = 50,
    ): LengthAwarePaginator {
        return VaultTransaction::query()
            ->with(['performer', 'verifier'])
            ->when($txnType !== null, fn ($query) => $query->where('txn_type', $txnType))
            ->when($floatId !== null, fn ($query) => $query->where('float_id', $floatId))
            ->when($dateFrom !== null, fn ($query) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($query) => $query->whereDate('created_at', '<=', $dateTo))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(max(1, min($perPage, 200)));
    }
}
