<?php

namespace Tests\Feature;

use App\Models\AgentCommissionEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DailyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('daily report tests');
        parent::setUp();
    }

    public function test_daily_report_sums_completed_transactions_and_agent_entries(): void
    {
        $user = User::factory()->create(['role' => 'teller']);
        [$account, $company] = $this->createCompanyAccountFixture(100000, isAgent: true);
        $transaction = Transaction::query()->create([
            'transaction_type' => 'cash_out',
            'account_id' => $account->id,
            'amount' => 10000,
            'customer_fee' => 400,
            'additional_fee_amount' => 0,
            'balance_change' => 10080,
            'currency' => 'MMK',
            'fee_payment_method' => 'cash',
            'created_by' => $user->id,
            'status' => 'COMPLETED',
        ]);
        AgentCommissionEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'company_id' => $company->id,
            'direction' => 'IN',
            'base_amount' => 10000,
            'calculation_type' => 'FIXED',
            'configured_value' => 80,
            'commission_amount' => 80,
            'status' => 'EARNED',
        ]);

        $summary = app(DailyReportService::class)->summary(now()->toDateString());
        $this->assertSame('10000.00', $summary['total_cash_out']);
        $this->assertSame('80.00', $summary['total_commission']);
        $this->assertSame('400.00', $summary['total_customer_fees']);
        $this->assertSame('480.00', $summary['total_profit']);
    }
}
