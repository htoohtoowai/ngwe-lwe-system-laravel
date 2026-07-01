<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\ServiceType;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Repositories\CashFloatRepository;
use App\Services\CashFloatService;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeTransferExchangeFloatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is not enabled for in-memory float transfer/exchange tests.');
        }

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('j', 32));
    }

    public function test_employee_transfer_deducts_float_denominations_and_balance(): void
    {
        [$employee, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 3, 5_000 => 4]);
        [$from, $to, $serviceType] = $this->twoAccountsWithBalance(30_000);
        $this->fixedTier($serviceType->id, feeDeposit: 300);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/transfer', [
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => 20_000,
                'denominations' => [10_000 => 2],
            ])
            ->assertCreated()
            ->assertJsonPath('data.transaction_type', 'transfer')
            ->assertJsonPath('data.customer_fee', '300.00');

        $this->assertSame('10000.00', $from->fresh()->balance);
        $this->assertSame('20000.00', $to->fresh()->balance);

        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $this->assertSame('30000.00', $activeFloat->current_balance);

        $balances = app(CashFloatRepository::class)->getDenominationBalance($activeFloat->id);
        $this->assertSame(1, $balances[10_000]);
        $this->assertSame(4, $balances[5_000]);
    }

    public function test_employee_transfer_requires_denominations(): void
    {
        [, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 3]);
        [$from, $to, $serviceType] = $this->twoAccountsWithBalance(30_000);
        $this->fixedTier($serviceType->id);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/transfer', [
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => 10_000,
            ])
            ->assertStatus(422);
    }

    public function test_employee_transfer_rejects_insufficient_denomination_stock(): void
    {
        [$employee, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 1]);
        [$from, $to, $serviceType] = $this->twoAccountsWithBalance(30_000);
        $this->fixedTier($serviceType->id);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/transfer', [
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => 20_000,
                'denominations' => [10_000 => 2],
            ])
            ->assertStatus(409);

        // Float untouched.
        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $this->assertSame('10000.00', $activeFloat->current_balance);
        $this->assertSame('30000.00', $from->fresh()->balance);
    }

    public function test_owner_transfer_still_works_without_denominations(): void
    {
        [, $ownerToken] = $this->userWithToken('owner');
        [$from, $to, $serviceType] = $this->twoAccountsWithBalance(30_000);
        $this->fixedTier($serviceType->id);

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/transactions/transfer', [
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => 5_000,
            ])
            ->assertCreated();

        $this->assertSame('25000.00', $from->fresh()->balance);
        $this->assertSame('5000.00', $to->fresh()->balance);
    }

    public function test_employee_exchange_deducts_float_denominations_and_balance(): void
    {
        [$employee, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 3]);
        [$account, $serviceType] = $this->accountWithServiceType(0);
        $this->fixedTier($serviceType->id, feeDeposit: 200);

        ExchangeRate::query()->create([
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'base_amount' => 1,
            'buy_rate' => 145,
            'sell_rate' => 148,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $account->id,
                'amount' => 20_000,
                'currency' => 'MMK',
                'denominations' => [10_000 => 2],
            ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'MMK')
            ->assertJsonPath('data.exchange_rate', '148.0000')
            ->assertJsonPath('data.customer_fee', '200.00');

        $this->assertSame('20000.00', $account->fresh()->balance);

        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $this->assertSame('10000.00', $activeFloat->current_balance);
    }

    public function test_employee_exchange_requires_denominations(): void
    {
        [, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 1]);
        [$account, $serviceType] = $this->accountWithServiceType(0);
        $this->fixedTier($serviceType->id);

        ExchangeRate::query()->create([
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'base_amount' => 1,
            'buy_rate' => 145,
            'sell_rate' => 148,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $account->id,
                'amount' => 10_000,
                'currency' => 'MMK',
            ])
            ->assertStatus(422);
    }

    public function test_employee_exchange_denom_total_mismatch_rejected(): void
    {
        [, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 3, 5_000 => 4]);
        [$account, $serviceType] = $this->accountWithServiceType(0);
        $this->fixedTier($serviceType->id);

        ExchangeRate::query()->create([
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'base_amount' => 1,
            'buy_rate' => 145,
            'sell_rate' => 148,
        ]);

        // Amount 15000 but denominations total 10000.
        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $account->id,
                'amount' => 15_000,
                'currency' => 'MMK',
                'denominations' => [10_000 => 1],
            ])
            ->assertStatus(422);
    }

    /**
     * @param  array<int, int>  $denominations
     * @return array{0: User, 1: string}
     */
    private function activeEmployeeWithFloat(array $denominations): array
    {
        $cashier = $this->createUser('cashier');
        $employee = $this->createUser('employee');
        $employee->pin_hash = Hash::make('1234');
        $employee->save();
        $employeeToken = app(NgweLweTokenService::class)->create($employee);

        app(CashDenominationRepository::class)->recordBulk(
            'vault_in',
            $denominations,
            $cashier->id,
        );

        $service = app(CashFloatService::class);
        $float = $service->issue($cashier, $employee->id, $denominations);
        $service->activate($employee, $float->fresh(), '1234');

        return [$employee, $employeeToken];
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function userWithToken(string $role): array
    {
        $user = $this->createUser($role);

        return [$user, app(NgweLweTokenService::class)->create($user)];
    }

    private function createUser(string $role): User
    {
        return User::factory()->create([
            'username' => $role.'_'.uniqid('', true),
            'role' => $role,
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);
    }

    /**
     * @return array{0: Account, 1: ServiceType}
     */
    private function accountWithServiceType(int $balance): array
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
            'account_name' => 'Main',
            'phone_number' => '0900000000',
            'balance' => $balance,
        ]);

        return [$account, $serviceType];
    }

    /**
     * @return array{0: Account, 1: Account, 2: ServiceType}
     */
    private function twoAccountsWithBalance(int $fromBalance): array
    {
        $company = Company::query()->create([
            'name' => 'Wave-'.uniqid('', true),
            'category' => 'Pay',
        ]);
        $serviceType = ServiceType::query()->create([
            'company_id' => $company->id,
            'name' => 'Transfer',
            'operation' => 'Transfer',
        ]);
        $from = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'From',
            'phone_number' => '0900000001',
            'balance' => $fromBalance,
        ]);
        $to = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'To',
            'phone_number' => '0900000002',
            'balance' => 0,
        ]);

        return [$from, $to, $serviceType];
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
