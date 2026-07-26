<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('transactions', 'fee_payment_method')) {
                $table->string('fee_payment_method', 16)->default('cash')->after('fee_account_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            if (Schema::hasColumn('transactions', 'fee_payment_method')) {
                $table->dropColumn('fee_payment_method');
            }
        });
    }
};
