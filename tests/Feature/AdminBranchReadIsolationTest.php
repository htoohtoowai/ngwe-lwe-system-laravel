<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VaultTransaction;
use App\Repositories\CashDenominationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminBranchReadIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('admin branch read isolation tests');
        parent::setUp();
    }

    public function test_admin_transaction_page_returns_only_selected_branch(): void
    {
        $main = Branch::main();
        $branchTwo = $this->branchTwo();
        $admin = User::factory()->create(['role' => 'admin']);
        $tellerA = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $main->id,
        ]);
        $tellerB = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $branchTwo->id,
        ]);
        [$account] = $this->createCompanyAccountFixture(100000);

        $mainTxn = Transaction::query()
            ->withoutGlobalScopes()
            ->create([
                'branch_id' => $main->id,
                'transaction_type' => 'cash_in',
                'account_id' => $account->id,
                'amount' => '1000.00',
                'created_by' => $tellerA->id,
                'status' => 'COMPLETED',
            ]);

        $branchTxn = Transaction::query()
            ->withoutGlobalScopes()
            ->create([
                'branch_id' => $branchTwo->id,
                'transaction_type' => 'cash_in',
                'account_id' => $account->id,
                'amount' => '2000.00',
                'created_by' => $tellerB->id,
                'status' => 'COMPLETED',
            ]);

        $this->actingAs($admin)
            ->get("/admin/transactions?branch_id={$branchTwo->id}")
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('admin/transactions/All')
                    ->where('selectedBranchId', $branchTwo->id)
                    ->has('rows', 1)
                    ->where('rows.0.id', $branchTxn->id)
                    ->where('rows.0.branch_id', $branchTwo->id),
            );

        $this->assertNotSame($mainTxn->id, $branchTxn->id);
    }

    public function test_admin_vault_log_returns_only_selected_branch(): void
    {
        $main = Branch::main();
        $branchTwo = $this->branchTwo();
        $admin = User::factory()->create(['role' => 'admin']);

        VaultTransaction::query()
            ->withoutGlobalScopes()
            ->create([
                'branch_id' => $main->id,
                'batch_id' => 'MAIN-BATCH',
                'txn_type' => 'adjustment',
                'movement_type' => 'admin_to_cashier',
                'denomination' => 1000,
                'quantity' => 1,
                'performed_by' => $admin->id,
            ]);

        $branchLog = VaultTransaction::query()
            ->withoutGlobalScopes()
            ->create([
                'branch_id' => $branchTwo->id,
                'batch_id' => 'BR2-BATCH',
                'txn_type' => 'adjustment',
                'movement_type' => 'admin_to_cashier',
                'denomination' => 1000,
                'quantity' => 2,
                'performed_by' => $admin->id,
            ]);

        $this->actingAs($admin)
            ->get("/admin/vault/log?branch_id={$branchTwo->id}")
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('admin/vault/Log')
                    ->where('selectedBranchId', $branchTwo->id)
                    ->has('rows', 1)
                    ->where('rows.0.id', $branchLog->id)
                    ->where('rows.0.branch_id', $branchTwo->id),
            );
    }

    public function test_admin_vault_page_uses_selected_branch_inventory(): void
    {
        $main = Branch::main();
        $branchTwo = $this->branchTwo();
        $admin = User::factory()->create(['role' => 'admin']);
        $cashierA = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $main->id,
        ]);
        $cashierB = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branchTwo->id,
        ]);

        $vault = app(CashDenominationRepository::class);
        $vault->recordBulk(
            'vault_in',
            [1000 => 3],
            $cashierA->id,
            branchId: $main->id,
        );
        $vault->recordBulk(
            'vault_in',
            [1000 => 7],
            $cashierB->id,
            branchId: $branchTwo->id,
        );

        $this->actingAs($admin)
            ->get("/admin/vault?branch_id={$branchTwo->id}")
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('admin/Vault')
                    ->where('adminData.selectedBranchId', $branchTwo->id)
                    ->where('adminData.vaultInventory.branch_id', $branchTwo->id)
                    ->where('adminData.vaultInventory.main_vault.1000', 7),
            );
    }

    private function branchTwo(): Branch
    {
        return Branch::query()->firstOrCreate(
            ['code' => 'BR-002'],
            [
                'name' => 'Branch 2',
                'is_active' => true,
            ],
        );
    }
}
