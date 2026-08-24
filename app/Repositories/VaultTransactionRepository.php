<?php

namespace App\Repositories;

use App\Models\CashDenominationLog;
use App\Models\VaultTransaction;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        'cash_out_change',
        'transfer_fee_received',
        'return_initiate',
        'return_confirm',
        'adjustment',
    ];

    /**
     * Record one logical vault/cash movement as denomination-level immutable rows.
     * The optional batch id is shared with `cash_denomination_logs` so the two
     * ledgers can be reconciled exactly.
     *
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
        ?string $batchId = null,
        ?string $movementType = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $destinationType = null,
        ?int $destinationId = null,
    ): string {
        if (! in_array($txnType, self::TYPES, true)) {
            throw new InvalidArgumentException("Invalid vault transaction type: {$txnType}");
        }

        Money::denominationTotal($denominations);

        $batchId ??= (string) Str::uuid();
        $rows = [];
        foreach ($denominations as $denomination => $quantity) {
            $denomination = (int) $denomination;
            $quantity = (int) $quantity;
            if ($quantity <= 0) {
                continue;
            }

            $rows[] = [
                'batch_id' => $batchId,
                'txn_type' => $txnType,
                'movement_type' => $movementType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'destination_type' => $destinationType,
                'destination_id' => $destinationId,
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
            return $batchId;
        }

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                VaultTransaction::query()->create($row);
            }
        });

        return $batchId;
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

    /**
     * Logical transaction rows plus an automatic cross-check against the physical
     * denomination ledger. MATCHED requires the same batch, movement direction,
     * references, denomination quantities and total amount in both ledgers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function groupedLog(int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));

        /** @var Collection<int, VaultTransaction> $ledgerRows */
        $ledgerRows = VaultTransaction::query()
            ->with(['performer', 'verifier'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit * 20)
            ->get();

        $batchIds = $ledgerRows
            ->pluck('batch_id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values();

        $cashByBatch = $batchIds->isEmpty()
            ? collect()
            : CashDenominationLog::query()
                ->whereIn('batch_id', $batchIds->all())
                ->orderByDesc('denomination')
                ->get()
                ->groupBy('batch_id');

        return $ledgerRows
            ->groupBy(fn (VaultTransaction $row): string => $this->groupKey($row))
            ->take($limit)
            ->map(function (Collection $group) use ($cashByBatch): array {
                /** @var VaultTransaction $first */
                $first = $group->first();
                $details = $this->vaultDetails($group);

                /** @var Collection<int, CashDenominationLog> $cashRows */
                $cashRows = $first->batch_id
                    ? ($cashByBatch->get($first->batch_id) ?? collect())
                    : collect();
                $cashDetails = $this->cashDetails($cashRows);

                [$status, $issues] = $this->reconcile($first, $details, $cashRows, $cashDetails);

                return [
                    'id' => $first->id,
                    'batch_id' => $first->batch_id,
                    'txn_type' => $first->txn_type,
                    'movement_type' => $first->movement_type,
                    'source_type' => $first->source_type,
                    'source_id' => $first->source_id,
                    'destination_type' => $first->destination_type,
                    'destination_id' => $first->destination_id,
                    'float_id' => $first->float_id,
                    'transaction_id' => $first->transaction_id,
                    'performed_by' => $first->performed_by,
                    'performed_by_name' => $first->performer?->full_name ?? $first->performer?->username,
                    'verified_by' => $first->verified_by,
                    'verified_by_name' => $first->verifier?->full_name ?? $first->verifier?->username,
                    'note' => $first->note,
                    'created_at' => $first->created_at?->toISOString(),
                    'total_amount' => (int) $details->sum('amount'),
                    'denomination_count' => $details->count(),
                    'details' => $details->all(),
                    'cash_total_amount' => (int) $cashDetails->sum('amount'),
                    'cash_log_count' => $cashRows->count(),
                    'cash_details' => $cashDetails->all(),
                    'reconciliation_status' => $status,
                    'reconciliation_issues' => $issues,
                ];
            })
            ->values()
            ->all();
    }

    private function groupKey(VaultTransaction $row): string
    {
        if ($row->batch_id !== null && $row->batch_id !== '') {
            return 'batch:'.$row->batch_id;
        }

        return 'legacy:'.hash('sha256', implode('|', [
            $row->txn_type,
            (string) ($row->float_id ?? ''),
            (string) ($row->transaction_id ?? ''),
            (string) $row->performed_by,
            (string) ($row->verified_by ?? ''),
            (string) ($row->note ?? ''),
            $row->created_at?->format('Y-m-d H:i:s') ?? '',
        ]));
    }

    /** @return Collection<int, array{id:int, denomination:int, quantity:int, amount:int}> */
    private function vaultDetails(Collection $group): Collection
    {
        return $group
            ->map(fn (VaultTransaction $row): array => [
                'id' => $row->id,
                'denomination' => (int) $row->denomination,
                'quantity' => (int) $row->quantity,
                'amount' => (int) $row->denomination * (int) $row->quantity,
            ])
            ->sortByDesc('denomination')
            ->values();
    }

    /** @return Collection<int, array{id:int, denomination:int, quantity:int, amount:int, affects_main_vault:bool}> */
    private function cashDetails(Collection $rows): Collection
    {
        return $rows
            ->map(fn (CashDenominationLog $row): array => [
                'id' => $row->id,
                'denomination' => (int) $row->denomination,
                'quantity' => (int) $row->quantity,
                'amount' => (int) $row->denomination * (int) $row->quantity,
                'affects_main_vault' => (bool) $row->affects_main_vault,
            ])
            ->sortByDesc('denomination')
            ->values();
    }

    /** @return array{0:string,1:array<int,string>} */
    private function reconcile(
        VaultTransaction $vault,
        Collection $vaultDetails,
        Collection $cashRows,
        Collection $cashDetails,
    ): array {
        $movementType = (string) ($vault->movement_type ?? '');

        if (str_starts_with($movementType, 'verification_')) {
            return ['not_applicable', []];
        }

        if ($movementType === '') {
            return ['legacy_unlinked', ['This record predates shared cash movement references.']];
        }

        if ($cashRows->isEmpty()) {
            return ['missing_cash_log', ['No cash_denomination_logs rows share this batch id.']];
        }

        /** @var CashDenominationLog $cashFirst */
        $cashFirst = $cashRows->first();
        $issues = [];

        foreach ([
            'movement_type' => [$vault->movement_type, $cashFirst->movement_type],
            'source_type' => [$vault->source_type, $cashFirst->source_type],
            'source_id' => [$vault->source_id, $cashFirst->source_id],
            'destination_type' => [$vault->destination_type, $cashFirst->destination_type],
            'destination_id' => [$vault->destination_id, $cashFirst->destination_id],
            'float_id' => [$vault->float_id, $cashFirst->float_id],
            'transaction_id' => [$vault->transaction_id, $cashFirst->transaction_id],
        ] as $field => [$vaultValue, $cashValue]) {
            if ((string) ($vaultValue ?? '') !== (string) ($cashValue ?? '')) {
                $issues[] = "{$field} does not match.";
            }
        }

        if ($this->denominationMap($vaultDetails) !== $this->denominationMap($cashDetails)) {
            $issues[] = 'Denomination quantities do not match.';
        }

        if ((int) $vaultDetails->sum('amount') !== (int) $cashDetails->sum('amount')) {
            $issues[] = 'Total amount does not match.';
        }

        return $issues === [] ? ['matched', []] : ['mismatch', $issues];
    }

    /** @return array<int, int> */
    private function denominationMap(Collection $details): array
    {
        $map = [];
        foreach ($details as $detail) {
            $denomination = (int) $detail['denomination'];
            $map[$denomination] = ($map[$denomination] ?? 0) + (int) $detail['quantity'];
        }
        krsort($map);

        return $map;
    }
}
