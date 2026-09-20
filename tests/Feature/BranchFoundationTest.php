<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Repositories\UserRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BranchFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('branch foundation tests');
        parent::setUp();
    }

    public function test_main_branch_and_branch_columns_exist(): void
    {
        $main = Branch::main();

        $this->assertSame('MAIN', $main->code);
        $this->assertSame('Main Branch', $main->name);
        $this->assertTrue($main->is_active);

        foreach ([
            'users',
            'accounts',
            'transactions',
            'cash_float_assignments',
            'cash_float_issues',
            'vault_transactions',
            'cash_denomination_logs',
            'daily_summary',
            'daily_reconciliation_logs',
        ] as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'branch_id'),
                "{$table}.branch_id is missing.",
            );
        }
    }

    public function test_user_repository_assigns_staff_to_main_branch_and_keeps_admin_global(): void
    {
        $users = app(UserRepository::class);
        $main = Branch::main();

        $admin = $users->create([
            'username' => 'branch-admin',
            'full_name' => 'Branch Admin',
            'role' => 'admin',
            'password' => 'password123',
        ]);

        $cashier = $users->create([
            'username' => 'branch-cashier',
            'full_name' => 'Branch Cashier',
            'role' => 'cashier',
            'password' => 'password123',
        ]);

        $teller = $users->create([
            'username' => 'branch-teller',
            'full_name' => 'Branch Teller',
            'role' => 'teller',
            'password' => 'password123',
        ]);

        $this->assertNull($admin->branch_id);
        $this->assertSame($main->id, $cashier->branch_id);
        $this->assertSame($main->id, $teller->branch_id);
        $this->assertSame($main->id, $cashier->branch?->id);
        $this->assertSame($main->id, $teller->branch?->id);
    }

    public function test_staff_can_be_assigned_to_an_explicit_branch(): void
    {
        $branch = Branch::query()->create([
            'code' => 'BR-002',
            'name' => 'Branch 2',
            'is_active' => true,
        ]);

        $teller = app(UserRepository::class)->create([
            'username' => 'branch-two-teller',
            'full_name' => 'Branch Two Teller',
            'role' => 'teller',
            'branch_id' => $branch->id,
            'password' => 'password123',
        ]);

        $this->assertSame($branch->id, $teller->branch_id);
    }
}
