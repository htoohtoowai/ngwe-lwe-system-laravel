<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ServiceType;
use App\Models\User;
use App\Repositories\AccountRepository;
use App\Repositories\CommissionTierRepository;
use App\Services\NgweLweTokenService;
use App\Services\TransactionFeeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CommissionTierAndBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('calculation tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('d', 32));
    }

    public function test_tier_lookup_prefers_specific_range_over_catch_all(): void
    {
        $serviceType = $this->serviceType();

        $catchAll = CommissionTier::query()->create([
            'service_type_id' => $serviceType->id,
            'amount_from' => 1,
            'amount_to' => 999_999_999_999,
            'fee_amount_type' => 'FIXED',
            'fee_amount_deposit' => 100,
            'fee_amount_withdraw' => 100,
            'comm_type' => 'FIXED',
            'additional_fee_type' => 'FIXED',
            'is_active' => true,
        ]);

        $specific = CommissionTier::query()->create([
            'service_type_id' => $serviceType->id,
            'amount_from' => 10_000,
            'amount_to' => 100_000,
            'fee_amount_type' => 'FIXED',
            'fee_amount_deposit' => 500,
            'fee_amount_withdraw' => 500,
            'comm_type' => 'FIXED',
            'additional_fee_type' => 'FIXED',
            'is_active' => true,
        ]);

        $repo = app(CommissionTierRepository::class);

        $this->assertSame($specific->id, $repo->findForAmount($serviceType->id, 50_000)->id);
        $this->assertSame($catchAll->id, $repo->findForAmount($serviceType->id, 500_000)->id);
    }

    public function test_calculator_reads_tier_from_db_and_applies_mmk_rounding(): void
    {
        $serviceType = $this->serviceType();

        CommissionTier::query()->create([
            'service_type_id' => $serviceType->id,
            'amount_from' => 1,
            'amount_to' => 999_999_999_999,
            'fee_amount_type' => 'PERCENTAGE',
            'fee_amount_deposit' => 0.01,
            'fee_amount_withdraw' => 0.01,
            'comm_type' => 'PERCENTAGE',
            'comm_deposit' => 0.002,
            'comm_withdraw' => 0.002,
            'additional_fee_type' => 'FIXED',
            'additional_fee_deposit_amount' => 250,
            'additional_fee_withdraw_amount' => 250,
            'is_active' => true,
        ]);

        $account = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'Wave Ops',
            'phone_number' => '09000000001',
            'balance' => 0,
        ]);

        $calc = app(TransactionFeeCalculator::class);
        $fees = $calc->resolveFees($account, 100_000, TransactionFeeCalculator::MODE_CASH_IN);

        $this->assertSame('1300.00', $fees['customer_fee']);
        $this->assertSame('250.00', $fees['additional_fee']);
        $this->assertSame('200.00', $calc->commission($account, 100_000, TransactionFeeCalculator::COMMISSION_SEND));
    }

    public function test_debit_balance_rejects_overdraw(): void
    {
        $serviceType = $this->serviceType();
        $account = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'Ops',
            'phone_number' => '09000000002',
            'balance' => 5_000,
        ]);

        $repo = app(AccountRepository::class);

        $this->expectException(InsufficientBalanceException::class);

        $repo->debitBalance($account->id, 10_000);
    }

    public function test_debit_balance_decrements_when_sufficient(): void
    {
        $serviceType = $this->serviceType();
        $account = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'Ops',
            'phone_number' => '09000000003',
            'balance' => 20_000,
        ]);

        $updated = app(AccountRepository::class)->debitBalance($account->id, 7_500);

        $this->assertSame('12500.00', $updated->balance);
    }

    public function test_balance_adjust_endpoint_writes_activity_log(): void
    {
        $serviceType = $this->serviceType();
        $account = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'Wave Main',
            'phone_number' => '09000000004',
            'balance' => 1_000,
        ]);

        $owner = User::factory()->create([
            'username' => 'balance_owner',
            'role' => 'admin',
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);
        $token = app(NgweLweTokenService::class)->create($owner);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/accounts/'.$account->id.'/balance-adjust', [
                'amount' => 250.5,
                'remark' => 'Owner top-up',
            ])
            ->assertOk()
            ->assertJsonPath('data.old_balance', '1000.00')
            ->assertJsonPath('data.new_balance', '1250.50')
            ->assertJsonPath('data.delta', '250.50');

        $log = ActivityLog::query()
            ->where('entity_type', 'account')
            ->where('entity_id', $account->id)
            ->where('action', 'balance_adjust')
            ->firstOrFail();

        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame('250.50', $log->details['amount']);
        $this->assertSame('1000.00', $log->details['old_balance']);
        $this->assertSame('1250.50', $log->details['new_balance']);
        $this->assertSame('Owner top-up', $log->details['remark']);
    }

    public function test_balance_adjust_endpoint_requires_owner_role(): void
    {
        $serviceType = $this->serviceType();
        $account = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'Wave Sub',
            'phone_number' => '09000000005',
            'balance' => 500,
        ]);

        $employee = User::factory()->create([
            'username' => 'teller_balance',
            'role' => 'teller',
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);
        $token = app(NgweLweTokenService::class)->create($employee);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/accounts/'.$account->id.'/balance-adjust', [
                'amount' => 100,
            ])
            ->assertForbidden();

        $this->assertSame(0, ActivityLog::query()->count());
    }

    private function serviceType(): ServiceType
    {
        $company = Company::query()->create([
            'name' => 'Wave Money',
            'category' => 'Pay',
        ]);

        return ServiceType::query()->create([
            'company_id' => $company->id,
            'name' => 'Cash In',
            'operation' => 'CashIn',
        ]);
    }
}
