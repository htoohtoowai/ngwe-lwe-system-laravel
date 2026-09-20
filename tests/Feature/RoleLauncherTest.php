<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BalanceAdjustmentRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RoleLauncherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('role launcher tests');
        parent::setUp();
    }

    public function test_admin_home_is_launcher_with_selected_branch_and_adjustment_badges(): void
    {
        $main = Branch::main();
        $branch = Branch::query()->create([
            'code' => 'BR-002',
            'name' => 'Branch 2',
            'is_active' => true,
        ]);
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
                    'note' => 'Launcher badge test',
                    'status' => BalanceAdjustmentRequest::STATUS_PENDING,
                    'requested_by' => $cashier->id,
                    'assigned_cashier_id' => $cashier->id,
                    'approver_id' => $admin->id,
                ]);
        }

        $this->actingAs($admin)
            ->get("/admin?branch_id={$branch->id}")
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('admin/Overview')
                    ->where('adminData.selectedBranchId', $branch->id)
                    ->where('adminData.adjustmentCounts.deposit', 1)
                    ->where('adminData.adjustmentCounts.withdraw', 1),
            );

        $this->assertNotSame($main->id, $branch->id);
    }

    public function test_admin_pay_and_bank_balance_pages_are_branch_aware(): void
    {
        $branch = Branch::main();
        $admin = User::factory()->create(['role' => 'admin']);
        $payProvider = Company::query()->create([
            'name' => 'K Pay',
            'category' => 'Pay',
            'is_active' => true,
        ]);
        $bankProvider = Company::query()->create([
            'name' => 'KBZ Bank',
            'category' => 'Bank',
            'is_active' => true,
        ]);

        Account::query()->withoutGlobalScopes()->create([
            'company_id' => $payProvider->id,
            'branch_id' => $branch->id,
            'account_name' => 'Main KPay',
            'account_type' => 'PAY',
            'account_identifier' => 'KPAY-001',
            'balance' => '12000.00',
            'is_active' => true,
            'is_fee_account' => false,
            'is_agent' => false,
        ]);

        Account::query()->withoutGlobalScopes()->create([
            'company_id' => $bankProvider->id,
            'branch_id' => $branch->id,
            'account_name' => 'Main Bank',
            'account_type' => 'BANK',
            'account_identifier' => 'BANK-001',
            'balance' => '50000.00',
            'is_active' => true,
            'is_fee_account' => false,
            'is_agent' => false,
        ]);

        $this->actingAs($admin)
            ->get("/admin/balances/pay?branch_id={$branch->id}")
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('admin/BalanceAccounts')
                    ->where('type', 'pay')
                    ->has('accounts', 1)
                    ->where('accounts.0.account_type', 'PAY'),
            );

        $this->actingAs($admin)
            ->get("/admin/balances/bank?branch_id={$branch->id}")
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('admin/BalanceAccounts')
                    ->where('type', 'bank')
                    ->has('accounts', 1)
                    ->where('accounts.0.account_type', 'BANK'),
            );
    }

    public function test_cashier_can_open_launcher_and_own_branch_teller_page(): void
    {
        $main = Branch::main();
        $branch = Branch::query()->create([
            'code' => 'BR-002',
            'name' => 'Branch 2',
            'is_active' => true,
        ]);
        User::factory()->create([
            'role' => 'teller',
            'branch_id' => $main->id,
            'full_name' => 'Other Teller',
        ]);
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branch->id,
        ]);
        $teller = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $branch->id,
            'full_name' => 'Branch Teller',
        ]);

        $this->actingAs($cashier)
            ->get('/cashier')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('cashier/Dashboard'));

        $this->actingAs($cashier)
            ->get('/cashier/tellers')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('cashier/Tellers')
                    ->has('tellers', 1)
                    ->where('tellers.0.id', $teller->id),
            );
    }
}
