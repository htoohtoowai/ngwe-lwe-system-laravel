<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('cashier_branch_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->unsignedTinyInteger('admin_guard')->nullable()->after('cashier_branch_id');

            $table->unique('cashier_branch_id', 'users_cashier_branch_unique');
            $table->unique('admin_guard', 'users_single_admin_unique');
        });

        DB::table('users')
            ->where('role', 'cashier')
            ->update(['cashier_branch_id' => DB::raw('branch_id')]);

        DB::table('users')
            ->where('role', 'admin')
            ->update(['admin_guard' => 1]);

        Schema::create('branch_vault_denomination_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->unsignedInteger('denomination_id');
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedBigInteger('total_value')->default(0);
            $table->timestamp('last_updated')->useCurrent();

            $table->foreign('denomination_id')
                ->references('id')
                ->on('note_denominations')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->unique(['branch_id', 'denomination_id'], 'branch_vault_branch_denom_unique');
            $table->index('branch_id');
        });

        $this->backfillBranchVaultBalances();
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_vault_denomination_balances');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_cashier_branch_unique');
            $table->dropUnique('users_single_admin_unique');
            $table->dropConstrainedForeignId('cashier_branch_id');
            $table->dropColumn('admin_guard');
        });
    }

    private function backfillBranchVaultBalances(): void
    {
        $netRows = DB::table('cash_denomination_logs')
            ->where('affects_main_vault', true)
            ->select(['branch_id', 'denomination'])
            ->selectRaw(
                "SUM(CASE WHEN entry_type IN ('vault_in','float_returned','adjustment') THEN quantity "
                ."WHEN entry_type = 'vault_out' THEN -quantity ELSE 0 END) AS net_qty"
            )
            ->groupBy('branch_id', 'denomination')
            ->get();

        $net = [];
        foreach ($netRows as $row) {
            $branchId = (int) $row->branch_id;
            $denomination = (int) $row->denomination;
            $quantity = (int) $row->net_qty;

            if ($quantity < 0) {
                throw new \RuntimeException(
                    "Cannot migrate branch {$branchId} vault denomination {$denomination}: calculated quantity is negative ({$quantity})."
                );
            }

            $net[$branchId][$denomination] = $quantity;
        }

        $branchIds = DB::table('branches')->orderBy('id')->pluck('id');
        $denominations = DB::table('note_denominations')->orderBy('id')->pluck('id');

        foreach ($branchIds as $branchId) {
            foreach ($denominations as $denomination) {
                $branchId = (int) $branchId;
                $denomination = (int) $denomination;
                $quantity = (int) ($net[$branchId][$denomination] ?? 0);

                DB::table('branch_vault_denomination_balances')->insert([
                    'branch_id' => $branchId,
                    'denomination_id' => $denomination,
                    'quantity' => $quantity,
                    'total_value' => $quantity * $denomination,
                    'last_updated' => now(),
                ]);
            }
        }
    }
};
