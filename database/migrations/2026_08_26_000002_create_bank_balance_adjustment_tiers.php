<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_balance_adjustment_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('destination_company_id')->nullable()->constrained('companies')->nullOnDelete()->cascadeOnUpdate();
            $table->string('channel', 32)->default('any');
            $table->string('transfer_method', 32)->default('any');
            $table->boolean('is_same_location')->nullable();
            $table->decimal('amount_from', 18, 2);
            $table->decimal('amount_to', 18, 2);
            $table->string('adjustment_type', 16)->default('PERCENTAGE');
            $table->decimal('adjustment_value', 18, 4)->default(0);
            $table->decimal('fixed_extra_amount', 18, 2)->default(0);
            $table->decimal('minimum_adjustment', 18, 2)->nullable();
            $table->decimal('maximum_adjustment', 18, 2)->nullable();
            $table->text('source_note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active'], 'bank_balance_adj_company_active_index');
            $table->index(['destination_company_id', 'is_active'], 'bank_balance_adj_dest_active_index');
            $table->index(['amount_from', 'amount_to'], 'bank_balance_adj_amount_range_index');
            $table->index(['channel', 'transfer_method'], 'bank_balance_adj_lookup_index');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('bank_balance_adjustment_tier_id')
                ->nullable()
                ->after('additional_fee_amount')
                ->constrained('bank_balance_adjustment_tiers')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->decimal('bank_balance_adjustment_amount', 18, 2)
                ->default(0)
                ->after('bank_balance_adjustment_tier_id');
            $table->string('bank_transfer_method', 32)->nullable()->after('bank_balance_adjustment_amount');
            $table->string('bank_transfer_channel', 32)->nullable()->after('bank_transfer_method');
            $table->boolean('is_same_location')->nullable()->after('bank_transfer_channel');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bank_balance_adjustment_tier_id');
            $table->dropColumn([
                'bank_balance_adjustment_amount',
                'bank_transfer_method',
                'bank_transfer_channel',
                'is_same_location',
            ]);
        });

        Schema::dropIfExists('bank_balance_adjustment_tiers');
    }
};
