<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashFloatAssignment;
use App\Models\CashFloatDenomination;
use App\Models\Company;
use App\Models\DailyReconciliationLog;
use App\Models\DailySummary;
use App\Models\ServiceType;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DailyReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('daily report tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('r', 32));
    }

    public function test_owner_can_view_daily_summary_for_completed_transactions(): void
    {
        [$owner, $token] = $this->owner();
        [$account, $toAccount] = $this->accounts();
        $date = '2026-07-02';

        $this->transaction($owner, $account, 'cash_in', 1_000, 100, 50, $date.' 09:00:00');
        $this->transaction($owner, $account, 'cash_out', 2_000, 200, 75, $date.' 10:00:00');
        $this->transaction($owner, $account, 'transfer', 3_000, 300, 25, $date.' 11:00:00', [
            'to_account_id' => $toAccount->id,
        ]);
        $this->transaction($owner, $account, 'exchange', 4_000, 400, 125, $date.' 12:00:00');
        $this->transaction($owner, $account, 'cash_in', 5_000, 500, 500, $date.' 13:00:00', [
            'status' => 'PENDING_CASHIER_CONFIRM',
        ]);
        $this->transaction($owner, $account, 'cash_out', 9_000, 900, 900, $date.' 14:00:00', [
            'status' => 'CANCELLED',
        ]);
        $this->transaction($owner, $account, 'cash_in', 7_000, 700, 700, '2026-07-01 09:00:00');

        app(CashDenominationRepository::class)->recordBulk('vault_in', [10_000 => 5], $owner->id);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/daily-summary?date='.$date)
            ->assertOk()
            ->assertJsonPath('data.summary_date', $date)
            ->assertJsonPath('data.total_cash_in', '1000.00')
            ->assertJsonPath('data.total_cash_out', '2000.00')
            ->assertJsonPath('data.total_transfer', '3000.00')
            ->assertJsonPath('data.total_exchange', '4000.00')
            ->assertJsonPath('data.total_commission', '1000.00')
            ->assertJsonPath('data.total_customer_fees', '275.00')
            ->assertJsonPath('data.total_profit', '1275.00')
            ->assertJsonPath('data.transaction_count', 4)
            ->assertJsonPath('data.pending_cash_in_count', 1)
            ->assertJsonPath('data.main_vault_total', '50000.00')
            ->assertJsonPath('data.total_digital', '125000.00')
            ->assertJsonPath('data.grand_total', '175000.00');
    }

    public function test_owner_can_close_daily_reconciliation_and_list_logs(): void
    {
        [$owner, $token] = $this->owner();
        [$account] = $this->accounts();
        $employee = $this->userWithRole('employee', 'employee');
        $date = '2026-07-02';

        $this->transaction($owner, $account, 'cash_in', 1_000, 100, 50, $date.' 09:00:00');
        app(CashDenominationRepository::class)->recordBulk('vault_in', [10_000 => 5], $owner->id);

        $float = CashFloatAssignment::query()->create([
            'employee_id' => $employee->id,
            'issued_by' => $owner->id,
            'status' => 'ACTIVE',
            'total_amount' => '10000.00',
            'current_balance' => '10000.00',
        ]);
        CashFloatDenomination::query()->create([
            'float_id' => $float->id,
            'denomination' => 10_000,
            'quantity' => 1,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/reports/daily-reconciliation', [
                'date' => $date,
                'notes' => 'End of day close',
            ])
            ->assertCreated()
            ->assertJsonPath('data.recon_date', $date)
            ->assertJsonPath('data.closed_by', $owner->id)
            ->assertJsonPath('data.notes', 'End of day close')
            ->assertJsonPath('data.total_cash_in', '1000.00')
            ->assertJsonPath('data.main_vault_total', '50000.00')
            ->assertJsonPath('data.employee_floats_total', '10000.00')
            ->assertJsonPath('data.total_cash', '60000.00')
            ->assertJsonPath('data.grand_total', '185000.00')
            ->assertJsonPath('data.employee_snapshots.0.employee_id', $employee->id);

        $vaultRows = collect($response->json('data.vault_snapshot.denomination_rows'));
        $this->assertSame(5, $vaultRows->firstWhere('denomination', 10_000)['quantity'] ?? null);

        $this->assertSame(1, DailySummary::query()->whereDate('summary_date', $date)->count());
        $this->assertSame(1, DailyReconciliationLog::query()->whereDate('recon_date', $date)->count());

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/daily-reconciliations?date_from=2026-07-01&date_to=2026-07-03')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.recon_date', $date)
            ->assertJsonPath('data.0.closed_by_name', $owner->full_name);
    }

    public function test_non_owner_cannot_access_reports(): void
    {
        $employee = $this->userWithRole('employee', 'employee');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($employee))
            ->getJson('/api/reports/daily-summary?date=2026-07-02')
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function owner(): array
    {
        $owner = $this->userWithRole('owner', 'owner');

        return [$owner, $this->tokenFor($owner)];
    }

    /**
     * @return array{0: Account, 1: Account}
     */
    private function accounts(): array
    {
        $company = Company::query()->create([
            'name' => 'Demo Pay',
            'category' => 'Pay',
            'is_active' => true,
        ]);
        $serviceType = ServiceType::query()->create([
            'company_id' => $company->id,
            'name' => 'Cash In',
            'operation' => 'CashIn',
            'is_active' => true,
        ]);
        $account = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'Primary',
            'phone_number' => '0911111111',
            'balance' => '100000.00',
            'is_active' => true,
        ]);
        $toAccount = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'Secondary',
            'phone_number' => '0922222222',
            'balance' => '25000.00',
            'is_active' => true,
        ]);

        return [$account, $toAccount];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transaction(
        User $creator,
        Account $account,
        string $type,
        int $amount,
        int $commission,
        int $customerFee,
        string $createdAt,
        array $extra = [],
    ): Transaction {
        $transaction = Transaction::query()->create([
            'transaction_type' => $type,
            'account_id' => $account->id,
            'amount' => $amount,
            'commission_amount' => $commission,
            'customer_fee' => $customerFee,
            'additional_fee_amount' => 0,
            'balance_change' => $amount,
            'currency' => 'MMK',
            'created_by' => $creator->id,
            'status' => 'COMPLETED',
            ...$extra,
        ]);

        $transaction->forceFill(['created_at' => $createdAt])->save();

        return $transaction->refresh();
    }

    private function userWithRole(string $role, string $username): User
    {
        return User::factory()->create([
            'username' => $username.'_'.uniqid('', true),
            'full_name' => ucfirst($username).' User',
            'role' => $role,
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return app(NgweLweTokenService::class)->create($user);
    }
}
