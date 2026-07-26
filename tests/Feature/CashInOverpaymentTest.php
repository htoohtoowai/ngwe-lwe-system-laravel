<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ServiceType;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Repositories\CashFloatRepository;
use App\Services\CashFloatService;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CashInOverpaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('cash-in overpayment tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('k', 32));
    }

    public function test_cash_in_without_overpayment_keeps_employee_float_balanced_until_cashier_handoff(): void
    {
        [$employee, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 5]);
        [$account, $serviceType] = $this->accountWithBalance(80_000);
        $this->fixedTier($serviceType->id, feeDeposit: 300);

        $response = $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 20_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
                'received_denominations' => [10_000 => 2],
                'handoff_denominations' => [10_000 => 2],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING_CASHIER_CONFIRM')
            ->assertJsonPath('data.change_given', '0.00');

        $this->assertSame('60000.00', $account->fresh()->balance);

        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $this->assertSame('50000.00', $activeFloat->current_balance);

        $balances = app(CashFloatRepository::class)->getDenominationBalance($activeFloat->id);
        $this->assertSame(5, $balances[10_000]);

        [, $cashierToken] = $this->userWithToken('cashier');
        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/transactions/'.$response->json('data.id').'/confirm-cash-in')
            ->assertOk();

        $this->assertSame(2, app(CashDenominationRepository::class)->getVaultBalance()[10_000]);
    }

    public function test_cash_in_with_overpayment_deducts_change_from_float(): void
    {
        [$employee, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 3, 5_000 => 4]);
        [$account, $serviceType] = $this->accountWithBalance(100_000);
        $this->fixedTier($serviceType->id);

        $response = $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 20_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
                'amount_received' => 30_000,
                'received_denominations' => [10_000 => 2, 5_000 => 2],
                'handoff_denominations' => [10_000 => 2],
                'change_denominations' => [10_000 => 1],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING_CASHIER_CONFIRM')
            ->assertJsonPath('data.change_given', '10000.00');

        $this->assertSame([10_000 => 1], $response->json('data.change_denominations'));

        // Account digital balance debited by amount (20k).
        $this->assertSame('80000.00', $account->fresh()->balance);

        // Employee float receives 30k, gives 10k change, and hands over 20k: net 0.
        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $this->assertSame('50000.00', $activeFloat->current_balance);

        $balances = app(CashFloatRepository::class)->getDenominationBalance($activeFloat->id);
        $this->assertSame(2, $balances[10_000]);
        $this->assertSame(6, $balances[5_000]);
    }

    public function test_teller_cash_in_keeps_value_balance_flat_but_changes_denominations(): void
    {
        [$employee, $employeeToken] = $this->activeEmployeeWithFloat([5_000 => 2]);
        [$account, $serviceType] = $this->accountWithBalance(100_000);
        $this->fixedTier($serviceType->id);

        $response = $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 45_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'amount_received' => 50_000,
                'received_denominations' => [10_000 => 5],
                'handoff_denominations' => [10_000 => 4, 5_000 => 1],
                'change_denominations' => [5_000 => 1],
            ])
            ->assertCreated()
            ->assertJsonPath('data.change_given', '5000.00');

        $this->assertSame([10_000 => 5], $response->json('data.received_denominations'));
        $handoff = $response->json('data.handoff_denominations');
        ksort($handoff);
        $this->assertSame([5_000 => 1, 10_000 => 4], $handoff);

        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $this->assertSame('10000.00', $activeFloat->current_balance);
        $this->assertSame([5_000 => 0, 10_000 => 1], app(CashFloatRepository::class)->getDenominationBalance($activeFloat->id));

        [, $cashierToken] = $this->userWithToken('cashier');
        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/transactions/'.$response->json('data.id').'/confirm-cash-in')
            ->assertOk();

        $mainVault = app(CashDenominationRepository::class)->getVaultBalance();
        $mainVault = array_filter($mainVault, static fn (int $quantity): bool => $quantity > 0);
        ksort($mainVault);
        $this->assertSame([5_000 => 1, 10_000 => 4], $mainVault);
    }

    public function test_cash_in_change_denomination_total_must_match_change_due(): void
    {
        [, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 3]);
        [$account, $serviceType] = $this->accountWithBalance(100_000);
        $this->fixedTier($serviceType->id);

        // change_due would be 5000 but change_denominations total 10000.
        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 20_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'amount_received' => 25_000,
                'received_denominations' => [10_000 => 2, 5_000 => 1],
                'change_denominations' => [10_000 => 1],
            ])
            ->assertStatus(422);
    }

    public function test_cash_in_amount_received_less_than_amount_rejected(): void
    {
        [, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 3]);
        [$account, $serviceType] = $this->accountWithBalance(100_000);
        $this->fixedTier($serviceType->id);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 20_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'amount_received' => 10_000,
                'received_denominations' => [10_000 => 1],
            ])
            ->assertStatus(422);
    }

    public function test_change_denominations_without_overpayment_rejected(): void
    {
        [, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 3]);
        [$account, $serviceType] = $this->accountWithBalance(100_000);
        $this->fixedTier($serviceType->id);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 20_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'received_denominations' => [10_000 => 2],
                'handoff_denominations' => [10_000 => 2],
                'change_denominations' => [10_000 => 1],
            ])
            ->assertStatus(422);
    }

    public function test_owner_cannot_do_overpayment_change(): void
    {
        [, $ownerToken] = $this->userWithToken('admin');
        [$account, $serviceType] = $this->accountWithBalance(100_000);
        $this->fixedTier($serviceType->id);

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 20_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'amount_received' => 25_000,
                'received_denominations' => [10_000 => 2, 5_000 => 1],
                'change_denominations' => [5_000 => 1],
            ])
            ->assertStatus(422);
    }

    public function test_overpayment_rejected_when_float_stock_insufficient(): void
    {
        [$employee, $employeeToken] = $this->activeEmployeeWithFloat([5_000 => 1]);
        [$account, $serviceType] = $this->accountWithBalance(100_000);
        $this->fixedTier($serviceType->id);

        // change_due = 10_000, but only 5_000 x 1 in float (5000).
        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 20_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'amount_received' => 30_000,
                'received_denominations' => [10_000 => 3],
                'handoff_denominations' => [10_000 => 2],
                'change_denominations' => [10_000 => 1],
            ])
            ->assertStatus(409);

        // Nothing changed on account or float.
        $this->assertSame('100000.00', $account->fresh()->balance);
        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $this->assertSame('5000.00', $activeFloat->current_balance);
    }

    public function test_cancel_cash_in_reverses_received_cash_from_employee_float(): void
    {
        [$employee, $employeeToken] = $this->activeEmployeeWithFloat([10_000 => 5]);
        [, $cashierToken] = $this->userWithToken('cashier');
        [$account, $serviceType] = $this->accountWithBalance(100_000);
        $this->fixedTier($serviceType->id);

        $txnId = $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $account->id,
                'amount' => 20_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'received_denominations' => [10_000 => 2],
                'handoff_denominations' => [10_000 => 2],
            ])
            ->assertCreated()
            ->json('data.id');

        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $this->assertSame('50000.00', $activeFloat->current_balance);

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/transactions/'.$txnId.'/cancel-cash-in', ['note' => 'cash not received'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $this->assertSame('100000.00', $account->fresh()->balance);
        $activeFloat = app(CashFloatRepository::class)->activeForEmployee($employee->id);
        $this->assertSame('50000.00', $activeFloat->current_balance);
        $this->assertSame([10_000 => 5], app(CashFloatRepository::class)->getDenominationBalance($activeFloat->id));
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
    private function accountWithBalance(int $balance): array
    {
        $company = Company::query()->create([
            'name' => 'Wave-'.uniqid('', true),
            'category' => 'Pay',
        ]);
        $serviceType = ServiceType::query()->create([
            'company_id' => $company->id,
            'name' => 'Cash In',
            'operation' => 'CashIn',
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
