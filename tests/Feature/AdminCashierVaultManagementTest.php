<?php

namespace Tests\Feature;

use App\Models\BalanceAdjustmentRequest;
use App\Models\Branch;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCashierVaultManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('admin cashier vault management tests');
        parent::setUp();
    }

    public function test_legacy_direct_admin_vault_mutation_is_blocked(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create([
            'role' => 'cashier',
            'branch_id' => Branch::main()->id,
        ]);

        $this->actingAs($admin)
            ->post('/admin/actions/vault/entries', [
                'entry_type' => 'vault_in',
                'denominations' => [10000 => 1],
            ])
            ->assertSessionHasErrors('form');

        $this->assertSame(
            0,
            app(CashDenominationRepository::class)
                ->getVaultBalance(Branch::main()->id)[10000],
        );
    }

    public function test_admin_cash_request_is_applied_only_after_cashier_pin(): void
    {
        $branch = Branch::main();
        $admin = User::factory()->create(['role' => 'admin']);
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branch->id,
            'pin_hash' => Hash::make('2222'),
        ]);

        $this->actingAs($admin)
            ->post('/admin/branch-vault/entries', [
                'branch_id' => $branch->id,
                'entry_type' => 'vault_in',
                'denominations' => [10000 => 5, 5000 => 2],
                'note' => 'Opening owner deposit request.',
            ])
            ->assertRedirect();

        $adjustment = BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->firstOrFail();

        $this->assertSame(
            0,
            app(CashDenominationRepository::class)
                ->getVaultBalance($branch->id)[10000],
        );

        $this->actingAs($cashier)
            ->post("/cashier/admin-requests/{$adjustment->id}/confirm", [
                'pin' => '2222',
            ])
            ->assertRedirect();

        $vault = app(CashDenominationRepository::class)
            ->getVaultBalance($branch->id);

        $this->assertSame(5, $vault[10000]);
        $this->assertSame(2, $vault[5000]);

        $this->assertDatabaseHas('vault_transactions', [
            'branch_id' => $branch->id,
            'txn_type' => 'adjustment',
            'movement_type' => 'admin_to_cashier',
            'verified_by' => $cashier->id,
        ]);
    }

    public function test_cashier_cannot_manually_mutate_main_vault(): void
    {
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => Branch::main()->id,
        ]);

        $this->actingAs($cashier)
            ->post('/cashier/vault/entries', [
                'entry_type' => 'vault_in',
                'denominations' => [10000 => 1],
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('cash_denomination_logs', [
            'entry_type' => 'vault_in',
            'created_by' => $cashier->id,
        ]);
    }

    public function test_withdraw_request_cannot_exceed_current_branch_stock(): void
    {
        $branch = Branch::main();
        $admin = User::factory()->create(['role' => 'admin']);

        User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branch->id,
            'pin_hash' => Hash::make('2222'),
        ]);

        $this->actingAs($admin)
            ->post('/admin/branch-vault/entries', [
                'branch_id' => $branch->id,
                'entry_type' => 'vault_out',
                'denominations' => [10000 => 2],
                'note' => 'Owner withdrawal request',
            ])
            ->assertSessionHasErrors('denominations');

        $this->assertDatabaseCount('balance_adjustment_requests', 0);
    }
}
