<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\ServiceType;
use App\Models\User;
use App\Models\VaultTransaction;
use App\Repositories\CashDenominationRepository;
use App\Services\CashFloatService;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VaultTransactionAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('vault transaction tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('l', 32));
    }

    public function test_float_lifecycle_writes_one_vault_transaction_per_denomination(): void
    {
        $cashier = $this->createUser('cashier');
        $employee = $this->createUser('employee');
        $this->setPin($cashier, '9999');
        $this->setPin($employee, '1234');

        app(CashDenominationRepository::class)->recordBulk(
            'vault_in',
            [10_000 => 10, 5_000 => 10],
            $cashier->id,
        );

        $service = app(CashFloatService::class);
        $float = $service->issue($cashier, $employee->id, [10_000 => 2, 5_000 => 3]);

        $this->assertVaultRows(
            txnType: 'float_issue',
            floatId: $float->id,
            transactionId: null,
            denominations: [10_000 => 2, 5_000 => 3],
            performedBy: $cashier->id,
        );

        $service->activate($employee, $float->fresh(), '1234', [10_000 => 2, 5_000 => 3]);

        $this->assertVaultRows(
            txnType: 'float_receipt',
            floatId: $float->id,
            transactionId: null,
            denominations: [10_000 => 2, 5_000 => 3],
            performedBy: $employee->id,
        );

        $service->initiateReturn($employee, $float->fresh(), [10_000 => 1, 5_000 => 1]);

        $this->assertVaultRows(
            txnType: 'return_initiate',
            floatId: $float->id,
            transactionId: null,
            denominations: [10_000 => 1, 5_000 => 1],
            performedBy: $employee->id,
        );

        $service->confirmReturn($cashier, $float->fresh(), 15_000, '9999');

        $this->assertVaultRows(
            txnType: 'return_confirm',
            floatId: $float->id,
            transactionId: null,
            denominations: [10_000 => 1, 5_000 => 1],
            performedBy: $cashier->id,
            verifiedBy: $cashier->id,
        );
    }

    public function test_employee_cash_draw_operations_write_cash_out_rows(): void
    {
        [$employee, $employeeToken, $floatId] = $this->activeEmployeeWithFloat([
            10_000 => 10,
            5_000 => 10,
        ]);

        [$cashOutAccount, $cashOutServiceType] = $this->accountWithServiceType('Cash Out', 'CashOut', 0);
        $this->fixedTier($cashOutServiceType->id, feeWithdraw: 500);

        $cashOutId = $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-out', [
                'account_id' => $cashOutAccount->id,
                'amount' => 20_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
                'denominations' => [10_000 => 1, 5_000 => 2],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertVaultRows('cash_out', $floatId, $cashOutId, [10_000 => 1, 5_000 => 2], $employee->id);

        [$fromAccount, $fromServiceType] = $this->accountWithServiceType('Transfer From', 'Transfer', 60_000);
        $toAccount = Account::query()->create([
            'service_type_id' => $fromServiceType->id,
            'account_name' => 'Transfer To',
            'phone_number' => '0900000002',
            'balance' => 0,
        ]);
        $this->fixedTier($fromServiceType->id, feeDeposit: 300);

        $transferId = $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/transfer', [
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => 10_000,
                'denominations' => [10_000 => 1],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertVaultRows('cash_out', $floatId, $transferId, [10_000 => 1], $employee->id);

        [$exchangeAccount, $exchangeServiceType] = $this->accountWithServiceType('Exchange', 'Exchange', 0);
        $this->fixedTier($exchangeServiceType->id, feeDeposit: 200);
        $this->exchangeRate();

        $exchangeId = $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $exchangeAccount->id,
                'amount' => 10_000,
                'currency' => 'MMK',
                'denominations' => [5_000 => 2],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertVaultRows('cash_out', $floatId, $exchangeId, [5_000 => 2], $employee->id);

        [$cashInAccount, $cashInServiceType] = $this->accountWithServiceType('Cash In', 'CashIn', 80_000);
        $this->fixedTier($cashInServiceType->id, feeDeposit: 100);

        $cashInId = $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $cashInAccount->id,
                'amount' => 10_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
                'amount_received' => 15_000,
                'change_denominations' => [5_000 => 1],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertVaultRows('cash_out', $floatId, $cashInId, [5_000 => 1], $employee->id);
    }

    public function test_vault_log_endpoint_is_owner_only_paginated_and_filterable(): void
    {
        [$employee, $employeeToken, $floatId] = $this->activeEmployeeWithFloat([10_000 => 3]);
        [$account, $serviceType] = $this->accountWithServiceType('Cash Out', 'CashOut', 0);
        $this->fixedTier($serviceType->id);
        $ownerToken = app(NgweLweTokenService::class)->create($this->createUser('owner'));

        $txnId = $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-out', [
                'account_id' => $account->id,
                'amount' => 20_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
                'denominations' => [10_000 => 2],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->getJson('/api/vault/log')
            ->assertForbidden();

        $response = $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->getJson("/api/vault/log?txn_type=cash_out&float_id={$floatId}&per_page=5")
            ->assertOk()
            ->assertJsonPath('data.0.txn_type', 'cash_out')
            ->assertJsonPath('data.0.float_id', $floatId)
            ->assertJsonPath('data.0.transaction_id', $txnId)
            ->assertJsonPath('data.0.performed_by', $employee->id)
            ->assertJsonPath('data.0.verified_by', null);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(5, $response->json('meta.per_page'));
    }

    /**
     * @param  array<int, int>  $denominations
     * @return array{0: User, 1: string, 2: int}
     */
    private function activeEmployeeWithFloat(array $denominations): array
    {
        $cashier = $this->createUser('cashier');
        $employee = $this->createUser('employee');
        $this->setPin($employee, '1234');

        app(CashDenominationRepository::class)->recordBulk(
            'vault_in',
            $denominations,
            $cashier->id,
        );

        $service = app(CashFloatService::class);
        $float = $service->issue($cashier, $employee->id, $denominations);
        $service->activate($employee, $float->fresh(), '1234', $denominations);

        return [$employee, app(NgweLweTokenService::class)->create($employee), $float->id];
    }

    /**
     * @return array{0: Account, 1: ServiceType}
     */
    private function accountWithServiceType(string $name, string $operation, int $balance): array
    {
        $company = Company::query()->create([
            'name' => 'Wave-'.uniqid('', true),
            'category' => 'Pay',
        ]);
        $serviceType = ServiceType::query()->create([
            'company_id' => $company->id,
            'name' => $name,
            'operation' => $operation,
        ]);
        $account = Account::query()->create([
            'service_type_id' => $serviceType->id,
            'account_name' => $name.' Main',
            'phone_number' => '0900000000',
            'balance' => $balance,
        ]);

        return [$account, $serviceType];
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

    private function setPin(User $user, string $pin): void
    {
        $user->pin_hash = Hash::make($pin);
        $user->save();
    }

    /**
     * @param  array<int, int>  $denominations
     */
    private function assertVaultRows(
        string $txnType,
        int $floatId,
        ?int $transactionId,
        array $denominations,
        int $performedBy,
        ?int $verifiedBy = null,
    ): void {
        $rows = VaultTransaction::query()
            ->where('txn_type', $txnType)
            ->where('float_id', $floatId)
            ->when(
                $transactionId === null,
                fn ($query) => $query->whereNull('transaction_id'),
                fn ($query) => $query->where('transaction_id', $transactionId),
            )
            ->orderBy('denomination')
            ->get();

        $this->assertCount(count($denominations), $rows);

        foreach ($denominations as $denomination => $quantity) {
            $row = $rows->firstWhere('denomination', $denomination);
            $this->assertNotNull($row, "Missing denomination {$denomination} for {$txnType}.");
            $this->assertSame((int) $quantity, $row->quantity);
            $this->assertSame($performedBy, $row->performed_by);
            $this->assertSame($verifiedBy, $row->verified_by);
        }
    }
}
