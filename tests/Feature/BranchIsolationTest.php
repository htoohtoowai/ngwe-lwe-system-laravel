<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\CashFloatAssignment;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('branch isolation tests');
        parent::setUp();
    }

    public function test_database_allows_one_cashier_per_branch_but_rejects_two_in_the_same_branch(): void
    {
        $main = Branch::main();
        $branchTwo = $this->branchTwo();

        User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $main->id,
        ]);

        User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branchTwo->id,
        ]);

        $this->expectException(QueryException::class);

        User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $main->id,
        ]);
    }

    public function test_database_allows_only_one_admin(): void
    {
        User::factory()->create(['role' => 'admin']);

        $this->expectException(QueryException::class);

        User::factory()->create(['role' => 'admin']);
    }

    public function test_cashier_user_and_operational_queries_are_scoped_to_own_branch(): void
    {
        $main = Branch::main();
        $branchTwo = $this->branchTwo();

        $cashierA = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $main->id,
        ]);
        $cashierB = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branchTwo->id,
        ]);
        $tellerA = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $main->id,
        ]);
        $tellerB = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $branchTwo->id,
        ]);

        $company = Company::query()->create([
            'name' => 'Branch Isolation Pay',
            'category' => 'Pay',
            'is_active' => true,
        ]);
        $account = Account::query()->create([
            'company_id' => $company->id,
            'branch_id' => null,
            'account_name' => 'Shared Test Account',
            'account_type' => 'PAY',
            'account_identifier' => 'BRANCH-SHARED-001',
            'balance' => '1000000.00',
            'is_active' => true,
            'is_fee_account' => false,
            'is_agent' => false,
        ]);

        $this->actingAs($tellerA);
        $transactionA = Transaction::query()->create([
            'transaction_type' => 'cash_in',
            'account_id' => $account->id,
            'amount' => '1000.00',
            'created_by' => $tellerA->id,
            'status' => 'PENDING_CASHIER_CONFIRM',
        ]);

        auth()->logout();
        $this->actingAs($tellerB);
        $transactionB = Transaction::query()->create([
            'transaction_type' => 'cash_in',
            'account_id' => $account->id,
            'amount' => '2000.00',
            'created_by' => $tellerB->id,
            'status' => 'PENDING_CASHIER_CONFIRM',
        ]);

        auth()->logout();
        $this->actingAs($cashierA);

        $this->assertSame([$tellerA->id], User::query()
            ->where('role', 'teller')
            ->pluck('id')
            ->all());
        $this->assertSame([$transactionA->id], Transaction::query()
            ->pluck('id')
            ->all());
        $this->assertNull(Transaction::query()->find($transactionB->id));

        auth()->logout();
        $this->actingAs($cashierB);

        $this->assertSame([$tellerB->id], User::query()
            ->where('role', 'teller')
            ->pluck('id')
            ->all());
        $this->assertSame([$transactionB->id], Transaction::query()
            ->pluck('id')
            ->all());
    }

    public function test_accounts_are_shared_or_visible_only_to_their_branch(): void
    {
        $main = Branch::main();
        $branchTwo = $this->branchTwo();
        $company = Company::query()->create([
            'name' => 'Scoped Accounts Pay',
            'category' => 'Pay',
            'is_active' => true,
        ]);

        $shared = $this->account($company->id, null, 'SHARED');
        $branchA = $this->account($company->id, $main->id, 'BR-A');
        $branchB = $this->account($company->id, $branchTwo->id, 'BR-B');

        $tellerA = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $main->id,
        ]);

        $this->actingAs($tellerA);

        $ids = Account::query()->orderBy('id')->pluck('id')->all();

        $this->assertContains($shared->id, $ids);
        $this->assertContains($branchA->id, $ids);
        $this->assertNotContains($branchB->id, $ids);
    }

    public function test_main_vault_balances_are_isolated_by_branch(): void
    {
        $main = Branch::main();
        $branchTwo = $this->branchTwo();

        $cashierA = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $main->id,
        ]);
        $cashierB = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branchTwo->id,
        ]);

        $vault = app(CashDenominationRepository::class);

        $this->actingAs($cashierA);
        $vault->recordBulk('vault_in', [10000 => 5], $cashierA->id);
        $this->assertSame(5, $vault->getVaultBalance()[10000]);

        auth()->logout();
        $this->actingAs($cashierB);
        $vault->recordBulk('vault_in', [10000 => 2], $cashierB->id);
        $this->assertSame(2, $vault->getVaultBalance()[10000]);

        $vault->recordBulk('vault_out', [10000 => 1], $cashierB->id);
        $this->assertSame(1, $vault->getVaultBalance()[10000]);

        auth()->logout();
        $this->actingAs($cashierA);
        $this->assertSame(5, $vault->getVaultBalance()[10000]);
    }

    public function test_cash_float_queries_are_scoped_to_cashier_branch(): void
    {
        $main = Branch::main();
        $branchTwo = $this->branchTwo();

        $cashierA = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $main->id,
        ]);
        $cashierB = User::factory()->create([
            'role' => 'cashier',
            'branch_id' => $branchTwo->id,
        ]);
        $tellerA = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $main->id,
        ]);
        $tellerB = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $branchTwo->id,
        ]);

        $this->actingAs($cashierA);
        $floatA = CashFloatAssignment::query()->create([
            'employee_id' => $tellerA->id,
            'issued_by' => $cashierA->id,
            'status' => 'PENDING_RECEIPT',
            'total_amount' => '10000.00',
            'current_balance' => '0.00',
        ]);

        auth()->logout();
        $this->actingAs($cashierB);
        $floatB = CashFloatAssignment::query()->create([
            'employee_id' => $tellerB->id,
            'issued_by' => $cashierB->id,
            'status' => 'PENDING_RECEIPT',
            'total_amount' => '20000.00',
            'current_balance' => '0.00',
        ]);

        $this->assertSame([$floatB->id], CashFloatAssignment::query()->pluck('id')->all());

        auth()->logout();
        $this->actingAs($cashierA);
        $this->assertSame([$floatA->id], CashFloatAssignment::query()->pluck('id')->all());
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

    private function account(int $companyId, ?int $branchId, string $suffix): Account
    {
        return Account::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'account_name' => "Account {$suffix}",
            'account_type' => 'PAY',
            'account_identifier' => "ACC-{$suffix}",
            'balance' => '1000000.00',
            'is_active' => true,
            'is_fee_account' => false,
            'is_agent' => false,
        ]);
    }
}
