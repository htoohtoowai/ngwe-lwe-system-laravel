<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            // The original column was an enum limited to four operation types.
            // A string keeps the schema portable between MySQL and SQLite while
            // allowing Send Money / Receive Money without DB-specific enum SQL.
            $table->string('transaction_type', 32)->change();
            $table->string('fee_mode', 24)->nullable()->after('fee_payment_method');
            $table->decimal('customer_total', 18, 2)->nullable()->after('customer_fee');
        });

        Schema::table('vault_transactions', function (Blueprint $table): void {
            // Existing code already emits additional audit movement names; using
            // a string removes MySQL enum drift and permits Send/Receive entries.
            $table->string('txn_type', 64)->change();
        });

        Schema::table('daily_summary', function (Blueprint $table): void {
            $table->decimal('total_send_money', 18, 2)->default(0)->after('total_cash_out');
            $table->decimal('total_receive_money', 18, 2)->default(0)->after('total_send_money');
        });

        Schema::table('daily_reconciliation_logs', function (Blueprint $table): void {
            $table->decimal('total_send_money', 18, 2)->default(0)->after('total_cash_out');
            $table->decimal('total_receive_money', 18, 2)->default(0)->after('total_send_money');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['fee_mode', 'customer_total']);
        });

        Schema::table('daily_summary', function (Blueprint $table): void {
            $table->dropColumn(['total_send_money', 'total_receive_money']);
        });

        Schema::table('daily_reconciliation_logs', function (Blueprint $table): void {
            $table->dropColumn(['total_send_money', 'total_receive_money']);
        });

        // Do not narrow transaction_type back to the legacy enum in down().
        // Existing Send/Receive rows would otherwise make rollback destructive.
    }
};
