<?php

namespace Tests\Feature;

use App\Enums\AccountFeature;
use App\Models\Account;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ExchangeRateAndTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('exchange tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('f', 32));
    }

    public function test_owner_can_create_and_update_exchange_rate(): void
    {
        [, $token] = $this->userWithToken('admin');

        $rateId = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/exchange-rates', [
                'base_currency' => 'thb',
                'quote_currency' => 'mmk',
                'base_amount' => 1,
                'buy_rate' => 145.1234,
                'sell_rate' => 148.5678,
            ])
            ->assertCreated()
            ->assertJsonPath('data.base_currency', 'THB')
            ->assertJsonPath('data.quote_currency', 'MMK')
            ->assertJsonPath('data.buy_rate', '145.1234')
            ->assertJsonPath('data.sell_rate', '148.5678')
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/exchange-rates/'.$rateId, [
                'buy_rate' => 150,
            ])
            ->assertOk()
            ->assertJsonPath('data.buy_rate', '150.0000')
            ->assertJsonPath('data.sell_rate', '148.5678');
    }

    public function test_employee_cannot_create_exchange_rate(): void
    {
        [, $employeeToken] = $this->userWithToken('teller');

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/exchange-rates', [
                'base_currency' => 'THB',
                'quote_currency' => 'MMK',
                'base_amount' => 1,
                'buy_rate' => 145,
                'sell_rate' => 148,
            ])
            ->assertForbidden();
    }

    public function test_owner_can_delete_exchange_rate(): void
    {
        [, $token] = $this->userWithToken('admin');
        $rate = ExchangeRate::query()->create([
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'base_amount' => 1,
            'buy_rate' => 145,
            'sell_rate' => 148,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/exchange-rates/'.$rate->id)
            ->assertOk()
            ->assertJsonPath('message', 'Rate deleted');

        $this->assertDatabaseMissing('exchange_rates', ['id' => $rate->id]);
    }

    public function test_latest_endpoint_returns_placeholder_when_no_rate_stored(): void
    {
        [, $token] = $this->userWithToken('teller');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/exchange-rates/latest?base=THB&quote=MMK')
            ->assertOk()
            ->assertJsonPath('data.base_currency', 'THB')
            ->assertJsonPath('data.quote_currency', 'MMK')
            ->assertJsonPath('data.buy_rate', '0.0000')
            ->assertJsonPath('data.sell_rate', '0.0000');
    }

    public function test_latest_endpoint_returns_stored_rate(): void
    {
        [, $token] = $this->userWithToken('teller');

        ExchangeRate::query()->create([
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'base_amount' => 1,
            'buy_rate' => 145,
            'sell_rate' => 148,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/exchange-rates/latest')
            ->assertOk()
            ->assertJsonPath('data.buy_rate', '145.0000')
            ->assertJsonPath('data.sell_rate', '148.0000');
    }

    public function test_latest_endpoint_uses_highest_id_rate_for_the_pair(): void
    {
        [, $token] = $this->userWithToken('teller');

        ExchangeRate::query()->create([
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'base_amount' => 1,
            'buy_rate' => 145,
            'sell_rate' => 148,
            'created_at' => now(),
            'updated_at' => now()->addDay(),
        ]);

        ExchangeRate::query()->create([
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'base_amount' => 1,
            'buy_rate' => 150,
            'sell_rate' => 153,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/exchange-rates/latest')
            ->assertOk()
            ->assertJsonPath('data.buy_rate', '150.0000')
            ->assertJsonPath('data.sell_rate', '153.0000');
    }

    public function test_exchange_transaction_credits_account_and_uses_sell_rate_for_mmk(): void
    {
        [, $token] = $this->userWithToken('admin');
        [$account, $company] = $this->accountWithBalance(0);
        $this->fixedTier($company->id, feeDeposit: 300);

        ExchangeRate::query()->create([
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'base_amount' => 1,
            'buy_rate' => 145,
            'sell_rate' => 148,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $account->id,
                'amount' => 100_000,
                'currency' => 'MMK',
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'exchange_payment_method' => 'account',
            ])
            ->assertCreated()
            ->assertJsonPath('data.transaction_type', 'exchange')
            ->assertJsonPath('data.currency', 'MMK')
            ->assertJsonPath('data.exchange_rate', '148.0000')
            ->assertJsonPath('data.customer_fee', '0.00')
            ->assertJsonPath('data.balance_change', '100000.00');

        $this->assertSame('100000.00', $account->fresh()->balance);
    }

    public function test_exchange_transaction_uses_buy_rate_for_thb(): void
    {
        [, $token] = $this->userWithToken('admin');
        [$account, $company] = $this->accountWithBalance(0);
        $this->fixedTier($company->id);

        ExchangeRate::query()->create([
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'base_amount' => 1,
            'buy_rate' => 145,
            'sell_rate' => 148,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $account->id,
                'amount' => 1_000,
                'currency' => 'THB',
                'customer_name' => 'Aung',
                'customer_phone' => '09',
            ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'THB')
            ->assertJsonPath('data.exchange_rate', '145.0000')
            ->assertJsonPath('data.balance_change', '145000.00');

        $this->assertSame('145000.00', $account->fresh()->balance);
    }

    public function test_exchange_agent_commission_uses_provider_cash_in_tier_for_mmk_to_thb(): void
    {
        [, $token] = $this->userWithToken('admin');
        [$account, $company] = $this->accountWithBalance(0);
        $this->featureCommissionTier($company->id, AccountFeature::CashIn, 'FIXED', 125);
        $this->exchangeRate();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $account->id,
                'amount' => 100_000,
                'currency' => 'MMK',
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'exchange_payment_method' => 'account',
            ])
            ->assertCreated()
            ->assertJsonPath('data.commission_amount', '125.00')
            ->assertJsonPath('data.balance_change', '100125.00');

        $this->assertSame('100125.00', $account->fresh()->balance);
    }

    public function test_exchange_agent_commission_uses_provider_cash_out_tier_for_thb_to_mmk(): void
    {
        [, $token] = $this->userWithToken('admin');
        [$account, $company] = $this->accountWithBalance(0);
        $this->featureCommissionTier($company->id, AccountFeature::CashOut, 'PERCENTAGE', 0.1);
        $this->exchangeRate();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $account->id,
                'amount' => 1_000,
                'currency' => 'THB',
                'customer_name' => 'Aung',
                'customer_phone' => '09',
            ])
            ->assertCreated()
            ->assertJsonPath('data.commission_amount', '145.00')
            ->assertJsonPath('data.balance_change', '145145.00');

        $this->assertSame('145145.00', $account->fresh()->balance);
    }

    public function test_exchange_transaction_respects_base_amount_divisor(): void
    {
        [, $token] = $this->userWithToken('admin');
        [$account, $company] = $this->accountWithBalance(0);
        $this->fixedTier($company->id);

        ExchangeRate::query()->create([
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'base_amount' => 10,
            'buy_rate' => 1450,
            'sell_rate' => 1480,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $account->id,
                'amount' => 1_000,
                'currency' => 'MMK',
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'exchange_payment_method' => 'account',
            ])
            ->assertCreated()
            ->assertJsonPath('data.exchange_rate', '148.0000');
    }

    public function test_exchange_rejects_when_no_rate_stored(): void
    {
        [, $token] = $this->userWithToken('admin');
        [$account, $company] = $this->accountWithBalance(0);
        $this->fixedTier($company->id);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $account->id,
                'amount' => 1_000,
                'currency' => 'MMK',
                'customer_name' => 'Aung',
                'customer_phone' => '09',
            ])
            ->assertStatus(422);
    }

    public function test_exchange_rejects_unsupported_currency(): void
    {
        [, $token] = $this->userWithToken('admin');
        [$account, $company] = $this->accountWithBalance(0);
        $this->fixedTier($company->id);

        ExchangeRate::query()->create([
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'base_amount' => 1,
            'buy_rate' => 145,
            'sell_rate' => 148,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $account->id,
                'amount' => 1_000,
                'currency' => 'USD',
                'customer_name' => 'Aung',
                'customer_phone' => '09',
            ])
            ->assertStatus(422);
    }

    public function test_cashier_cannot_create_exchange(): void
    {
        [, $cashierToken] = $this->userWithToken('cashier');
        [$account, $company] = $this->accountWithBalance(0);
        $this->fixedTier($company->id);

        ExchangeRate::query()->create([
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'base_amount' => 1,
            'buy_rate' => 145,
            'sell_rate' => 148,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $account->id,
                'amount' => 1_000,
                'currency' => 'MMK',
                'customer_name' => 'Aung',
                'customer_phone' => '09',
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function userWithToken(string $role): array
    {
        $user = User::factory()->create([
            'username' => $role.'_'.uniqid('', true),
            'role' => $role,
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);

        return [$user, app(NgweLweTokenService::class)->create($user)];
    }

    /**
     * @return array{0: Account, 1: Company}
     */
    private function accountWithBalance(int $balance): array
    {
        $company = Company::query()->create([
            'name' => 'Wave-'.uniqid('', true),
            'category' => 'Pay',
        ]);
        $account = Account::query()->create([
            'company_id' => $company->id,
            'account_name' => 'Wave Main',
            'phone_number' => '0900000000',
            'balance' => $balance,
            'is_agent' => true,
        ]);

        return [$account, $company];
    }

    private function fixedTier(
        int $companyId,
        int $feeDeposit = 0,
        int $feeWithdraw = 0,
        int $commDeposit = 0,
        int $commWithdraw = 0,
    ): CommissionTier {
        return $this->createCompanyTierFixtures(
            $companyId,
            $feeDeposit,
            $feeWithdraw,
            $commDeposit,
            $commWithdraw,
        );
    }
    private function featureCommissionTier(
        int $companyId,
        AccountFeature $feature,
        string $type,
        float $amount,
    ): CommissionTier {
        return CommissionTier::query()->create([
            'company_id' => $companyId,
            'feature' => $feature->value,
            'amount_from' => 1,
            'amount_to' => 999_999_999,
            'fee_type' => 'FIXED',
            'fee_amount' => 0,
            'comm_type' => $type,
            'comm_amount' => $amount,
            'additional_fee_type' => 'FIXED',
            'additional_fee_amount' => 0,
            'is_active' => true,
        ]);
    }

    private function exchangeRate(): ExchangeRate
    {
        return ExchangeRate::query()->create([
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'base_amount' => 1,
            'buy_rate' => 145,
            'sell_rate' => 148,
        ]);
    }
}
