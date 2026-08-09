<?php

use App\Enums\AccountFeature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts', 'company_id')) {
                $table->foreignId('company_id')
                    ->nullable()
                    ->after('id')
                    ->constrained()
                    ->nullOnDelete()
                    ->cascadeOnUpdate();
            }
        });

        DB::statement(<<<'SQL'
            UPDATE accounts a
            INNER JOIN service_types st ON st.id = a.service_type_id
            SET a.company_id = st.company_id
            WHERE a.company_id IS NULL
        SQL);

        if (! Schema::hasTable('account_features')) {
            Schema::create('account_features', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
                $table->string('feature', 32);
                $table->timestamps();
                $table->unique(['account_id', 'feature']);
                $table->index('feature');
            });
        }

        $this->backfillAccountFeatures();

        Schema::table('commission_tiers', function (Blueprint $table) {
            if (! Schema::hasColumn('commission_tiers', 'company_id')) {
                $table->foreignId('company_id')
                    ->nullable()
                    ->after('id')
                    ->constrained()
                    ->nullOnDelete()
                    ->cascadeOnUpdate();
            }
            if (! Schema::hasColumn('commission_tiers', 'feature')) {
                $table->string('feature', 32)->nullable()->after('company_id');
                $table->index(['company_id', 'feature', 'is_active'], 'commission_tiers_company_feature_active_index');
            }
            if (! Schema::hasColumn('commission_tiers', 'fee_type')) {
                $table->string('fee_type', 16)->default('FIXED')->after('amount_to');
            }
            if (! Schema::hasColumn('commission_tiers', 'fee_amount')) {
                $table->decimal('fee_amount', 18, 4)->default(0)->after('fee_type');
            }
            if (! Schema::hasColumn('commission_tiers', 'additional_fee_amount')) {
                $table->decimal('additional_fee_amount', 18, 4)->default(0)->after('additional_fee_type');
            }
            if (! Schema::hasColumn('commission_tiers', 'comm_amount')) {
                $table->decimal('comm_amount', 18, 4)->default(0)->after('comm_type');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE commission_tiers MODIFY service_type_id BIGINT UNSIGNED NULL');
        }

        $this->backfillCommissionTierFeatureColumns();

        if (! Schema::hasTable('transfer_fee_tiers')) {
            Schema::create('transfer_fee_tiers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_from_id')->constrained('companies')->restrictOnDelete()->cascadeOnUpdate();
                $table->foreignId('company_to_id')->constrained('companies')->restrictOnDelete()->cascadeOnUpdate();
                $table->decimal('amount_from', 18, 2);
                $table->decimal('amount_to', 18, 2);
                $table->string('fee_type', 16)->default('FIXED');
                $table->decimal('fee_amount', 18, 4)->default(0);
                $table->string('additional_fee_type', 16)->default('FIXED');
                $table->decimal('additional_fee_amount', 18, 4)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['company_from_id', 'company_to_id', 'is_active'], 'transfer_fee_tiers_company_active_index');
                $table->index(['amount_from', 'amount_to']);
            });
        }

        Schema::table('exchange_rates', function (Blueprint $table) {
            if (! Schema::hasColumn('exchange_rates', 'company_id')) {
                $table->foreignId('company_id')
                    ->nullable()
                    ->after('id')
                    ->constrained()
                    ->nullOnDelete()
                    ->cascadeOnUpdate();
                $table->index(['company_id', 'base_currency', 'quote_currency'], 'exchange_rates_company_currency_index');
            }
            if (! Schema::hasColumn('exchange_rates', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('sell_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            if (Schema::hasColumn('exchange_rates', 'company_id')) {
                $table->dropIndex('exchange_rates_company_currency_index');
                $table->dropConstrainedForeignId('company_id');
            }
            if (Schema::hasColumn('exchange_rates', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });

        Schema::dropIfExists('transfer_fee_tiers');

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE commission_tiers MODIFY service_type_id BIGINT UNSIGNED NOT NULL');
        }

        Schema::table('commission_tiers', function (Blueprint $table) {
            if (Schema::hasColumn('commission_tiers', 'feature')) {
                $table->dropIndex('commission_tiers_company_feature_active_index');
            }
            foreach (['feature', 'fee_type', 'fee_amount', 'additional_fee_amount', 'comm_amount'] as $column) {
                if (Schema::hasColumn('commission_tiers', $column)) {
                    $table->dropColumn($column);
                }
            }
            if (Schema::hasColumn('commission_tiers', 'company_id')) {
                $table->dropConstrainedForeignId('company_id');
            }
        });

        Schema::dropIfExists('account_features');

        Schema::table('accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounts', 'company_id')) {
                $table->dropConstrainedForeignId('company_id');
            }
        });
    }

    private function backfillAccountFeatures(): void
    {
        $now = now();
        $rows = DB::table('accounts')
            ->join('service_types', 'service_types.id', '=', 'accounts.service_type_id')
            ->select('accounts.id as account_id', 'service_types.name', 'service_types.operation')
            ->get();

        foreach ($rows as $row) {
            foreach (AccountFeature::fromLegacy($row->operation, $row->name) as $feature) {
                DB::table('account_features')->insertOrIgnore([
                    'account_id' => $row->account_id,
                    'feature' => $feature->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function backfillCommissionTierFeatureColumns(): void
    {
        $rows = DB::table('commission_tiers')
            ->join('service_types', 'service_types.id', '=', 'commission_tiers.service_type_id')
            ->select(
                'commission_tiers.id',
                'service_types.company_id',
                'service_types.name',
                'service_types.operation',
                'commission_tiers.fee_amount_type',
                'commission_tiers.fee_amount_deposit',
                'commission_tiers.fee_amount_withdraw',
                'commission_tiers.additional_fee_deposit_amount',
                'commission_tiers.additional_fee_withdraw_amount',
                'commission_tiers.comm_deposit',
                'commission_tiers.comm_withdraw',
            )
            ->get();

        foreach ($rows as $row) {
            $feature = AccountFeature::fromLegacy($row->operation, $row->name)[0] ?? AccountFeature::CashIn;
            $usesWithdrawSide = in_array($feature, [AccountFeature::CashOut, AccountFeature::ReceiveMoney], true);

            DB::table('commission_tiers')
                ->where('id', $row->id)
                ->update([
                    'company_id' => $row->company_id,
                    'feature' => $feature->value,
                    'fee_type' => $row->fee_amount_type,
                    'fee_amount' => $usesWithdrawSide ? $row->fee_amount_withdraw : $row->fee_amount_deposit,
                    'additional_fee_amount' => $usesWithdrawSide
                        ? $row->additional_fee_withdraw_amount
                        : $row->additional_fee_deposit_amount,
                    'comm_amount' => $usesWithdrawSide ? $row->comm_withdraw : $row->comm_deposit,
                ]);
        }
    }
};
