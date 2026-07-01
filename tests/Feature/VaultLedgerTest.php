<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientVaultDenominationException;
use App\Models\CashDenominationLog;
use App\Models\CashFloatAssignment;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Services\CashFloatService;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VaultLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is not enabled for in-memory vault tests.');
        }

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('h', 32));
    }

    public function test_vault_balance_reflects_vault_in_minus_vault_out(): void
    {
        $vault = app(CashDenominationRepository::class);
        $owner = $this->userWithRole('owner');

        $vault->recordBulk('vault_in', [10_000 => 5, 5_000 => 4], $owner->id);
        $vault->recordBulk('vault_out', [10_000 => 2], $owner->id);
        $vault->recordBulk('float_returned', [1_000 => 10], $owner->id);

        $balance = $vault->getVaultBalance();

        $this->assertSame(3, $balance[10_000]);   // 5 - 2
        $this->assertSame(4, $balance[5_000]);
        $this->assertSame(10, $balance[1_000]);
        $this->assertSame(0, $balance[50]);
    }

    public function test_recording_vault_out_that_would_go_negative_throws(): void
    {
        $vault = app(CashDenominationRepository::class);
        $owner = $this->userWithRole('owner');

        $vault->recordBulk('vault_in', [10_000 => 2], $owner->id);

        $this->expectException(InsufficientVaultDenominationException::class);

        $vault->recordBulk('vault_out', [10_000 => 5], $owner->id);
    }

    public function test_float_issue_writes_vault_out_log_and_decrements_balance(): void
    {
        $vault = app(CashDenominationRepository::class);
        $cashier = $this->userWithRole('cashier');
        $employee = $this->userWithRole('employee', 'emp');

        $vault->recordBulk('vault_in', [10_000 => 10, 5_000 => 10], $cashier->id);

        $float = app(CashFloatService::class)->issue(
            $cashier,
            $employee->id,
            [10_000 => 3, 5_000 => 2],
        );

        $this->assertSame('PENDING_RECEIPT', $float->status);

        $balance = $vault->getVaultBalance();
        $this->assertSame(7, $balance[10_000]);
        $this->assertSame(8, $balance[5_000]);

        $this->assertSame(2, CashDenominationLog::query()
            ->where('float_id', $float->id)
            ->where('entry_type', 'vault_out')
            ->count());
    }

    public function test_float_issue_fails_when_vault_stock_is_insufficient(): void
    {
        $cashier = $this->userWithRole('cashier');
        $employee = $this->userWithRole('employee', 'emp');

        // Only stock 1x10k — issuing 2x10k must reject.
        app(CashDenominationRepository::class)
            ->recordBulk('vault_in', [10_000 => 1], $cashier->id);

        $this->expectException(InsufficientVaultDenominationException::class);

        app(CashFloatService::class)->issue(
            $cashier,
            $employee->id,
            [10_000 => 2],
        );
    }

    public function test_confirm_return_writes_float_returned_log_and_credits_vault(): void
    {
        $vault = app(CashDenominationRepository::class);
        $cashier = $this->userWithRole('cashier');
        $employee = $this->userWithRole('employee', 'emp');
        $this->setPin($cashier, '9999');
        $this->setPin($employee, '1234');

        $vault->recordBulk('vault_in', [10_000 => 10], $cashier->id);

        $service = app(CashFloatService::class);
        $float = $service->issue($cashier, $employee->id, [10_000 => 5]);
        $service->activate($employee, $float->fresh(), '1234', [10_000 => 5]);

        $active = $float->fresh();
        $service->initiateReturn($employee, $active, [10_000 => 5]);

        $service->confirmReturn($cashier, $active->fresh(), 50_000, '9999');

        $this->assertSame(10, $vault->getVaultBalance()[10_000]);
        $this->assertSame(1, CashDenominationLog::query()
            ->where('float_id', $float->id)
            ->where('entry_type', 'float_returned')
            ->count());
    }

    public function test_vault_balance_endpoint_returns_totals(): void
    {
        $owner = $this->userWithRole('owner');
        $ownerToken = app(NgweLweTokenService::class)->create($owner);

        app(CashDenominationRepository::class)->recordBulk(
            'vault_in',
            [10_000 => 3, 5_000 => 4],
            $owner->id,
        );

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->getJson('/api/vault/balance')
            ->assertOk()
            ->assertJsonPath('data.vault.10000', 3)
            ->assertJsonPath('data.vault.5000', 4)
            ->assertJsonPath('data.vault_total', 50_000)
            ->assertJsonPath('data.available_total', 50_000);
    }

    public function test_vault_inventory_endpoint_shows_open_floats(): void
    {
        $cashier = $this->userWithRole('cashier');
        $employee = $this->userWithRole('employee', 'emp');
        $ownerToken = app(NgweLweTokenService::class)->create($this->userWithRole('owner'));

        app(CashDenominationRepository::class)->recordBulk(
            'vault_in',
            [10_000 => 10],
            $cashier->id,
        );

        $float = app(CashFloatService::class)->issue(
            $cashier,
            $employee->id,
            [10_000 => 3],
        );

        $response = $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->getJson('/api/vault/inventory')
            ->assertOk();

        $response->assertJsonPath('data.main_vault.10000', 7);
        $response->assertJsonPath('data.main_vault_total', 70_000);

        $floats = $response->json('data.employee_floats');
        $this->assertCount(1, $floats);
        $this->assertSame($float->id, $floats[0]['float_id']);
        $this->assertSame('PENDING_RECEIPT', $floats[0]['status']);
        $this->assertSame(30_000, $floats[0]['denom_total']);
        $this->assertSame(100_000, $response->json('data.grand_physical_total'));
    }

    public function test_closed_floats_do_not_appear_in_inventory(): void
    {
        $cashier = $this->userWithRole('cashier');
        $employee = $this->userWithRole('employee', 'emp');
        $this->setPin($cashier, '9999');
        $this->setPin($employee, '1234');
        $ownerToken = app(NgweLweTokenService::class)->create($this->userWithRole('owner'));

        app(CashDenominationRepository::class)->recordBulk(
            'vault_in',
            [10_000 => 10],
            $cashier->id,
        );

        $service = app(CashFloatService::class);
        $float = $service->issue($cashier, $employee->id, [10_000 => 3]);
        $service->activate($employee, $float->fresh(), '1234', [10_000 => 3]);
        $service->initiateReturn($employee, $float->fresh(), [10_000 => 3]);
        $service->confirmReturn($cashier, $float->fresh(), 30_000, '9999');

        $this->assertSame('CLOSED', CashFloatAssignment::query()->find($float->id)->status);

        $response = $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->getJson('/api/vault/inventory')
            ->assertOk();

        $this->assertCount(0, $response->json('data.employee_floats'));
        $this->assertSame(100_000, $response->json('data.grand_physical_total'));
        $this->assertSame(100_000, $response->json('data.main_vault_total'));
    }

    private function userWithRole(string $role, string $prefix = ''): User
    {
        return User::factory()->create([
            'username' => ($prefix ?: $role).'_'.uniqid('', true),
            'role' => $role,
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);
    }

    private function setPin(User $user, string $pin): void
    {
        $user->pin_hash = Hash::make($pin);
        $user->save();
    }
}
