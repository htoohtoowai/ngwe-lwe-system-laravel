<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NgweLweSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is not enabled for in-memory migration tests.');
        }

        $this->artisan('migrate:fresh')->run();
    }

    public function test_ngwe_lwe_core_tables_are_created(): void
    {
        foreach ([
            'schema_version',
            'companies',
            'service_types',
            'accounts',
            'transactions',
            'commission_tiers',
            'exchange_rates',
            'daily_summary',
            'activity_logs',
            'cash_float_assignments',
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

    public function test_users_table_has_ngwe_lwe_auth_and_role_columns(): void
    {
        foreach (['username', 'pin_hash', 'full_name', 'role', 'is_active', 'auth_version'] as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column), "Missing users.{$column}");
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
