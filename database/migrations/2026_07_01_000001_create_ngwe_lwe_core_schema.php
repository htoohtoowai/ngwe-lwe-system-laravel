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

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('logo_path')->nullable();
            $table->enum('category', ['Pay', 'Bank', 'Both'])->default('Pay');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->string('account_name');
            $table->string('account_type', 8)->default('PAY'); // PAY | BANK
            $table->string('account_identifier');
            $table->decimal('balance', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_fee_account')->default(false);
            $table->boolean('is_agent')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'account_identifier'], 'accounts_company_identifier_unique');
            $table->index(['account_type', 'is_agent']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('account_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('feature', 32);
            $table->timestamps();
            $table->unique(['account_id', 'feature']);
            $table->index('feature');
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
            $table->string('source_account_type', 16)->nullable();
            $table->string('source_provider')->nullable();
            $table->string('source_account_number')->nullable();
            $table->string('destination_provider')->nullable();
            $table->string('destination_customer_name')->nullable();
            $table->string('destination_account_number')->nullable();
            $table->decimal('amount', 18, 2);
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
            $table->json('received_denominations')->nullable();
            $table->json('handoff_denominations')->nullable();
            $table->index('transaction_type');
            $table->index('created_at');
            $table->index('created_by');
            $table->index('status');
        });

        Schema::create('provider_fee_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->string('feature', 32);
            $table->decimal('amount_from', 18, 2);
            $table->decimal('amount_to', 18, 2);
            $table->string('fee_type', 16)->default('FIXED');
            $table->decimal('fee_value', 18, 4)->default(0);
            $table->string('additional_fee_type', 16)->default('FIXED');
            $table->decimal('additional_fee_value', 18, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'feature', 'is_active'], 'provider_fee_tiers_company_feature_active_index');
            $table->index(['amount_from', 'amount_to']);
        });

        Schema::create('agent_commission_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->decimal('amount_from', 18, 2);
            $table->decimal('amount_to', 18, 2);
            $table->string('commission_type', 16)->default('FIXED');
            $table->decimal('out_commission_value', 18, 4)->default(0);
            $table->decimal('in_commission_value', 18, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'is_active'], 'agent_comm_tiers_company_active_index');
            $table->index(['amount_from', 'amount_to']);
        });

        Schema::create('transfer_fee_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_from_id')->constrained('companies')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('company_to_id')->constrained('companies')->restrictOnDelete()->cascadeOnUpdate();
            $table->decimal('amount_from', 18, 2);
            $table->decimal('amount_to', 18, 2);
            $table->string('fee_type', 16)->default('FIXED');
            $table->decimal('fee_value', 18, 4)->default(0);
            $table->string('additional_fee_type', 16)->default('FIXED');
            $table->decimal('additional_fee_value', 18, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_from_id', 'company_to_id', 'is_active'], 'transfer_fee_tiers_company_active_index');
            $table->index(['amount_from', 'amount_to']);
        });

        Schema::create('agent_commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('agent_commission_tier_id')->nullable()->constrained('agent_commission_tiers')->nullOnDelete()->cascadeOnUpdate();
            $table->string('direction', 8); // IN | OUT
            $table->decimal('base_amount', 18, 2);
            $table->string('calculation_type', 16);
            $table->decimal('configured_value', 18, 4);
            $table->decimal('commission_amount', 18, 2);
            $table->string('status', 16)->default('EARNED');
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['transaction_id', 'account_id', 'direction'], 'agent_comm_entries_txn_account_direction_unique');
            $table->index(['account_id', 'created_at']);
            $table->index(['company_id', 'direction']);
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete()->cascadeOnUpdate();
            $table->string('base_currency')->default('THB');
            $table->string('quote_currency')->default('MMK');
            $table->decimal('base_amount', 18, 2)->default(1);
            $table->decimal('buy_rate', 18, 4);
            $table->decimal('sell_rate', 18, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'base_currency', 'quote_currency'], 'exchange_rates_company_currency_index');
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
            $table->enum('txn_type', [
                'float_issue',
                'float_receipt',
                'float_reject',
                'cash_in',
                'cash_in_received',
                'cash_in_handoff',
                'cash_in_change',
                'cash_out',
                'cash_out_fee_received',
                'transfer_fee_received',
                'return_initiate',
                'return_confirm',
                'adjustment',
            ]);
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
        Schema::dropIfExists('agent_commission_entries');
        Schema::dropIfExists('transfer_fee_tiers');
        Schema::dropIfExists('agent_commission_tiers');
        Schema::dropIfExists('provider_fee_tiers');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('account_features');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('companies');

        Schema::table('users', function (Blueprint $table) {
            foreach (['auth_version', 'is_active', 'role', 'full_name', 'pin_hash', 'username'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
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
