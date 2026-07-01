<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\ServiceType;
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
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is not enabled for in-memory exchange tests.');
        }

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('f', 32));
    }

    public function test_owner_can_create_and_update_exchange_rate(): void
    {
        [, $token] = $this->userWithToken('owner');

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
        [, $employeeToken] = $this->userWithToken('employee');

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

    public function test_latest_endpoint_returns_placeholder_when_no_rate_stored(): void
    {
        [, $token] = $this->userWithToken('employee');

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
        [, $token] = $this->userWithToken('employee');

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

    public function test_exchange_transaction_credits_account_and_uses_sell_rate_for_mmk(): void
    {
        [, $token] = $this->userWithToken('owner');
        [$account, $serviceType] = $this->accountWithBalance(0);
        $this->fixedTier($serviceType->id, feeDeposit: 300);

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
            ])
            ->assertCreated()
            ->assertJsonPath('data.transaction_type', 'exchange')
            ->assertJsonPath('data.currency', 'MMK')
            ->assertJsonPath('data.exchange_rate', '148.0000')
            ->assertJsonPath('data.customer_fee', '300.00')
            ->assertJsonPath('data.balance_change', '100000.00');

        $this->assertSame('100000.00', $account->fresh()->balance);
    }

    public function test_exchange_transaction_uses_buy_rate_for_thb(): void
    {
        [, $token] = $this->userWithToken('owner');
        [$account, $serviceType] = $this->accountWithBalance(0);
        $this->fixedTier($serviceType->id);

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
            ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'THB')
            ->assertJsonPath('data.exchange_rate', '145.0000');
    }

    public function test_exchange_transaction_respects_base_amount_divisor(): void
    {
        [, $token] = $this->userWithToken('owner');
        [$account, $serviceType] = $this->accountWithBalance(0);
        $this->fixedTier($serviceType->id);

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
            ])
            ->assertCreated()
            ->assertJsonPath('data.exchange_rate', '148.0000');
    }

    public function test_exchange_rejects_when_no_rate_stored(): void
    {
        [, $token] = $this->userWithToken('owner');
        [$account, $serviceType] = $this->accountWithBalance(0);
        $this->fixedTier($serviceType->id);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $account->id,
                'amount' => 1_000,
                'currency' => 'MMK',
            ])
            ->assertStatus(422);
    }

    public function test_exchange_rejects_unsupported_currency(): void
    {
        [, $token] = $this->userWithToken('owner');
        [$account, $serviceType] = $this->accountWithBalance(0);
        $this->fixedTier($serviceType->id);

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
            ])
            ->assertStatus(422);
    }

    public function test_cashier_cannot_create_exchange(): void
    {
        [, $cashierToken] = $this->userWithToken('cashier');
        [$account, $serviceType] = $this->accountWithBalance(0);
        $this->fixedTier($serviceType->id);

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
     * @return array{0: Account, 1: ServiceType}
     */
    private function accountWithBalance(int $balance): array
    {
        $company = Company::query()->create([
            'name' => 'Wave-'.uniqid('', true),
            'category' => 'Pay',
        ]);
        $serviceType = ServiceType::query()->create([
            'company_id' => $company->id,
            'name' => 'Exchange',
            'operation' => 'Exchange',
        ]);
        $account = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'Wave Main',
            'phone_number' => '0900000000',
            'balance' => $balance,
        ]);

        return [$account, $serviceType];
    }

    private function fixedTier(int $serviceTypeId, int $feeDeposit = 0, int $feeWithdraw = 0): CommissionTier
    {
        return CommissionTier::query()->create([
            'service_type_id' => $serviceTypeId,
            'amount_from' => 1,
            'amount_to' => 999_999_999_999,
            'fee_amount_type' => 'FIXED',
            'fee_amount_deposit' => $feeDeposit,
            'fee_amount_withdraw' => $feeWithdraw,
            'comm_type' => 'FIXED',
            'additional_fee_type' => 'FIXED',
            'is_active' => true,
        ]);
    }
}
