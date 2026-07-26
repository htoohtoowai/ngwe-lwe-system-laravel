<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'username')) {
                $table->string('username')->unique()->after('id');
            }
            if (! Schema::hasColumn('users', 'pin_hash')) {
                $table->string('pin_hash')->nullable()->after('password');
            }
            if (! Schema::hasColumn('users', 'full_name')) {
                $table->string('full_name')->after('pin_hash');
            }
            if (! Schema::hasColumn('users', 'role')) {
                $table->enum('role', ['admin', 'teller', 'cashier'])->default('teller')->after('full_name');
            }
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('role');
            }
            if (! Schema::hasColumn('users', 'auth_version')) {
                $table->unsignedInteger('auth_version')->default(0)->after('is_active');
            }
        });

        Schema::create('schema_version', function (Blueprint $table) {
            $table->unsignedInteger('version')->primary();
            $table->timestamp('applied_at')->useCurrent();
            $table->string('description')->nullable();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('logo_path')->nullable();
            $table->enum('category', ['Pay', 'Bank', 'Both'])->default('Pay');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->string('name');
            $table->enum('operation', ['CashIn', 'CashOut', 'Transfer', 'Exchange', 'All']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_type_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->string('account_name');
            $table->string('phone_number');
            $table->decimal('balance', 18, 2)->default(0);
            $table->decimal('commission_rate', 10, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_fee_account')->default(false);
            $table->timestamps();
            $table->index('service_type_id');
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->enum('transaction_type', ['cash_in', 'cash_out', 'transfer', 'exchange']);
            $table->foreignId('account_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('to_account_id')->nullable()->constrained('accounts')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('from_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('to_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('commission_amount', 18, 2)->default(0);
            $table->decimal('customer_fee', 18, 2)->default(0);
            $table->decimal('additional_fee_amount', 18, 2)->default(0);
            $table->decimal('balance_change', 18, 2)->default(0);
            $table->string('currency')->default('MMK');
            $table->decimal('exchange_rate', 18, 4)->nullable();
            $table->foreignId('fee_account_id')->nullable()->constrained('accounts')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('fee_payment_method', 16)->default('cash');
            $table->string('screenshot_path')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->timestamp('created_at')->useCurrent();
            $table->foreignId('cash_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cash_approved_at')->nullable();
            $table->enum('status', ['PENDING_CASHIER_CONFIRM', 'COMPLETED', 'CANCELLED'])->default('COMPLETED');
            $table->enum('vault_impact', ['mini_vault_decrease', 'main_vault_increase', 'none'])->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->decimal('change_given', 18, 2)->default(0);
            $table->json('change_denominations')->nullable();
            $table->index('transaction_type');
            $table->index('created_at');
            $table->index('created_by');
            $table->index('status');
        });

        Schema::create('commission_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_type_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->decimal('amount_from', 18, 2);
            $table->decimal('amount_to', 18, 2);
            $table->enum('fee_amount_type', ['FIXED', 'PERCENTAGE'])->default('FIXED');
            $table->decimal('fee_amount_deposit', 18, 4)->default(0);
            $table->decimal('fee_amount_withdraw', 18, 4)->default(0);
            $table->enum('comm_type', ['FIXED', 'PERCENTAGE'])->default('FIXED');
            $table->decimal('comm_deposit', 18, 4)->default(0);
            $table->decimal('comm_withdraw', 18, 4)->default(0);
            $table->enum('additional_fee_type', ['FIXED', 'PERCENTAGE'])->default('FIXED');
            $table->decimal('additional_fee_deposit_amount', 18, 4)->default(0);
            $table->decimal('additional_fee_withdraw_amount', 18, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['service_type_id', 'is_active']);
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('base_currency')->default('THB');
            $table->string('quote_currency')->default('MMK');
            $table->decimal('base_amount', 18, 2)->default(1);
            $table->decimal('buy_rate', 18, 4);
            $table->decimal('sell_rate', 18, 4);
            $table->timestamps();
            $table->index(['base_currency', 'quote_currency']);
            $table->index('updated_at');
        });

        Schema::create('daily_summary', function (Blueprint $table) {
            $table->id();
            $table->date('summary_date')->unique();
            $table->decimal('total_cash_in', 18, 2)->default(0);
            $table->decimal('total_cash_out', 18, 2)->default(0);
            $table->decimal('total_transfer', 18, 2)->default(0);
            $table->decimal('total_exchange', 18, 2)->default(0);
            $table->decimal('total_commission', 18, 2)->default(0);
            $table->decimal('total_customer_fees', 18, 2)->default(0);
            $table->decimal('total_profit', 18, 2)->default(0);
            $table->unsignedInteger('transaction_count')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('user_id');
            $table->index('created_at');
        });

        Schema::create('cash_float_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->enum('status', ['PENDING_RECEIPT', 'ACTIVE', 'PENDING_RECONCILIATION', 'CLOSED'])->default('PENDING_RECEIPT');
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->decimal('current_balance', 18, 2)->default(0);
            $table->json('return_denominations_json')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('closing_total', 18, 2)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['employee_id', 'status']);
        });

        Schema::create('cash_denomination_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('entry_type', ['vault_in', 'vault_out', 'float_returned', 'adjustment']);
            $table->unsignedInteger('denomination');
            $table->integer('quantity');
            $table->foreignId('float_id')->nullable()->constrained('cash_float_assignments')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at');
        });

        Schema::create('cash_float_denominations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('float_id')->constrained('cash_float_assignments')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unsignedInteger('denomination');
            $table->integer('quantity');
            $table->index('float_id');
        });

        Schema::create('vault_transactions', function (Blueprint $table) {
            $table->id();
            $table->enum('txn_type', ['float_issue', 'float_receipt', 'cash_out', 'return_initiate', 'return_confirm', 'adjustment']);
            $table->foreignId('float_id')->nullable()->constrained('cash_float_assignments')->nullOnDelete();
            $table->unsignedInteger('denomination');
            $table->unsignedInteger('quantity');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('float_id');
            $table->index('created_at');
        });

        Schema::create('note_denominations', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('value')->unique();
            $table->string('label_mm');
            $table->string('label_en');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('vault_denomination_balances', function (Blueprint $table) {
            $table->unsignedInteger('denomination_id')->primary();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedBigInteger('total_value')->default(0);
            $table->timestamp('last_updated')->useCurrent();
            $table->foreign('denomination_id')->references('id')->on('note_denominations');
        });

        Schema::create('transaction_payment_denominations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->unsignedInteger('denomination_id');
            $table->unsignedInteger('quantity_paid')->default(0);
            $table->unsignedInteger('quantity_returned')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('denomination_id')->references('id')->on('note_denominations');
            $table->index('transaction_id');
        });

        Schema::create('daily_reconciliation_logs', function (Blueprint $table) {
            $table->id();
            $table->date('recon_date');
            $table->foreignId('closed_by')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->timestamp('closed_at')->useCurrent();
            $table->decimal('total_cash_in', 18, 2)->default(0);
            $table->decimal('total_cash_out', 18, 2)->default(0);
            $table->decimal('total_transfer', 18, 2)->default(0);
            $table->decimal('total_exchange', 18, 2)->default(0);
            $table->decimal('total_commission', 18, 2)->default(0);
            $table->decimal('total_customer_fees', 18, 2)->default(0);
            $table->decimal('main_vault_total', 18, 2)->default(0);
            $table->decimal('employee_floats_total', 18, 2)->default(0);
            $table->decimal('total_cash', 18, 2)->default(0);
            $table->decimal('total_digital', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->json('employee_snapshots')->nullable();
            $table->json('account_snapshots')->nullable();
            $table->json('vault_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->index('recon_date');
        });

        $this->seedSchemaVersion();
        $this->seedDenominations();
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_reconciliation_logs');
        Schema::dropIfExists('transaction_payment_denominations');
        Schema::dropIfExists('vault_denomination_balances');
        Schema::dropIfExists('note_denominations');
        Schema::dropIfExists('vault_transactions');
        Schema::dropIfExists('cash_float_denominations');
        Schema::dropIfExists('cash_denomination_logs');
        Schema::dropIfExists('cash_float_assignments');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('daily_summary');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('commission_tiers');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('service_types');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('schema_version');

        Schema::table('users', function (Blueprint $table) {
            foreach (['auth_version', 'is_active', 'role', 'full_name', 'pin_hash', 'username'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function seedSchemaVersion(): void
    {
        $versions = [
            [1, 'Add cashier role and pin_hash'],
            [2, 'Create cash management tables'],
            [3, 'Add cash approval fields to transactions'],
            [4, 'Add companies, service_types; migrate accounts, commission_tiers, and transactions'],
            [5, 'Add is_fee_account flag to accounts'],
            [6, 'Add current_balance to floats; create daily_reconciliation_logs'],
            [7, 'Rebuild cash_float_assignments with new statuses; create vault_transactions'],
            [8, 'Add Pay_To_Pay service types for Bank companies'],
            [9, 'Add auth_version for token revocation'],
            [10, 'Add transaction status and vault impact fields'],
            [11, 'Add denomination payment and vault balance tables'],
            [12, 'Add 20,000 MMK denomination support'],
        ];

        foreach ($versions as [$version, $description]) {
            DB::table('schema_version')->insertOrIgnore([
                'version' => $version,
                'description' => $description,
            ]);
        }
    }

    private function seedDenominations(): void
    {
        foreach ([50, 100, 200, 500, 1000, 5000, 10000, 20000] as $value) {
            DB::table('note_denominations')->insertOrIgnore([
                'id' => $value,
                'value' => $value,
                'label_mm' => number_format($value).' Kyats',
                'label_en' => number_format($value).' Kyats',
                'is_active' => true,
            ]);

            DB::table('vault_denomination_balances')->insertOrIgnore([
                'denomination_id' => $value,
                'quantity' => 0,
                'total_value' => 0,
            ]);
        }
    }
};
