<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\TransferFeeTier;
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
        $employee = $this->createUser('teller');
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

        $service->initiateReturn($employee, $float->fresh(), [10_000 => 2, 5_000 => 3], '1234');

        $this->assertVaultRows(
            txnType: 'return_initiate',
            floatId: $float->id,
            transactionId: null,
            denominations: [10_000 => 2, 5_000 => 3],
            performedBy: $employee->id,
        );

        $service->confirmReturn($cashier, $float->fresh(), 35_000, '9999');

        $this->assertVaultRows(
            txnType: 'return_confirm',
            floatId: $float->id,
            transactionId: null,
            denominations: [10_000 => 2, 5_000 => 3],
            performedBy: $cashier->id,
            verifiedBy: $cashier->id,
        );
    }

    public function test_employee_cash_draw_operations_write_cash_out_rows(): void
    {
        [$employee, $employeeToken, $floatId] = $this->activeEmployeeWithFloat([
            10_000 => 10,
            5_000 => 10,
            1_000 => 5,
            500 => 2,
        ]);

        [$cashOutAccount, $cashOutCompany] = $this->accountWithCompany('Cash Out', 'CashOut', 0);
        $this->fixedTier($cashOutCompany->id, feeWithdraw: 500);

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

        [$fromAccount, $fromCompany] = $this->accountWithCompany('Transfer From', 'Transfer', 60_000);
        $toAccount = Account::query()->create([
            'company_id' => $fromCompany->id,
            'account_name' => 'Transfer To',
            'phone_number' => '0900000002',
            'balance' => 0,
        ]);
        $this->fixedTier($fromCompany->id, feeDeposit: 300);
        TransferFeeTier::query()->create([
            'company_from_id' => $fromCompany->id,
            'company_to_id' => $fromCompany->id,
            'amount_from' => 1,
            'amount_to' => 999_999_999_999,
            'fee_type' => 'FIXED',
            'fee_amount' => 300,
            'additional_fee_type' => 'FIXED',
            'additional_fee_amount' => 0,
            'is_active' => true,
        ]);

        $transferId = $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/transfer', [
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => 10_000,
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'fee_payment_method' => 'cash',
                'fee_denominations' => [100 => 3],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertVaultRows('transfer_fee_received', $floatId, $transferId, [100 => 3], $employee->id);

        [$exchangeAccount, $exchangeCompany] = $this->accountWithCompany('Exchange', 'Exchange', 0);
        $this->fixedTier($exchangeCompany->id, feeDeposit: 200);
        $this->exchangeRate();

        $exchangeId = $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/exchange', [
                'account_id' => $exchangeAccount->id,
                'amount' => 100,
                'currency' => 'THB',
                'customer_name' => 'Aung',
                'customer_phone' => '09',
                'denominations' => [10_000 => 1, 1_000 => 4, 500 => 1],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertVaultRows('cash_out', $floatId, $exchangeId, [10_000 => 1, 1_000 => 4, 500 => 1], $employee->id);

        [$cashInAccount, $cashInCompany] = $this->accountWithCompany('Cash In', 'CashIn', 80_000);
        $this->fixedTier($cashInCompany->id, feeDeposit: 100);

        $cashInId = $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/transactions/cash-in', [
                'account_id' => $cashInAccount->id,
                'amount' => 10_000,
                'customer_name' => 'Aung',
                'customer_phone' => '0912345678',
                'amount_received' => 15_000,
                'received_denominations' => [10_000 => 1, 5_000 => 1],
                'handoff_denominations' => [10_000 => 1],
                'change_denominations' => [5_000 => 1],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertVaultRows('cash_in_received', $floatId, $cashInId, [10_000 => 1, 5_000 => 1], $employee->id);
        $this->assertVaultRows('cash_in_handoff', $floatId, $cashInId, [10_000 => 1], $employee->id);
        $this->assertVaultRows('cash_in_change', $floatId, $cashInId, [5_000 => 1], $employee->id);
    }

    public function test_vault_log_endpoint_is_owner_only_paginated_and_filterable(): void
    {
        [$employee, $employeeToken, $floatId] = $this->activeEmployeeWithFloat([10_000 => 3]);
        [$account, $company] = $this->accountWithCompany('Cash Out', 'CashOut', 0);
        $this->fixedTier($company->id);
        $ownerToken = app(NgweLweTokenService::class)->create($this->createUser('admin'));

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
        $employee = $this->createUser('teller');
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
     * @return array{0: Account, 1: Company}
     */
    private function accountWithCompany(string $name, string $operation, int $balance): array
    {
        $company = Company::query()->create([
            'name' => 'Wave-'.uniqid('', true),
            'category' => 'Pay',
        ]);
        $account = Account::query()->create([
            'company_id' => $company->id,
            'account_name' => $name.' Main',
            'phone_number' => '0900000000',
            'balance' => $balance,
        ]);

        return [$account, $company];
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
