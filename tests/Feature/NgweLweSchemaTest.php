<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NgweLweSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('migration tests');

        parent::setUp();
        $this->artisan('migrate:fresh')->run();
    }

    public function test_ngwe_lwe_core_tables_are_created(): void
    {
        foreach ([
            'companies',
            'accounts',
            'account_features',
            'transactions',
            'provider_fee_tiers',
            'agent_commission_tiers',
            'agent_commission_entries',
            'transfer_fee_tiers',
            'exchange_rates',
            'daily_summary',
            'activity_logs',
            'cash_float_assignments',
            'cash_float_issues',
            'cash_denomination_logs',
            'cash_float_denominations',
            'vault_transactions',
            'note_denominations',
            'vault_denomination_balances',
            'transaction_payment_denominations',
            'daily_reconciliation_logs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_service_types_and_legacy_foreign_keys_are_removed(): void
    {
        $this->assertFalse(Schema::hasTable('service_types'));
        $this->assertFalse(Schema::hasColumn('accounts', 'service_type_id'));
        $this->assertFalse(Schema::hasTable('commission_tiers'));
        $this->assertFalse(Schema::hasColumn('provider_fee_tiers', 'service_type_id'));
    }

    public function test_cash_ledgers_have_shared_reconciliation_metadata(): void
    {
        foreach ([
            'batch_id',
            'movement_type',
            'source_type',
            'source_id',
            'destination_type',
            'destination_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('vault_transactions', $column), "Missing vault_transactions.{$column}");
            $this->assertTrue(Schema::hasColumn('cash_denomination_logs', $column), "Missing cash_denomination_logs.{$column}");
        }

        $this->assertTrue(Schema::hasColumn('cash_denomination_logs', 'affects_main_vault'));
    }

    public function test_users_table_has_ngwe_lwe_auth_and_role_columns(): void
    {
        foreach (['username', 'pin_hash', 'full_name', 'role', 'is_active', 'auth_version'] as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column), "Missing users.{$column}");
        }
    }

    public function test_new_requirement_fee_schema_columns_are_available(): void
    {
        foreach (['company_id', 'account_type', 'account_identifier', 'is_agent'] as $column) {
            $this->assertTrue(Schema::hasColumn('accounts', $column), "Missing accounts.{$column}");
        }

        foreach (['company_id', 'feature', 'fee_type', 'fee_value', 'additional_fee_type', 'additional_fee_value'] as $column) {
            $this->assertTrue(Schema::hasColumn('provider_fee_tiers', $column), "Missing provider_fee_tiers.{$column}");
        }

        foreach (['company_id', 'amount_from', 'amount_to', 'commission_type', 'out_commission_value', 'in_commission_value'] as $column) {
            $this->assertTrue(Schema::hasColumn('agent_commission_tiers', $column), "Missing agent_commission_tiers.{$column}");
        }

        foreach (['transaction_id', 'account_id', 'direction', 'calculation_type', 'configured_value', 'commission_amount', 'status'] as $column) {
            $this->assertTrue(Schema::hasColumn('agent_commission_entries', $column), "Missing agent_commission_entries.{$column}");
        }

        foreach (['commission_amount', 'receive_commission_amount', 'payout_commission_amount'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('transactions', $column),
                "transactions must not duplicate agent commission column {$column}",
            );
        }

        $this->assertFalse(Schema::hasColumn('agent_commission_tiers', 'feature'));
        $this->assertFalse(Schema::hasColumn('agent_commission_entries', 'feature'));

        foreach (['account_id', 'feature'] as $column) {
            $this->assertTrue(Schema::hasColumn('account_features', $column), "Missing account_features.{$column}");
        }

        foreach (['company_from_id', 'company_to_id', 'fee_type', 'fee_value', 'additional_fee_value'] as $column) {
            $this->assertTrue(Schema::hasColumn('transfer_fee_tiers', $column), "Missing transfer_fee_tiers.{$column}");
        }

        foreach (['company_id', 'is_active'] as $column) {
            $this->assertTrue(Schema::hasColumn('exchange_rates', $column), "Missing exchange_rates.{$column}");
        }
    }

    public function test_default_note_denominations_are_seeded_with_vault_balances(): void
    {
        $this->assertSame(
            [50, 100, 200, 500, 1000, 5000, 10000, 20000],
            DB::table('note_denominations')->orderBy('id')->pluck('id')->all(),
        );

        $this->assertSame(8, DB::table('vault_denomination_balances')->count());
    }
}
