<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_denomination_logs', function (Blueprint $table): void {
            $table->uuid('batch_id')->nullable()->after('id')->index();
            $table->string('movement_type', 64)->nullable()->after('entry_type')->index();
            $table->string('source_type', 32)->nullable()->after('movement_type');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->string('destination_type', 32)->nullable()->after('source_id');
            $table->unsignedBigInteger('destination_id')->nullable()->after('destination_type');
            $table->boolean('affects_main_vault')->default(true)->after('destination_id')->index();
        });

        Schema::table('vault_transactions', function (Blueprint $table): void {
            $table->string('movement_type', 64)->nullable()->after('txn_type')->index();
            $table->string('source_type', 32)->nullable()->after('movement_type');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->string('destination_type', 32)->nullable()->after('source_id');
            $table->unsignedBigInteger('destination_id')->nullable()->after('destination_type');
        });
    }

    public function down(): void
    {
        Schema::table('vault_transactions', function (Blueprint $table): void {
            $table->dropIndex(['movement_type']);
            $table->dropColumn([
                'movement_type',
                'source_type',
                'source_id',
                'destination_type',
                'destination_id',
            ]);
        });

        Schema::table('cash_denomination_logs', function (Blueprint $table): void {
            $table->dropIndex(['batch_id']);
            $table->dropIndex(['movement_type']);
            $table->dropIndex(['affects_main_vault']);
            $table->dropColumn([
                'batch_id',
                'movement_type',
                'source_type',
                'source_id',
                'destination_type',
                'destination_id',
                'affects_main_vault',
            ]);
        });
    }
};
