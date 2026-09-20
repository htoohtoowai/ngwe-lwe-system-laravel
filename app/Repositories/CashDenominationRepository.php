<?php

namespace App\Repositories;

use App\Exceptions\InsufficientVaultDenominationException;
use App\Models\Branch;
use App\Models\BranchVaultDenominationBalance;
use App\Models\CashDenominationLog;
use App\Models\VaultDenominationBalance;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Physical cash denomination ledger.
 *
 * Main-vault stock is isolated by branch in
 * `branch_vault_denomination_balances`. The legacy
 * `vault_denomination_balances` table is kept as a Main Branch mirror so
 * existing setup/seeder checks continue to work during the branch migration.
 */
class CashDenominationRepository
{
    private const CREDIT_ENTRIES = ['vault_in', 'float_returned', 'adjustment'];

    private const DEBIT_ENTRIES = ['vault_out'];

    private ?int $mainBranchId = null;

    /**
     * Insert one log row per denomination and, when requested, atomically apply
     * the delta to the selected branch vault.
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
        ?string $batchId = null,
        ?string $movementType = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $destinationType = null,
        ?int $destinationId = null,
        bool $affectsMainVault = true,
        ?int $branchId = null,
    ): string {
        if (! in_array($entryType, array_merge(self::CREDIT_ENTRIES, self::DEBIT_ENTRIES), true)) {
            throw new \InvalidArgumentException("Invalid entry_type: {$entryType}");
        }

        $branchId = $this->resolveBranchId($branchId);
        $batchId ??= (string) Str::uuid();
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
            return $batchId;
        }

        DB::transaction(function () use (
            $branchId,
            $entryType,
            $rows,
            $createdBy,
            $floatId,
            $transactionId,
            $note,
            $batchId,
            $movementType,
            $sourceType,
            $sourceId,
            $destinationType,
            $destinationId,
            $affectsMainVault,
        ): void {
            $isCredit = in_array($entryType, self::CREDIT_ENTRIES, true);

            foreach ($rows as [$denom, $qty]) {
                CashDenominationLog::query()->create([
                    'branch_id' => $branchId,
                    'batch_id' => $batchId,
                    'entry_type' => $entryType,
                    'movement_type' => $movementType,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'destination_type' => $destinationType,
                    'destination_id' => $destinationId,
                    'affects_main_vault' => $affectsMainVault,
                    'denomination' => $denom,
                    'quantity' => $qty,
                    'float_id' => $floatId,
                    'transaction_id' => $transactionId,
                    'created_by' => $createdBy,
                    'note' => $note,
                ]);

                if (! $affectsMainVault) {
                    continue;
                }

                DB::table('branch_vault_denomination_balances')->insertOrIgnore([
                    'branch_id' => $branchId,
                    'denomination_id' => $denom,
                    'quantity' => 0,
                    'total_value' => 0,
                    'last_updated' => now(),
                ]);

                $balance = BranchVaultDenominationBalance::query()
                    ->where('branch_id', $branchId)
                    ->where('denomination_id', $denom)
                    ->lockForUpdate()
                    ->firstOrFail();

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

                if ($branchId === $this->mainBranchId()) {
                    $this->mirrorLegacyMainBalance($denom, $newQuantity);
                }
            }
        });

        return $batchId;
    }

    /**
     * @return array<int, int>
     */
    public function getVaultBalance(?int $branchId = null): array
    {
        $branchId = $this->resolveBranchId($branchId);

        $rows = BranchVaultDenominationBalance::query()
            ->where('branch_id', $branchId)
            ->pluck('quantity', 'denomination_id');

        $result = [];
        foreach (Money::supportedDenominations() as $denom) {
            $result[$denom] = (int) ($rows[$denom] ?? 0);
        }

        return $result;
    }

    /**
     * Denomination quantities sitting with PENDING_RECEIPT floats for one
     * branch. These notes are already removed from that branch vault by the
     * `vault_out` issue log, so this is diagnostic only.
     *
     * @return array<int, int>
     */
    public function getPendingReserved(?int $branchId = null): array
    {
        $branchId = $this->resolveBranchId($branchId);

        $rows = DB::table('cash_float_denominations as cfd')
            ->join('cash_float_assignments as cfa', 'cfa.id', '=', 'cfd.float_id')
            ->where('cfa.branch_id', $branchId)
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

    /** @return array<int, int> */
    public function getAvailableBalance(?int $branchId = null): array
    {
        return $this->getVaultBalance($branchId);
    }

    /** @return Collection<int, CashDenominationLog> */
    public function recentLogs(int $limit = 100, ?int $branchId = null): Collection
    {
        $branchId = $this->resolveBranchId($branchId);

        return CashDenominationLog::query()
            ->where('branch_id', $branchId)
            ->orderByDesc('created_at')
            ->limit(max(1, min($limit, 500)))
            ->get();
    }

    private function resolveBranchId(?int $branchId = null): int
    {
        if ($branchId !== null) {
            return $branchId;
        }

        $guard = Auth::guard();

        if (method_exists($guard, 'hasUser') && $guard->hasUser()) {
            $user = $guard->user();
            if ($user?->branch_id !== null) {
                return (int) $user->branch_id;
            }
        }

        return $this->mainBranchId();
    }

    private function mainBranchId(): int
    {
        return $this->mainBranchId ??= (int) Branch::query()
            ->where('code', Branch::MAIN_CODE)
            ->value('id');
    }

    private function mirrorLegacyMainBalance(int $denomination, int $quantity): void
    {
        $legacy = VaultDenominationBalance::query()->find($denomination);

        if ($legacy === null) {
            $legacy = VaultDenominationBalance::query()->create([
                'denomination_id' => $denomination,
                'quantity' => 0,
                'total_value' => 0,
            ]);
        }

        $legacy->quantity = $quantity;
        $legacy->total_value = $quantity * $denomination;
        $legacy->last_updated = now();
        $legacy->save();
    }
}
