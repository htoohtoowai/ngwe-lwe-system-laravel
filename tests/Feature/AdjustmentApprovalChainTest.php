<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BalanceAdjustmentRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdjustmentApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('adjustment approval chain tests');
        parent::setUp();
    }

    public function test_admin_bank_account_deposit_waits_for_branch_cashier_pin(): void
    {
        $branch = Branch::main();
        $admin = User::factory()->create(['role' => 'admin']);
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branch->id,
            'pin_hash' => Hash::make('2222'),
        ]);

        $company = Company::query()->create([
            'name' => 'KBZ Bank',
            'category' => 'Bank',
            'is_active' => true,
        ]);

        $account = Account::query()
            ->withoutGlobalScopes()
            ->create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'account_name' => 'Branch Bank',
                'account_type' => 'BANK',
                'account_identifier' => 'BANK-001',
                'balance' => '10000.00',
                'is_active' => true,
                'is_fee_account' => false,
                'is_agent' => false,
            ]);

        $this->actingAs($admin)
            ->post('/admin/adjustments', [
                'branch_id' => $branch->id,
                'target_type' => 'account',
                'account_id' => $account->id,
                'direction' => 'deposit',
                'amount' => 5000,
                'note' => 'Bank top-up',
            ])
            ->assertRedirect();

        $this->assertSame('10000.00', $account->fresh()->balance);

        $adjustment = BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame(BalanceAdjustmentRequest::STATUS_PENDING, $adjustment->status);
        $this->assertSame($cashier->id, $adjustment->assigned_cashier_id);

        $this->actingAs($cashier)
            ->post("/cashier/admin-requests/{$adjustment->id}/confirm", [
                'pin' => '2222',
            ])
            ->assertRedirect();

        $this->assertSame('15000.00', $account->fresh()->balance);
    }

    public function test_admin_pay_account_withdraw_waits_for_cashier_and_cannot_overdraw_at_confirmation(): void
    {
        $branch = Branch::main();
        $admin = User::factory()->create(['role' => 'admin']);
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branch->id,
            'pin_hash' => Hash::make('2222'),
        ]);

        $company = Company::query()->create([
            'name' => 'KBZ Pay',
            'category' => 'Pay',
            'is_active' => true,
        ]);

        $account = Account::query()
            ->withoutGlobalScopes()
            ->create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'account_name' => 'Branch Pay',
                'account_type' => 'PAY',
                'account_identifier' => 'PAY-001',
                'balance' => '20000.00',
                'is_active' => true,
                'is_fee_account' => false,
                'is_agent' => false,
            ]);

        $this->actingAs($admin)
            ->post('/admin/adjustments', [
                'branch_id' => $branch->id,
                'target_type' => 'account',
                'account_id' => $account->id,
                'direction' => 'withdraw',
                'amount' => 7000,
                'note' => 'Pay withdrawal request',
            ])
            ->assertRedirect();

        $adjustment = BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame('20000.00', $account->fresh()->balance);

        $this->actingAs($cashier)
            ->post("/cashier/admin-requests/{$adjustment->id}/confirm", [
                'pin' => '2222',
            ])
            ->assertRedirect();

        $this->assertSame('13000.00', $account->fresh()->balance);
    }

    public function test_other_branch_cashier_cannot_confirm_request(): void
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
            'pin_hash' => Hash::make('1111'),
        ]);

        $cashierB = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branchTwo->id,
            'pin_hash' => Hash::make('2222'),
        ]);

        $this->actingAs($admin)
            ->post('/admin/adjustments', [
                'branch_id' => $main->id,
                'target_type' => 'cash',
                'direction' => 'deposit',
                'denominations' => ['1000' => 1],
                'note' => 'Branch cash request',
            ])
            ->assertRedirect();

        $adjustment = BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame($cashierA->id, $adjustment->assigned_cashier_id);

        $this->actingAs($cashierB)
            ->post("/cashier/admin-requests/{$adjustment->id}/confirm", [
                'pin' => '2222',
            ])
            ->assertNotFound();

        $this->assertSame(
            BalanceAdjustmentRequest::STATUS_PENDING,
            $adjustment->fresh()->status,
        );
    }

    public function test_cashier_rejection_does_not_change_balance(): void
    {
        $branch = Branch::main();
        $admin = User::factory()->create(['role' => 'admin']);
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branch->id,
            'pin_hash' => Hash::make('2222'),
        ]);

        $this->actingAs($admin)
            ->post('/admin/adjustments', [
                'branch_id' => $branch->id,
                'target_type' => 'cash',
                'direction' => 'deposit',
                'denominations' => ['1000' => 3],
                'note' => 'Cash deposit request',
            ])
            ->assertRedirect();

        $adjustment = BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->firstOrFail();

        $this->actingAs($cashier)
            ->post("/cashier/admin-requests/{$adjustment->id}/reject", [
                'pin' => '2222',
                'note' => 'Cash not received.',
            ])
            ->assertRedirect();

        $this->assertSame(
            0,
            app(CashDenominationRepository::class)
                ->getVaultBalance($branch->id)[1000],
        );

        $this->assertDatabaseHas('balance_adjustment_requests', [
            'id' => $adjustment->id,
            'status' => BalanceAdjustmentRequest::STATUS_REJECTED,
            'rejected_by' => $cashier->id,
        ]);
    }

    public function test_cashier_dashboard_contains_only_tellers_from_own_branch(): void
    {
        $main = Branch::main();
        $branchTwo = Branch::query()->create([
            'code' => 'BR-002',
            'name' => 'Branch 2',
            'is_active' => true,
        ]);

        User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $main->id,
        ]);

        $cashierB = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branchTwo->id,
        ]);

        User::factory()->create([
            'role' => 'teller',
            'branch_id' => $main->id,
            'full_name' => 'Main Teller',
        ]);

        $tellerB = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $branchTwo->id,
            'full_name' => 'Branch Two Teller',
        ]);

        $this->actingAs($cashierB)
            ->get('/cashier')
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('cashier/Dashboard')
                    ->has('tellers', 1)
                    ->where('tellers.0.id', $tellerB->id),
            );
    }
}
