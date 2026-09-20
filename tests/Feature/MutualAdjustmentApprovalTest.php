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
        $this->skipIfDatabaseUnavailable('admin to cashier adjustment tests');
        parent::setUp();
    }

    public function test_cashier_adjustment_creation_route_is_not_available(): void
    {
        $branch = Branch::main();
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branch->id,
        ]);

        $this->actingAs($cashier)
            ->post('/cashier/adjustments', [
                'target_type' => 'cash',
                'direction' => 'deposit',
                'denominations' => ['1000' => 1],
                'note' => 'Should not be accepted.',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('balance_adjustment_requests', 0);
    }

    public function test_admin_cash_deposit_is_applied_only_after_cashier_pin_confirmation(): void
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
                'amount' => 2000,
                'denominations' => ['1000' => 2],
                'note' => 'Fund branch vault.',
            ])
            ->assertRedirect();

        $adjustment = BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame($admin->id, $adjustment->requested_by);
        $this->assertSame($cashier->id, $adjustment->assigned_cashier_id);
        $this->assertSame($cashier->id, $adjustment->approver_id);
        $this->assertSame(
            0,
            app(CashDenominationRepository::class)
                ->getVaultBalance($branch->id)[1000],
        );

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
    }

    public function test_admin_bank_withdraw_is_applied_only_after_cashier_pin_confirmation(): void
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

        $this->actingAs($admin)
            ->post('/admin/adjustments', [
                'branch_id' => $branch->id,
                'target_type' => 'account',
                'account_id' => $account->id,
                'direction' => 'withdraw',
                'amount' => 2500,
                'note' => 'Withdraw branch bank funds.',
            ])
            ->assertRedirect();

        $adjustment = BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame('10000.00', $account->fresh()->balance);

        $this->actingAs($cashier)
            ->post("/cashier/admin-requests/{$adjustment->id}/confirm", [
                'pin' => '2222',
            ])
            ->assertRedirect();

        $this->assertSame('7500.00', $account->fresh()->balance);
    }
}
