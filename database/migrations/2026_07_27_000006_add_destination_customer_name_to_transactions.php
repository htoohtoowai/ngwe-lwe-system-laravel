<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transactions', 'fee_payment_method')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('transactions', 'destination_customer_name')) {
                $table->string('destination_customer_name')->nullable()->after('destination_provider');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('transactions', 'fee_payment_method')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table): void {
            if (Schema::hasColumn('transactions', 'destination_customer_name')) {
                $table->dropColumn('destination_customer_name');
            }
        });
    }
};
