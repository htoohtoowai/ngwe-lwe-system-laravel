<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashFloatAssignment;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\TransactionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalBranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('operational branch isolation tests');
        parent::setUp();
    }

    public function test_transaction_repository_snapshots_creator_branch(): void
    {
        $branch = $this->branch('BR-002', 'Branch 2');
        $teller = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $branch->id,
        ]);
        [$account] = $this->createCompanyAccountFixture(100000);

        $transaction = app(TransactionRepository::class)->create([
            'branch_id' => Branch::main()->id,
            'transaction_type' => 'cash_in',
            'account_id' => $account->id,
            'amount' => '1000.00',
            'created_by' => $teller->id,
            'status' => 'PENDING_CASHIER_CONFIRM',
        ]);

        $this->assertSame($branch->id, $transaction->branch_id);
    }

    public function test_cashier_cannot_mark_other_branch_transaction_notification_as_read(): void
    {
        $main = Branch::main();
        $other = $this->branch('BR-002', 'Branch 2');

        $cashierA = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $main->id,
        ]);
        $tellerB = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $other->id,
        ]);
        [$account] = $this->createCompanyAccountFixture(100000);

        $transaction = Transaction::query()
            ->withoutGlobalScopes()
            ->create([
                'branch_id' => $other->id,
                'transaction_type' => 'cash_in',
                'account_id' => $account->id,
                'amount' => '1000.00',
                'created_by' => $tellerB->id,
                'status' => 'PENDING_CASHIER_CONFIRM',
            ]);

        $this->actingAs($cashierA)
            ->post("/cashier/notifications/{$transaction->id}/read")
            ->assertNotFound();
    }

    public function test_cashier_cannot_confirm_other_branch_pending_transaction(): void
    {
        $main = Branch::main();
        $other = $this->branch('BR-002', 'Branch 2');

        $cashierA = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $main->id,
        ]);
        $tellerB = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $other->id,
        ]);
        [$account] = $this->createCompanyAccountFixture(100000);

        $transaction = Transaction::query()
            ->withoutGlobalScopes()
            ->create([
                'branch_id' => $other->id,
                'transaction_type' => 'cash_in',
                'account_id' => $account->id,
                'amount' => '1000.00',
                'created_by' => $tellerB->id,
                'status' => 'PENDING_CASHIER_CONFIRM',
            ]);

        $this->actingAs($cashierA)
            ->post("/cashier/transactions/{$transaction->id}/confirm-cash-in", [
                'pin' => '1234',
                'received_denominations' => ['1000' => 1],
                'change_denominations' => [],
            ])
            ->assertNotFound();

        $this->assertSame(
            'PENDING_CASHIER_CONFIRM',
            $transaction->fresh()->status,
        );
    }

    public function test_cashier_cannot_confirm_other_branch_float_return(): void
    {
        $main = Branch::main();
        $other = $this->branch('BR-002', 'Branch 2');

        $cashierA = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $main->id,
        ]);
        $cashierB = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $other->id,
        ]);
        $tellerB = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $other->id,
        ]);

        $float = CashFloatAssignment::query()
            ->withoutGlobalScopes()
            ->create([
                'branch_id' => $other->id,
                'employee_id' => $tellerB->id,
                'issued_by' => $cashierB->id,
                'status' => 'PENDING_RECONCILIATION',
                'total_amount' => '1000.00',
                'current_balance' => '1000.00',
                'return_denominations_json' => ['1000' => 1],
            ]);

        $this->actingAs($cashierA)
            ->post("/cashier/cash-floats/{$float->id}/confirm-return", [
                'pin' => '1234',
            ])
            ->assertNotFound();

        $this->assertSame('PENDING_RECONCILIATION', $float->fresh()->status);
    }

    private function branch(string $code, string $name): Branch
    {
        return Branch::query()->create([
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);
    }
}
