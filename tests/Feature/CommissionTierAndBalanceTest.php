<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientBalanceException;
use App\Models\ActivityLog;
use App\Models\CommissionTier;
use App\Models\Company;
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
        $company = $this->company();
        $catchAll = $this->tier($company, 'cash_in', 1, 999_999_999_999, 100);
        $specific = $this->tier($company, 'cash_in', 10_000, 100_000, 500);

        $repo = app(CommissionTierRepository::class);

        $this->assertSame($specific->id, $repo->findForCompanyFeature($company->id, 'cash_in', 50_000)->id);
        $this->assertSame($catchAll->id, $repo->findForCompanyFeature($company->id, 'cash_in', 500_000)->id);
    }

    public function test_calculator_reads_company_feature_tier_and_applies_mmk_rounding(): void
    {
        $company = $this->company();
        $this->tier(
            $company,
            'cash_in',
            1,
            999_999_999_999,
            1,
            feeType: 'PERCENTAGE',
            commission: 0.2,
            commissionType: 'PERCENTAGE',
            additionalFee: 250,
        );
        [$account] = $this->createAccountForCompany($company, true);

        $calc = app(TransactionFeeCalculator::class);
        $fees = $calc->resolveFees($account, 100_000, TransactionFeeCalculator::MODE_CASH_IN);

        $this->assertSame('1300.00', $fees['customer_fee']);
        $this->assertSame('250.00', $fees['additional_fee']);
        $this->assertSame('200.00', $calc->commission($account, 100_000, TransactionFeeCalculator::COMMISSION_SEND));
    }

    public function test_cash_in_and_cash_out_use_separate_feature_tiers(): void
    {
        $company = $this->company();
        $this->tier($company, 'cash_in', 1, 999_999_999_999, 0.1, feeType: 'PERCENTAGE');
        $this->tier($company, 'cash_out', 1, 999_999_999_999, 0.2, feeType: 'PERCENTAGE');
        [$account] = $this->createAccountForCompany($company);

        $calc = app(TransactionFeeCalculator::class);

        $this->assertSame('100.00', $calc->resolveFees($account, 100_000, TransactionFeeCalculator::MODE_CASH_IN)['customer_fee']);
        $this->assertSame('200.00', $calc->resolveFees($account, 100_000, TransactionFeeCalculator::MODE_CASH_OUT)['customer_fee']);
    }

    public function test_api_tier_lookup_uses_same_inclusive_boundary_as_calculator(): void
    {
        $company = $this->company();
        $tier = $this->tier($company, 'cash_in', 1, 10_000, 400, commission: 80);
        $token = $this->tokenForRole('teller', 'tier_lookup');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/commission-tiers/lookup?company_id='.$company->id.'&feature=cash_in&amount=10000')
            ->assertOk()
            ->assertJsonPath('data.id', $tier->id)
            ->assertJsonPath('data.company_id', $company->id)
            ->assertJsonPath('data.feature', 'cash_in')
            ->assertJsonPath('data.fee_amount', '400.0000');
    }

    public function test_debit_balance_rejects_overdraw(): void
    {
        $company = $this->company();
        [$account] = $this->createAccountForCompany($company, false, 5_000);

        $this->expectException(InsufficientBalanceException::class);

        app(AccountRepository::class)->debitBalance($account->id, 10_000);
    }

    public function test_debit_balance_decrements_when_sufficient(): void
    {
        $company = $this->company();
        [$account] = $this->createAccountForCompany($company, false, 20_000);

        $updated = app(AccountRepository::class)->debitBalance($account->id, 7_500);

        $this->assertSame('12500.00', $updated->balance);
    }

    public function test_balance_adjust_endpoint_writes_activity_log(): void
    {
        $company = $this->company();
        [$account] = $this->createAccountForCompany($company, false, 1_000);
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
            ->assertJsonPath('data.new_balance', '1250.50');

        $log = ActivityLog::query()
            ->where('entity_type', 'account')
            ->where('entity_id', $account->id)
            ->where('action', 'balance_adjust')
            ->firstOrFail();

        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame('250.50', $log->details['amount']);
        $this->assertSame('Owner top-up', $log->details['remark']);
    }

    public function test_balance_adjust_endpoint_requires_owner_role(): void
    {
        $company = $this->company();
        [$account] = $this->createAccountForCompany($company, false, 500);
        $token = $this->tokenForRole('teller', 'teller_balance');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/accounts/'.$account->id.'/balance-adjust', ['amount' => 100])
            ->assertForbidden();

        $this->assertSame(0, ActivityLog::query()->count());
    }

    private function company(): Company
    {
        return Company::query()->create([
            'name' => 'Wave-'.uniqid('', true),
            'category' => 'Pay',
        ]);
    }

    private function createAccountForCompany(Company $company, bool $isAgent = false, int $balance = 0): array
    {
        $account = \App\Models\Account::query()->create([
            'company_id' => $company->id,
            'account_name' => 'Ops-'.uniqid('', true),
            'phone_number' => '09'.random_int(100000000, 999999999),
            'balance' => $balance,
            'is_agent' => $isAgent,
        ]);

        return [$account];
    }

    private function tier(
        Company $company,
        string $feature,
        int $amountFrom,
        int $amountTo,
        float $fee,
        string $feeType = 'FIXED',
        float $commission = 0,
        string $commissionType = 'FIXED',
        float $additionalFee = 0,
    ): CommissionTier {
        return CommissionTier::query()->create([
            'company_id' => $company->id,
            'feature' => $feature,
            'amount_from' => $amountFrom,
            'amount_to' => $amountTo,
            'fee_type' => $feeType,
            'fee_amount' => $fee,
            'comm_type' => $commissionType,
            'comm_amount' => $commission,
            'additional_fee_type' => 'FIXED',
            'additional_fee_amount' => $additionalFee,
            'is_active' => true,
        ]);
    }

    private function tokenForRole(string $role, string $username): string
    {
        $user = User::factory()->create([
            'username' => $username,
            'role' => $role,
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);

        return app(NgweLweTokenService::class)->create($user);
    }
}
