<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('balance_adjustment_requests', function (Blueprint $table): void {
            $table->foreignId('approver_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->index(['approver_id', 'status']);
        });

        DB::table('balance_adjustment_requests')
            ->whereNull('approver_id')
            ->update([
                'approver_id' => DB::raw('assigned_cashier_id'),
            ]);
    }

    public function down(): void
    {
        Schema::table('balance_adjustment_requests', function (Blueprint $table): void {
            $table->dropIndex(['approver_id', 'status']);
            $table->dropColumn('approved_at');
            $table->dropConstrainedForeignId('approver_id');
        });
    }
};
