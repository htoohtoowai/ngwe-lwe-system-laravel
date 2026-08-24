<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TRANSACTION_TYPES = [
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

    public function up(): void
    {
        Schema::table('vault_transactions', function (Blueprint $table): void {
            $table->enum('txn_type', self::TRANSACTION_TYPES)->change();
        });
    }

    public function down(): void
    {
        Schema::table('vault_transactions', function (Blueprint $table): void {
            $table->enum('txn_type', array_values(array_filter(
                self::TRANSACTION_TYPES,
                fn (string $type): bool => $type !== 'cash_out_change',
            )))->change();
        });
    }
};
