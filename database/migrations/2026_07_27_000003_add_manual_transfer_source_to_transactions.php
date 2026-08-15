<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('transactions', 'source_account_type')) {
                $table->string('source_account_type', 16)->nullable()->after('customer_phone');
            }

            if (! Schema::hasColumn('transactions', 'source_provider')) {
                $table->string('source_provider')->nullable()->after('source_account_type');
            }

            if (! Schema::hasColumn('transactions', 'source_account_number')) {
                $table->string('source_account_number')->nullable()->after('source_provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            foreach (['source_account_number', 'source_provider', 'source_account_type'] as $column) {
                if (Schema::hasColumn('transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
