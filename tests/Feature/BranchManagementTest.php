<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BranchManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('branch management tests');
        parent::setUp();
    }

    public function test_admin_can_open_branch_management_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/admin/branches')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('admin/Branches')
                    ->has('adminData.branches', 1)
                    ->where('adminData.branches.0.code', 'MAIN')
            );
    }

    public function test_admin_can_create_branch_and_zero_vault_rows_are_initialized(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/branch-management/branches', [
            'code' => 'BR-002',
            'name' => 'Branch 2',
            'address' => 'Second branch',
            'phone' => '09123456789',
            'is_active' => true,
        ])->assertRedirect();

        $branch = Branch::query()->where('code', 'BR-002')->firstOrFail();

        $this->assertSame('Branch 2', $branch->name);
        $this->assertSame(
            8,
            DB::table('branch_vault_denomination_balances')
                ->where('branch_id', $branch->id)
                ->count(),
        );
    }

    public function test_each_branch_can_have_one_cashier_and_many_tellers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $main = Branch::main();
        $second = Branch::query()->create([
            'code' => 'BR-002',
            'name' => 'Branch 2',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post('/admin/branch-management/users', [
            'username' => 'cashier-main',
            'full_name' => 'Main Cashier',
            'role' => 'cashier',
            'branch_id' => $main->id,
            'password' => 'password123',
            'is_active' => true,
        ])->assertRedirect();

        $this->actingAs($admin)->post('/admin/branch-management/users', [
            'username' => 'cashier-two',
            'full_name' => 'Branch Two Cashier',
            'role' => 'cashier',
            'branch_id' => $second->id,
            'password' => 'password123',
            'is_active' => true,
        ])->assertRedirect();

        $this->actingAs($admin)->post('/admin/branch-management/users', [
            'username' => 'cashier-two-duplicate',
            'full_name' => 'Duplicate Cashier',
            'role' => 'cashier',
            'branch_id' => $second->id,
            'password' => 'password123',
            'is_active' => true,
        ])->assertSessionHasErrors('branch_id');

        foreach (['teller-two-a', 'teller-two-b'] as $username) {
            $this->actingAs($admin)->post('/admin/branch-management/users', [
                'username' => $username,
                'full_name' => $username,
                'role' => 'teller',
                'branch_id' => $second->id,
                'password' => 'password123',
                'is_active' => true,
            ])->assertRedirect();
        }

        $this->assertSame(
            1,
            User::query()->withoutGlobalScopes()
                ->where('branch_id', $second->id)
                ->where('role', 'cashier')
                ->count(),
        );

        $this->assertSame(
            2,
            User::query()->withoutGlobalScopes()
                ->where('branch_id', $second->id)
                ->where('role', 'teller')
                ->count(),
        );
    }

    public function test_staff_requires_an_active_branch(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/branch-management/users', [
            'username' => 'unassigned-teller',
            'full_name' => 'Unassigned Teller',
            'role' => 'teller',
            'password' => 'password123',
            'is_active' => true,
        ])->assertSessionHasErrors('branch_id');
    }

    public function test_branch_with_active_staff_cannot_be_deactivated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $branch = Branch::query()->create([
            'code' => 'BR-003',
            'name' => 'Branch 3',
            'is_active' => true,
        ]);

        User::factory()->create([
            'role' => 'teller',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->patch("/admin/branch-management/branches/{$branch->id}/status", [
                'is_active' => false,
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($branch->fresh()->is_active);
    }

    public function test_system_admin_cannot_be_deactivated_or_demoted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->patch("/admin/branch-management/users/{$admin->id}/status", [
                'is_active' => false,
            ])
            ->assertSessionHasErrors('is_active');

        $this->actingAs($admin)
            ->patch("/admin/branch-management/users/{$admin->id}", [
                'role' => 'teller',
                'branch_id' => Branch::main()->id,
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertTrue($admin->fresh()->is_active);
    }
}
