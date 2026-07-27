<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('transactions', 'destination_provider')) {
                $table->string('destination_provider')->nullable()->after('source_account_number');
            }

            if (! Schema::hasColumn('transactions', 'destination_account_number')) {
                $table->string('destination_account_number')->nullable()->after('destination_provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            foreach (['destination_account_number', 'destination_provider'] as $column) {
                if (Schema::hasColumn('transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
