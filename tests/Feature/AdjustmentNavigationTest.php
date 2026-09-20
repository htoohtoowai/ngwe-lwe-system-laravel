<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BalanceAdjustmentRequest;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdjustmentNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('adjustment navigation tests');
        parent::setUp();
    }

    public function test_admin_adjustment_page_preselects_requested_branch_account(): void
    {
        $branch = Branch::main();
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branch->id,
        ]);

        [$account] = $this->createCompanyAccountFixture(25000);
        $account->forceFill(['branch_id' => $branch->id])->save();

        $this->actingAs($admin)
            ->get(
                "/admin/adjustments?branch_id={$branch->id}&account_id={$account->id}",
            )
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('admin/Adjustments')
                    ->where('selectedBranchId', $branch->id)
                    ->where('selectedAccountId', $account->id),
            );
    }

    public function test_cashier_admin_requests_page_only_lists_assigned_requests(): void
    {
        $main = Branch::main();
        $branchTwo = Branch::query()->create([
            'code' => 'BR-002',
            'name' => 'Branch 2',
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $cashierA = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $main->id,
        ]);
        $cashierB = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branchTwo->id,
        ]);

        BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->create([
                'branch_id' => $main->id,
                'target_type' => BalanceAdjustmentRequest::TARGET_CASH,
                'direction' => BalanceAdjustmentRequest::DIRECTION_DEPOSIT,
                'amount' => '1000.00',
                'denominations_json' => [1000 => 1],
                'status' => BalanceAdjustmentRequest::STATUS_PENDING,
                'requested_by' => $admin->id,
                'assigned_cashier_id' => $cashierA->id,
            ]);

        BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->create([
                'branch_id' => $branchTwo->id,
                'target_type' => BalanceAdjustmentRequest::TARGET_CASH,
                'direction' => BalanceAdjustmentRequest::DIRECTION_DEPOSIT,
                'amount' => '2000.00',
                'denominations_json' => [1000 => 2],
                'status' => BalanceAdjustmentRequest::STATUS_PENDING,
                'requested_by' => $admin->id,
                'assigned_cashier_id' => $cashierB->id,
            ]);

        $this->actingAs($cashierB)
            ->get('/cashier/admin-requests')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('cashier/AdminRequests')
                    ->has('rows', 1)
                    ->where('rows.0.branch_id', $branchTwo->id)
                    ->where('rows.0.assigned_cashier_id', $cashierB->id),
            );
    }
}
