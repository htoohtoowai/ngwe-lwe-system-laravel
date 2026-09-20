<?php

namespace Tests\Feature;

use App\Models\BalanceAdjustmentRequest;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdjustmentRequestUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('adjustment request UX tests');
        parent::setUp();
    }

    public function test_admin_launcher_direction_is_preselected_on_adjustment_page(): void
    {
        $branch = Branch::main();
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branch->id,
        ]);

        $this->actingAs($admin)
            ->get("/admin/adjustments?branch_id={$branch->id}&direction=withdraw")
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('admin/Adjustments')
                    ->where('selectedDirection', 'withdraw')
                    ->where('selectedBranchId', $branch->id),
            );
    }

    public function test_cashier_deposit_and_withdraw_pages_use_direction_context(): void
    {
        $branch = Branch::main();
        $admin = User::factory()->create(['role' => 'admin']);
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branch->id,
        ]);

        foreach (['deposit', 'withdraw'] as $direction) {
            BalanceAdjustmentRequest::query()
                ->withoutGlobalScopes()
                ->create([
                    'branch_id' => $branch->id,
                    'target_type' => 'cash',
                    'direction' => $direction,
                    'amount' => '1000.00',
                    'denominations_json' => [1000 => 1],
                    'note' => "{$direction} request",
                    'status' => BalanceAdjustmentRequest::STATUS_PENDING,
                    'requested_by' => $admin->id,
                    'assigned_cashier_id' => $cashier->id,
                ]);
        }

        $this->actingAs($cashier)
            ->get('/cashier/admin-requests?direction=withdraw')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('cashier/AdminRequests')
                    ->where('selectedDirection', 'withdraw')
                    ->has('rows', 2),
            );
    }

    public function test_adjustment_remark_is_required(): void
    {
        $branch = Branch::main();
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branch->id,
        ]);

        $this->actingAs($admin)
            ->post('/admin/adjustments', [
                'branch_id' => $branch->id,
                'target_type' => 'cash',
                'direction' => 'deposit',
                'denominations' => ['1000' => 1],
                'amount' => 1000,
                'note' => '',
            ])
            ->assertSessionHasErrors('note');

        $this->assertDatabaseCount('balance_adjustment_requests', 0);
    }
}
