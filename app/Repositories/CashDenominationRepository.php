<?php

namespace App\Repositories;

use App\Exceptions\InsufficientVaultDenominationException;
use App\Models\CashDenominationLog;
use App\Models\VaultDenominationBalance;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ports Python repositories/cash_denomination_repository.py.
 *
 * `entry_type` values:
 *   - vault_in / float_returned / adjustment: increment the main vault
 *   - vault_out: decrement the main vault
 */
class CashDenominationRepository
{
    private const CREDIT_ENTRIES = ['vault_in', 'float_returned', 'adjustment'];

    private const DEBIT_ENTRIES = ['vault_out'];

    /**
     * Insert one log row per denomination (skip qty=0) and atomically apply
     * the delta to `vault_denomination_balances`.
     *
     * @param  array<int, int>  $denominations
     */
    public function recordBulk(
        string $entryType,
        array $denominations,
        int $createdBy,
        ?int $floatId = null,
        ?int $transactionId = null,
        ?string $note = null,
    ): void {
        if (! in_array($entryType, array_merge(self::CREDIT_ENTRIES, self::DEBIT_ENTRIES), true)) {
            throw new \InvalidArgumentException("Invalid entry_type: {$entryType}");
        }

        $rows = [];
        foreach ($denominations as $denom => $qty) {
            $denom = (int) $denom;
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }
            $rows[] = [$denom, $qty];
        }

        if ($rows === []) {
            return;
        }

        DB::transaction(function () use ($entryType, $rows, $createdBy, $floatId, $transactionId, $note): void {
            $isCredit = in_array($entryType, self::CREDIT_ENTRIES, true);

            foreach ($rows as [$denom, $qty]) {
                CashDenominationLog::query()->create([
                    'entry_type' => $entryType,
                    'denomination' => $denom,
                    'quantity' => $qty,
                    'float_id' => $floatId,
                    'transaction_id' => $transactionId,
                    'created_by' => $createdBy,
                    'note' => $note,
                ]);

                $balance = VaultDenominationBalance::query()->find($denom);
                if ($balance === null) {
                    $balance = VaultDenominationBalance::query()->create([
                        'denomination_id' => $denom,
                        'quantity' => 0,
                        'total_value' => 0,
                    ]);
                }

                $delta = $isCredit ? $qty : -$qty;
                $newQuantity = ((int) $balance->quantity) + $delta;

                if ($newQuantity < 0) {
                    throw new InsufficientVaultDenominationException(
                        $denom,
                        (int) $balance->quantity,
                        $qty,
                    );
                }

                $balance->quantity = $newQuantity;
                $balance->total_value = $newQuantity * $denom;
                $balance->last_updated = now();
                $balance->save();
            }
        });
    }

    /**
     * Vault net balance from the log ledger, keyed by denomination.
     *
     * @return array<int, int>
     */
    public function getVaultBalance(): array
    {
        $rows = DB::table('cash_denomination_logs')
            ->selectRaw(
                'denomination, '
                ."SUM(CASE WHEN entry_type IN ('vault_in','float_returned','adjustment') THEN quantity "
                ."WHEN entry_type = 'vault_out' THEN -quantity ELSE 0 END) as net_qty"
            )
            ->groupBy('denomination')
            ->pluck('net_qty', 'denomination');

        $result = [];
        foreach (Money::supportedDenominations() as $denom) {
            $result[$denom] = (int) ($rows[$denom] ?? 0);
        }

        return $result;
    }

    /**
     * Denomination quantities sitting with PENDING_RECEIPT floats. These notes
     * are already removed from the main vault by the `vault_out` issue log, so
     * this is diagnostic only and must not be subtracted from availability.
     *
     * @return array<int, int>
     */
    public function getPendingReserved(): array
    {
        $rows = DB::table('cash_float_denominations as cfd')
            ->join('cash_float_assignments as cfa', 'cfa.id', '=', 'cfd.float_id')
            ->where('cfa.status', 'PENDING_RECEIPT')
            ->groupBy('cfd.denomination')
            ->selectRaw('cfd.denomination, SUM(cfd.quantity) as total_qty')
            ->pluck('total_qty', 'cfd.denomination');

        $result = [];
        foreach (Money::supportedDenominations() as $denom) {
            $result[$denom] = (int) ($rows[$denom] ?? 0);
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    public function getAvailableBalance(): array
    {
        return $this->getVaultBalance();
    }

    /**
     * @return Collection<int, CashDenominationLog>
     */
    public function recentLogs(int $limit = 100): Collection
    {
        return CashDenominationLog::query()
            ->orderByDesc('created_at')
            ->limit(max(1, min($limit, 500)))
            ->get();
    }
}
