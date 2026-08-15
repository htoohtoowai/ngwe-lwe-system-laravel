<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('transactions', 'receive_commission_amount')) {
                $table->decimal('receive_commission_amount', 18, 2)->default(0)->after('commission_amount');
            }

            if (! Schema::hasColumn('transactions', 'payout_commission_amount')) {
                $table->decimal('payout_commission_amount', 18, 2)->default(0)->after('receive_commission_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            foreach (['payout_commission_amount', 'receive_commission_amount'] as $column) {
                if (Schema::hasColumn('transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
