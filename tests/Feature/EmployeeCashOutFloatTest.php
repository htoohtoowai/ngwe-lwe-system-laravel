<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Repositories\CashFloatRepository;
use App\Services\CashFloatService;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeCashOutFloatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('float cash-out tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('i', 32));
    }

    public function test_employee_cash_out_deducts_float_denominations_and_balance(): void
    {
        [$employee, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 3, 5_000 => 4]);
        [$account, $company] = $this->accountWithCompany();
        $this->fixedTier($company->id, feeWithdraw: 500);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-out', [
                'account_id' => $account->id,
                'amount' => 30_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
                'denominations' => [
                    10_000 => 2,
                    5_000 => 2,
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.transaction_type', 'cash_out')
            ->assertJsonPath('data.customer_fee', '500.00')
            ->assertJsonPath('data.balance_change', '30000.00');

        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $this->assertSame('20000.00', $activeFloat->current_balance);

        $balances = app(CashFloatRepository::class)->getDenominationBalance($activeFloat->id);
        $this->assertSame(1, $balances[10_000]);
        $this->assertSame(2, $balances[5_000]);
    }

    public function test_employee_cash_out_account_fee_credits_receiving_account_with_amount_plus_fee(): void
    {
        [$employee, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 1]);
        [$account, $company] = $this->accountWithCompany();
        $this->fixedTier($company->id, feeWithdraw: 500, commWithdraw: 900);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-out', [
                'account_id' => $account->id,
                'amount' => 10_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
                'denominations' => [
                    10_000 => 1,
                ],
                'fee_payment_method' => 'account',
            ])
            ->assertCreated()
            ->assertJsonPath('data.customer_fee', '500.00')
            ->assertJsonPath('data.commission_amount', '900.00')
            ->assertJsonPath('data.balance_change', '11400.00')
            ->assertJsonPath('data.fee_payment_method', 'account')
            ->assertJsonPath('data.fee_account_id', $account->id);

        $this->assertSame('11400.00', $account->fresh()->balance);

        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $this->assertSame('0.00', $activeFloat->current_balance);
    }

    public function test_employee_cash_out_requires_denominations(): void
    {
        [, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 3]);
        [$account, $company] = $this->accountWithCompany();
        $this->fixedTier($company->id);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-out', [
                'account_id' => $account->id,
                'amount' => 10_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
            ])
            ->assertStatus(422);
    }

    public function test_employee_cash_out_rejects_when_no_active_float(): void
    {
        $employee = $this->createUser('teller');
        $employeeToken = app(NgweLweTokenService::class)->create($employee);
        [$account, $company] = $this->accountWithCompany();
        $this->fixedTier($company->id);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-out', [
                'account_id' => $account->id,
                'amount' => 10_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'denominations' => [10_000 => 1],
            ])
            ->assertStatus(422);
    }

    public function test_employee_cash_out_rejects_denomination_total_mismatch(): void
    {
        [, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 3]);
        [$account, $company] = $this->accountWithCompany();
        $this->fixedTier($company->id);

        // Amount 20000 but denominations only total 10000.
        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-out', [
                'account_id' => $account->id,
                'amount' => 20_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'denominations' => [10_000 => 1],
            ])
            ->assertStatus(422);
    }

    public function test_employee_cash_out_rejects_when_denomination_stock_insufficient(): void
    {
        [$employee, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 1]);
        [$account, $company] = $this->accountWithCompany();
        $this->fixedTier($company->id);

        // Requesting 2x10k but only 1 in float.
        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-out', [
                'account_id' => $account->id,
                'amount' => 20_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'denominations' => [10_000 => 2],
            ])
            ->assertStatus(409);

        // Float unchanged.
        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $this->assertSame('10000.00', $activeFloat->current_balance);
    }

    public function test_owner_cash_out_uses_main_vault_denominations(): void
    {
        [$owner, $ownerToken] = $this->userWithToken('admin');
        [$account, $company] = $this->accountWithCompany();
        $this->fixedTier($company->id, feeWithdraw: 200);
        app(CashDenominationRepository::class)->recordBulk('vault_in', [5_000 => 1], $owner->id);

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/transactions/cash-out', [
                'account_id' => $account->id,
                'amount' => 5_000,
                'customer_name' => 'Owner Test',
                'customer_phone' => '09',
                'denominations' => [5_000 => 1],
            ])
            ->assertCreated()
            ->assertJsonPath('data.customer_fee', '200.00');
    }

    public function test_employee_can_only_draw_notes_from_own_float(): void
    {
        [$employeeA, $employeeAToken] = $this->activeEmployeeWithFloat([10_000 => 1]);
        $this->activeEmployeeWithFloat([5_000 => 1]);
        [$account, $company] = $this->accountWithCompany();
        $this->fixedTier($company->id);

        $this->withHeader('Authorization', 'Bearer '.$employeeAToken)
            ->postJson('/api/transactions/cash-out', [
                'account_id' => $account->id,
                'amount' => 5_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'denominations' => [5_000 => 1],
            ])
            ->assertStatus(409);

        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employeeA->id);
        $this->assertSame('10000.00', $activeFloat->current_balance);
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

        // Stock the main vault so the cashier can issue.
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
     * @return array{0: Account, 1: Company}
     */
    private function accountWithCompany(): array
    {
        $company = Company::query()->create([
            'name' => 'Wave-'.uniqid('', true),
            'category' => 'Pay',
        ]);
        $account = Account::query()->create([
            'company_id' => $company->id,
            'account_name' => 'Wave Main',
            'phone_number' => '0900000000',
            'balance' => 0,
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
    }}
