<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCashierVaultManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('admin cashier vault management tests');
        parent::setUp();
    }

    public function test_admin_can_deposit_and_withdraw_cashier_vault_with_audit_logs(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'full_name' => 'Owner Admin']);
        $cashier = User::factory()->create(['role' => 'cashier', 'full_name' => 'Main Cashier']);

        $this->actingAs($admin)->post('/admin/actions/vault/entries', [
            'entry_type' => 'vault_in',
            'denominations' => [10000 => 5, 5000 => 2],
            'note' => 'Opening owner deposit.',
        ])->assertRedirect();

        $this->assertDatabaseHas('vault_denomination_balances', [
            'denomination_id' => 10000,
            'quantity' => 5,
        ]);
        $this->assertDatabaseHas('cash_denomination_logs', [
            'entry_type' => 'vault_in',
            'denomination' => 10000,
            'quantity' => 5,
            'created_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'cashier_vault_deposit',
            'entity_type' => 'cashier_vault',
            'entity_id' => $cashier->id,
        ]);
        $this->assertDatabaseHas('vault_transactions', [
            'txn_type' => 'adjustment',
            'denomination' => 10000,
            'quantity' => 5,
            'performed_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post('/admin/actions/vault/entries', [
            'entry_type' => 'vault_out',
            'denominations' => [10000 => 2],
            'note' => 'Owner cash collection.',
        ])->assertRedirect();

        $this->assertDatabaseHas('vault_denomination_balances', [
            'denomination_id' => 10000,
            'quantity' => 3,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'cashier_vault_withdraw',
            'entity_type' => 'cashier_vault',
            'entity_id' => $cashier->id,
        ]);
    }

    public function test_cashier_cannot_manually_mutate_main_vault(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);

        $this->actingAs($cashier)->post('/cashier/vault/entries', [
            'entry_type' => 'vault_in',
            'denominations' => [10000 => 1],
        ])->assertNotFound();

        $this->assertDatabaseMissing('cash_denomination_logs', [
            'entry_type' => 'vault_in',
            'created_by' => $cashier->id,
        ]);
    }

    public function test_only_one_active_cashier_can_exist_through_admin_actions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create([
            'role' => 'cashier',
            'is_active' => true,
            'username' => 'cashierone',
            'email' => 'cashierone@example.test',
        ]);

        $this->actingAs($admin)->post('/admin/actions/users', [
            'username' => 'cashiertwo',
            'email' => 'cashiertwo@example.test',
            'full_name' => 'Cashier Two',
            'role' => 'cashier',
            'password' => 'password123',
            'pin' => '3333',
            'is_active' => true,
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['username' => 'cashiertwo']);

        $this->actingAs($admin)->post('/admin/actions/users', [
            'username' => 'cashierinactive',
            'email' => 'cashierinactive@example.test',
            'full_name' => 'Inactive Cashier Two',
            'role' => 'cashier',
            'password' => 'password123',
            'pin' => '4444',
            'is_active' => false,
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['username' => 'cashierinactive']);
    }

    public function test_admin_withdraw_cannot_exceed_denomination_stock(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'cashier', 'is_active' => true]);

        $this->actingAs($admin)->post('/admin/actions/vault/entries', [
            'entry_type' => 'vault_in',
            'denominations' => [10000 => 1],
        ])->assertRedirect();

        $this->actingAs($admin)->post('/admin/actions/vault/entries', [
            'entry_type' => 'vault_out',
            'denominations' => [10000 => 2],
        ])->assertSessionHasErrors('denominations');

        $this->assertDatabaseHas('vault_denomination_balances', [
            'denomination_id' => 10000,
            'quantity' => 1,
        ]);
    }
}
