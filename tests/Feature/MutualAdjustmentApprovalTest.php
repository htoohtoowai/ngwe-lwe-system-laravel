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
use Tests\TestCase;

class MutualAdjustmentApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('mutual adjustment approval tests');
        parent::setUp();
    }

    public function test_cashier_cash_deposit_request_waits_for_admin_approval(): void
    {
        $branch = Branch::main();
        $admin = User::factory()->create(['role' => 'admin']);
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branch->id,
            'pin_hash' => Hash::make('2222'),
        ]);

        $this->actingAs($cashier)
            ->post('/cashier/adjustments', [
                'target_type' => 'cash',
                'direction' => 'deposit',
                'amount' => 2000,
                'denominations' => ['1000' => 2],
                'note' => 'Request additional branch operating cash.',
            ])
            ->assertRedirect();

        $adjustment = BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame($cashier->id, $adjustment->requested_by);
        $this->assertSame($cashier->id, $adjustment->assigned_cashier_id);
        $this->assertSame($admin->id, $adjustment->approver_id);
        $this->assertSame(
            0,
            app(CashDenominationRepository::class)
                ->getVaultBalance($branch->id)[1000],
        );

        $this->actingAs($admin)
            ->post("/admin/adjustments/{$adjustment->id}/approve")
            ->assertRedirect();

        $this->assertSame(
            0,
            app(CashDenominationRepository::class)
                ->getVaultBalance($branch->id)[1000],
        );
        $this->assertDatabaseHas('balance_adjustment_requests', [
            'id' => $adjustment->id,
            'status' => BalanceAdjustmentRequest::STATUS_APPROVED,
            'approver_id' => $admin->id,
        ]);

        $this->actingAs($cashier)
            ->post("/cashier/admin-requests/{$adjustment->id}/confirm", [
                'pin' => '2222',
            ])
            ->assertRedirect();

        $this->assertSame(
            2,
            app(CashDenominationRepository::class)
                ->getVaultBalance($branch->id)[1000],
        );
        $this->assertDatabaseHas('balance_adjustment_requests', [
            'id' => $adjustment->id,
            'status' => BalanceAdjustmentRequest::STATUS_CONFIRMED,
            'confirmed_by' => $cashier->id,
        ]);
        $this->assertDatabaseHas('vault_transactions', [
            'branch_id' => $branch->id,
            'movement_type' => 'admin_to_cashier',
            'performed_by' => $cashier->id,
            'verified_by' => $admin->id,
        ]);
    }

    public function test_cashier_bank_withdraw_request_is_applied_by_admin(): void
    {
        $branch = Branch::main();
        $admin = User::factory()->create(['role' => 'admin']);
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branch->id,
        ]);
        $company = Company::query()->create([
            'name' => 'KBZ Bank',
            'category' => 'Bank',
            'is_active' => true,
        ]);
        $account = Account::query()->withoutGlobalScopes()->create([
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

        $this->actingAs($cashier)
            ->post('/cashier/adjustments', [
                'target_type' => 'account',
                'account_id' => $account->id,
                'direction' => 'withdraw',
                'amount' => 2500,
                'note' => 'Request bank withdrawal for branch operation.',
            ])
            ->assertRedirect();

        $adjustment = BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame('10000.00', $account->fresh()->balance);
        $this->assertSame($admin->id, $adjustment->approver_id);

        $this->actingAs($admin)
            ->post("/admin/adjustments/{$adjustment->id}/approve")
            ->assertRedirect();

        $this->assertSame('7500.00', $account->fresh()->balance);
    }

    public function test_cashier_cannot_pin_confirm_own_request_assigned_to_admin(): void
    {
        $branch = Branch::main();
        User::factory()->create(['role' => 'admin']);
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branch->id,
            'pin_hash' => Hash::make('2222'),
        ]);

        $this->actingAs($cashier)
            ->post('/cashier/adjustments', [
                'target_type' => 'cash',
                'direction' => 'deposit',
                'denominations' => ['1000' => 1],
                'note' => 'Admin approval required.',
            ])
            ->assertRedirect();

        $adjustment = BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->firstOrFail();

        $this->actingAs($cashier)
            ->post("/cashier/admin-requests/{$adjustment->id}/confirm", [
                'pin' => '2222',
            ])
            ->assertSessionHasErrors('request');

        $this->assertSame(
            BalanceAdjustmentRequest::STATUS_PENDING,
            $adjustment->fresh()->status,
        );
    }

    public function test_admin_cannot_approve_request_assigned_to_cashier(): void
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
                'note' => 'Cashier must confirm this request.',
            ])
            ->assertRedirect();

        $adjustment = BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->firstOrFail();

        $this->actingAs($admin)
            ->post("/admin/adjustments/{$adjustment->id}/approve")
            ->assertSessionHasErrors('request');

        $this->assertSame(
            BalanceAdjustmentRequest::STATUS_PENDING,
            $adjustment->fresh()->status,
        );
    }
}
