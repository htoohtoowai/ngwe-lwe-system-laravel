<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentCommissionEntry;
use App\Models\Branch;
use App\Models\DailyReconciliationLog;
use App\Models\DailySummary;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Services\DailyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BranchDailyReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('branch daily report tests');
        parent::setUp();
    }

    public function test_summary_uses_only_selected_branch_transactions_and_cash(): void
    {
        $main = Branch::main();
        $branchTwo = $this->branchTwo();
        $tellerA = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $main->id,
        ]);
        $tellerB = User::factory()->create([
            'role' => 'teller',
            'branch_id' => $branchTwo->id,
        ]);

        [$sharedAccount, $company] = $this->createCompanyAccountFixture(
            100000,
            isAgent: true,
        );

        $branchAccount = Account::query()
            ->withoutGlobalScopes()
            ->create([
                'company_id' => $company->id,
                'branch_id' => $branchTwo->id,
                'account_name' => 'Branch Two Account',
                'account_type' => 'PAY',
                'account_identifier' => 'BR2-001',
                'balance' => '50000.00',
                'is_active' => true,
                'is_fee_account' => false,
                'is_agent' => false,
            ]);

        $transactionA = Transaction::query()
            ->withoutGlobalScopes()
            ->create([
                'branch_id' => $main->id,
                'transaction_type' => 'cash_out',
                'account_id' => $sharedAccount->id,
                'amount' => '1000.00',
                'customer_fee' => '10.00',
                'created_by' => $tellerA->id,
                'status' => 'COMPLETED',
            ]);

        $transactionB = Transaction::query()
            ->withoutGlobalScopes()
            ->create([
                'branch_id' => $branchTwo->id,
                'transaction_type' => 'cash_out',
                'account_id' => $sharedAccount->id,
                'amount' => '2000.00',
                'customer_fee' => '20.00',
                'created_by' => $tellerB->id,
                'status' => 'COMPLETED',
            ]);

        AgentCommissionEntry::query()->create([
            'transaction_id' => $transactionA->id,
            'account_id' => $sharedAccount->id,
            'company_id' => $company->id,
            'direction' => 'IN',
            'base_amount' => '1000.00',
            'calculation_type' => 'FIXED',
            'configured_value' => '5.0000',
            'commission_amount' => '5.00',
            'status' => 'EARNED',
        ]);

        AgentCommissionEntry::query()->create([
            'transaction_id' => $transactionB->id,
            'account_id' => $sharedAccount->id,
            'company_id' => $company->id,
            'direction' => 'IN',
            'base_amount' => '2000.00',
            'calculation_type' => 'FIXED',
            'configured_value' => '7.0000',
            'commission_amount' => '7.00',
            'status' => 'EARNED',
        ]);

        $vault = app(CashDenominationRepository::class);
        $vault->recordBulk(
            'vault_in',
            [1000 => 3],
            $tellerB->id,
            branchId: $branchTwo->id,
        );

        $summary = app(DailyReportService::class)->summary(
            now()->toDateString(),
            $branchTwo->id,
        );

        $this->assertSame($branchTwo->id, $summary['branch_id']);
        $this->assertSame('2000.00', $summary['total_cash_out']);
        $this->assertSame('20.00', $summary['total_customer_fees']);
        $this->assertSame('7.00', $summary['total_commission']);
        $this->assertSame('3000.00', $summary['main_vault_total']);
        $this->assertSame('50000.00', $summary['total_digital']);
        $this->assertSame('100000.00', $summary['shared_global_digital_total']);
        $this->assertSame('53000.00', $summary['grand_total']);

        $branchSnapshot = collect($summary['account_snapshots'])
            ->firstWhere('id', $branchAccount->id);
        $sharedSnapshot = collect($summary['account_snapshots'])
            ->firstWhere('id', $sharedAccount->id);

        $this->assertSame('branch', $branchSnapshot['scope']);
        $this->assertSame('shared', $sharedSnapshot['scope']);
    }

    public function test_closing_same_date_is_saved_separately_per_branch(): void
    {
        $main = Branch::main();
        $branchTwo = $this->branchTwo();
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(DailyReportService::class);
        $date = now()->toDateString();

        $service->close($admin, $date, 'Main close', $main->id);
        $service->close($admin, $date, 'Branch 2 close', $branchTwo->id);

        $this->assertTrue(
            DailySummary::query()
                ->withoutGlobalScopes()
                ->where('branch_id', $main->id)
                ->whereDate('summary_date', $date)
                ->exists(),
        );

        $this->assertTrue(
            DailySummary::query()
                ->withoutGlobalScopes()
                ->where('branch_id', $branchTwo->id)
                ->whereDate('summary_date', $date)
                ->exists(),
        );

        $this->assertSame(
            2,
            DailySummary::query()
                ->withoutGlobalScopes()
                ->whereDate('summary_date', $date)
                ->count(),
        );

        $this->assertSame(
            1,
            DailyReconciliationLog::query()
                ->withoutGlobalScopes()
                ->where('branch_id', $main->id)
                ->whereDate('recon_date', $date)
                ->count(),
        );

        $this->assertSame(
            1,
            DailyReconciliationLog::query()
                ->withoutGlobalScopes()
                ->where('branch_id', $branchTwo->id)
                ->whereDate('recon_date', $date)
                ->count(),
        );
    }

    public function test_admin_reports_page_loads_requested_branch_context(): void
    {
        $branchTwo = $this->branchTwo();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get("/admin/reports?branch_id={$branchTwo->id}")
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('admin/Reports')
                    ->where('adminData.selectedBranchId', $branchTwo->id)
                    ->where('adminData.dailySummary.branch_id', $branchTwo->id),
            );
    }

    public function test_branch_close_endpoint_requires_and_persists_selected_branch(): void
    {
        $branchTwo = $this->branchTwo();
        $admin = User::factory()->create(['role' => 'admin']);
        $date = now()->toDateString();

        $this->actingAs($admin)
            ->post('/admin/branch-reporting/close-day', [
                'branch_id' => $branchTwo->id,
                'date' => $date,
                'notes' => 'Branch close',
            ])
            ->assertRedirect();

        $this->assertTrue(
            DailyReconciliationLog::query()
                ->withoutGlobalScopes()
                ->where('branch_id', $branchTwo->id)
                ->where('closed_by', $admin->id)
                ->whereDate('recon_date', $date)
                ->exists(),
        );
    }

    public function test_reconciliation_page_filters_to_selected_branch(): void
    {
        $main = Branch::main();
        $branchTwo = $this->branchTwo();
        $admin = User::factory()->create(['role' => 'admin']);
        $date = now()->toDateString();
        $service = app(DailyReportService::class);

        $service->close($admin, $date, 'Main close', $main->id);
        $service->close($admin, $date, 'Branch 2 close', $branchTwo->id);

        $this->actingAs($admin)
            ->get("/admin/reports/reconciliations?branch_id={$branchTwo->id}")
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('admin/reports/Reconciliations')
                    ->where('selectedBranchId', $branchTwo->id)
                    ->has('rows', 1)
                    ->where('rows.0.branch_id', $branchTwo->id),
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
