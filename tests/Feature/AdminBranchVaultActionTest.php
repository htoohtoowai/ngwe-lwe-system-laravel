<?php

namespace Tests\Feature;

use App\Models\BalanceAdjustmentRequest;
use App\Models\Branch;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminBranchVaultActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('admin branch vault request tests');
        parent::setUp();
    }

    public function test_admin_cash_request_does_not_change_vault_until_cashier_pin_confirmation(): void
    {
        $main = Branch::main();
        $admin = User::factory()->create(['role' => 'admin']);
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $main->id,
            'pin_hash' => Hash::make('2222'),
        ]);

        $this->actingAs($admin)
            ->post('/admin/branch-vault/entries', [
                'branch_id' => $main->id,
                'entry_type' => 'vault_in',
                'denominations' => ['1000' => 2],
                'note' => 'Opening cash request',
            ])
            ->assertRedirect();

        $request = BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame(BalanceAdjustmentRequest::STATUS_PENDING, $request->status);
        $this->assertSame($cashier->id, $request->assigned_cashier_id);
        $this->assertSame(
            0,
            app(CashDenominationRepository::class)
                ->getVaultBalance($main->id)[1000],
        );

        $this->actingAs($cashier)
            ->post("/cashier/admin-requests/{$request->id}/confirm", [
                'pin' => '2222',
            ])
            ->assertRedirect();

        $this->assertSame(
            2,
            app(CashDenominationRepository::class)
                ->getVaultBalance($main->id)[1000],
        );

        $this->assertDatabaseHas('balance_adjustment_requests', [
            'id' => $request->id,
            'status' => BalanceAdjustmentRequest::STATUS_CONFIRMED,
            'confirmed_by' => $cashier->id,
        ]);
    }

    public function test_admin_cannot_request_branch_vault_without_active_cashier(): void
    {
        $branchTwo = Branch::query()->create([
            'code' => 'BR-002',
            'name' => 'Branch 2',
            'is_active' => true,
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post('/admin/branch-vault/entries', [
                'branch_id' => $branchTwo->id,
                'entry_type' => 'vault_in',
                'denominations' => ['1000' => 1],
            ])
            ->assertSessionHasErrors('branch_id');
    }
}
