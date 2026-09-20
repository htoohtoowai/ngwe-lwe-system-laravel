<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });

        $mainBranchId = (int) DB::table('branches')->insertGetId([
            'code' => 'MAIN',
            'name' => 'Main Branch',
            'address' => null,
            'phone' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('role')
                ->constrained('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['branch_id', 'role', 'is_active']);
        });

        DB::table('users')
            ->whereIn('role', ['cashier', 'teller'])
            ->whereNull('branch_id')
            ->update(['branch_id' => $mainBranchId]);

        Schema::table('accounts', function (Blueprint $table): void {
            // NULL means the provider account is shared by all branches.
            $table->foreignId('branch_id')
                ->nullable()
                ->after('company_id')
                ->constrained('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['branch_id', 'is_active']);
        });

        Schema::table('transactions', function (Blueprint $table) use ($mainBranchId): void {
            $table->foreignId('branch_id')
                ->default($mainBranchId)
                ->after('id')
                ->constrained('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['branch_id', 'created_at']);
            $table->index(['branch_id', 'status']);
        });

        Schema::table('cash_float_assignments', function (Blueprint $table) use ($mainBranchId): void {
            $table->foreignId('branch_id')
                ->default($mainBranchId)
                ->after('id')
                ->constrained('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['branch_id', 'status']);
        });

        Schema::table('cash_float_issues', function (Blueprint $table) use ($mainBranchId): void {
            $table->foreignId('branch_id')
                ->default($mainBranchId)
                ->after('id')
                ->constrained('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['branch_id', 'status']);
        });

        Schema::table('vault_transactions', function (Blueprint $table) use ($mainBranchId): void {
            $table->foreignId('branch_id')
                ->default($mainBranchId)
                ->after('id')
                ->constrained('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['branch_id', 'created_at']);
        });

        Schema::table('cash_denomination_logs', function (Blueprint $table) use ($mainBranchId): void {
            $table->foreignId('branch_id')
                ->default($mainBranchId)
                ->after('id')
                ->constrained('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['branch_id', 'created_at']);
        });

        Schema::table('daily_summary', function (Blueprint $table) use ($mainBranchId): void {
            $table->foreignId('branch_id')
                ->default($mainBranchId)
                ->after('id')
                ->constrained('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->dropUnique(['summary_date']);
            $table->unique(['branch_id', 'summary_date'], 'daily_summary_branch_date_unique');
        });

        Schema::table('daily_reconciliation_logs', function (Blueprint $table) use ($mainBranchId): void {
            $table->foreignId('branch_id')
                ->default($mainBranchId)
                ->after('id')
                ->constrained('branches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['branch_id', 'recon_date']);
        });
    }

    public function down(): void
    {
        Schema::table('daily_reconciliation_logs', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'recon_date']);
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('daily_summary', function (Blueprint $table): void {
            $table->dropUnique('daily_summary_branch_date_unique');
            $table->dropConstrainedForeignId('branch_id');
            $table->unique('summary_date');
        });

        Schema::table('cash_denomination_logs', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'created_at']);
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('vault_transactions', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'created_at']);
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('cash_float_issues', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'status']);
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('cash_float_assignments', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'status']);
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'created_at']);
            $table->dropIndex(['branch_id', 'status']);
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'is_active']);
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'role', 'is_active']);
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::dropIfExists('branches');
    }
};
