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
        $this->skipIfDatabaseUnavailable('float transfer/exchange tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('j', 32));
    }

    public function test_employee_transfer_does_not_deduct_float_denominations_or_balance(): void
    {
        [$employee, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 3, 5_000 => 4]);
        [$from, $to, $serviceType] = $this->twoAccountsWithBalance(30_000);
        $this->fixedTier($serviceType->id, feeDeposit: 300);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/transfer', [
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => 20_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
            ])
            ->assertCreated()
            ->assertJsonPath('data.transaction_type', 'transfer')
            ->assertJsonPath('data.customer_name', 'Aung')
            ->assertJsonPath('data.customer_phone', '09')
            ->assertJsonPath('data.customer_fee', '300.00');

        $this->assertSame('10000.00', $from->fresh()->balance);
        $this->assertSame('20000.00', $to->fresh()->balance);

        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $this->assertSame('50000.00', $activeFloat->current_balance);

        $balances = app(CashFloatRepository::class)->getDenominationBalance($activeFloat->id);
        $this->assertSame(3, $balances[10_000]);
        $this->assertSame(4, $balances[5_000]);
    }

    public function test_employee_transfer_cash_fee_adds_fee_denominations_to_float(): void
    {
        [$employee, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 3]);
        [$from, $to, $serviceType] = $this->twoAccountsWithBalance(30_000);
        $this->fixedTier($serviceType->id, feeDeposit: 300);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/transfer', [
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'source_account_type' => 'pay',
                'source_provider' => 'KBZPay',
                'source_account_number' => '09123456789',
                'destination_provider' => 'CB Bank',
                'destination_customer_name' => 'Mya Mya',
                'destination_account_number' => '001-001122-001',
                'amount' => 10_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'fee_payment_method' => 'cash',
                'fee_denominations' => [100 => 3],
            ])
            ->assertCreated()
            ->assertJsonPath('data.received_denominations.100', 3);

        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $this->assertSame('30300.00', $activeFloat->current_balance);

        $balances = app(CashFloatRepository::class)->getDenominationBalance($activeFloat->id);
        $this->assertSame(3, $balances[10_000]);
        $this->assertSame(3, $balances[100]);
    }

    public function test_employee_transfer_rejects_amount_denominations(): void
    {
        [$employee, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 1]);
        [$from, $to, $serviceType] = $this->twoAccountsWithBalance(30_000);
        $this->fixedTier($serviceType->id);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/transfer', [
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => 20_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'denominations' => [10_000 => 1],
            ])
            ->assertStatus(422);

        // Float untouched.
        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $this->assertSame('10000.00', $activeFloat->current_balance);
        $this->assertSame('30000.00', $from->fresh()->balance);
    }

    public function test_owner_transfer_still_works_without_denominations(): void
    {
        [, $ownerToken] = $this->userWithToken('admin');
        [$from, $to, $serviceType] = $this->twoAccountsWithBalance(30_000);
        $this->fixedTier($serviceType->id);

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/transactions/transfer', [
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => 5_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
            ])
            ->assertCreated();

        $this->assertSame('25000.00', $from->fresh()->balance);
        $this->assertSame('5000.00', $to->fresh()->balance);
    }

    public function test_employee_thb_exchange_deducts_mmk_float_denominations_and_balance(): void
    {
        [$employee, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 20, 5_000 => 2]);
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
                'amount' => 1_000,
                'currency' => 'THB',
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'denominations' => [10_000 => 14, 5_000 => 1],
            ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'THB')
            ->assertJsonPath('data.exchange_rate', '145.0000')
            ->assertJsonPath('data.customer_fee', '0.00');

        $this->assertSame('145000.00', $account->fresh()->balance);

        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $this->assertSame('65000.00', $activeFloat->current_balance);
    }

    public function test_employee_mmk_exchange_account_payment_does_not_require_denominations(): void
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
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'exchange_payment_method' => 'account',
            ])
            ->assertCreated();
    }

    public function test_employee_thb_exchange_account_payment_does_not_require_or_deduct_denominations(): void
    {
        [$employee, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 20, 5_000 => 2]);
        [$account, $serviceType] = $this->accountWithServiceType(0);
        $this->fixedTier($serviceType->id);

        ExchangeRate::query()->create([
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'base_amount' => 1,
            'buy_rate' => 145,
            'sell_rate' => 148,
        ]);

        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $balanceBefore = $activeFloat->current_balance;

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $account->id,
                'amount' => 1_000,
                'currency' => 'THB',
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'exchange_payment_method' => 'account',
            ])
            ->assertCreated();

        $this->assertSame($balanceBefore, $activeFloat->fresh()->current_balance);
    }

    public function test_employee_thb_exchange_denom_total_mismatch_rejected(): void
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

        // THB 1000 needs 145000 MMK, but denominations total 10000.
        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $account->id,
                'amount' => 1_000,
                'currency' => 'THB',
                'customer_name' => 'Aung',
                'customer_phone' => '09',
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
        $employee = $this->createUser('teller');
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
        $service->activate($employee, $float->fresh(), '1234', $denominations);

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
            'is_agent' => true,
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
            'is_agent' => true,
        ]);
        $to = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => 'To',
            'phone_number' => '0900000002',
            'balance' => 0,
            'is_agent' => true,
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
