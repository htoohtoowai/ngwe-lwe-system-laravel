<?php

namespace Tests\Feature;

use App\Models\CashFloatAssignment;
use App\Models\CashFloatDenomination;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Services\CashFloatService;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PinVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is not enabled for in-memory PIN tests.');
        }

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('l', 32));
    }

    public function test_authenticated_user_can_set_pin(): void
    {
        $user = $this->createUser('employee');
        $token = app(NgweLweTokenService::class)->create($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/pin', ['pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('message', 'PIN updated');

        $this->assertTrue(Hash::check('1234', $user->fresh()->pin_hash));
    }

    public function test_set_pin_rejects_non_numeric(): void
    {
        $user = $this->createUser('employee');
        $token = app(NgweLweTokenService::class)->create($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/pin', ['pin' => 'abcd'])
            ->assertStatus(422);
    }

    public function test_set_pin_rejects_short_pin(): void
    {
        $user = $this->createUser('employee');
        $token = app(NgweLweTokenService::class)->create($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/pin', ['pin' => '12'])
            ->assertStatus(422);
    }

    public function test_activate_requires_correct_pin(): void
    {
        $cashier = $this->createUser('cashier');
        $employee = $this->createUser('employee');
        $employee->pin_hash = Hash::make('1234');
        $employee->save();
        $token = app(NgweLweTokenService::class)->create($employee);

        $float = $this->pendingFloat($cashier, $employee, [10_000 => 1]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/cash-floats/'.$float->id.'/activate', [
                'pin' => '9999',
                'verified_denominations' => [10_000 => 1],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Incorrect PIN.');

        $this->assertSame('PENDING_RECEIPT', $float->fresh()->status);
    }

    public function test_activate_requires_pin_to_be_set_first(): void
    {
        $cashier = $this->createUser('cashier');
        $employee = $this->createUser('employee');
        $token = app(NgweLweTokenService::class)->create($employee);

        $float = $this->pendingFloat($cashier, $employee, [10_000 => 1]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/cash-floats/'.$float->id.'/activate', [
                'pin' => '1234',
                'verified_denominations' => [10_000 => 1],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No PIN set. Please set your PIN first.');
    }

    public function test_activate_rejects_missing_pin_at_validation(): void
    {
        $cashier = $this->createUser('cashier');
        $employee = $this->createUser('employee');
        $employee->pin_hash = Hash::make('1234');
        $employee->save();
        $token = app(NgweLweTokenService::class)->create($employee);

        $float = $this->pendingFloat($cashier, $employee, [10_000 => 1]);

        // No pin in the body — request-level validation kicks in.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/cash-floats/'.$float->id.'/activate', [
                'verified_denominations' => [10_000 => 1],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');
    }

    public function test_confirm_return_requires_cashier_pin(): void
    {
        $cashier = $this->createUser('cashier');
        $cashier->pin_hash = Hash::make('9999');
        $cashier->save();
        $cashierToken = app(NgweLweTokenService::class)->create($cashier);

        $employee = $this->createUser('employee');
        $employee->pin_hash = Hash::make('1234');
        $employee->save();

        app(CashDenominationRepository::class)->recordBulk(
            'vault_in',
            [10_000 => 5],
            $cashier->id,
        );

        $service = app(CashFloatService::class);
        $float = $service->issue($cashier, $employee->id, [10_000 => 3]);
        $service->activate($employee, $float->fresh(), '1234', [10_000 => 3]);
        $service->initiateReturn($employee, $float->fresh(), [10_000 => 3]);

        // Wrong PIN
        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/cash-floats/'.$float->id.'/confirm-return', [
                'closing_total' => 30_000,
                'pin' => '0000',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Incorrect PIN.');

        // Still PENDING_RECONCILIATION.
        $this->assertSame('PENDING_RECONCILIATION', $float->fresh()->status);

        // Correct PIN
        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/cash-floats/'.$float->id.'/confirm-return', [
                'closing_total' => 30_000,
                'pin' => '9999',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'CLOSED');
    }

    /**
     * @param  array<int, int>  $denominations
     */
    private function pendingFloat(User $cashier, User $employee, array $denominations): CashFloatAssignment
    {
        $total = 0;
        foreach ($denominations as $denom => $qty) {
            $total += $denom * $qty;
        }

        $float = CashFloatAssignment::query()->create([
            'employee_id' => $employee->id,
            'issued_by' => $cashier->id,
            'status' => 'PENDING_RECEIPT',
            'total_amount' => $total,
        ]);

        foreach ($denominations as $denom => $qty) {
            CashFloatDenomination::query()->create([
                'float_id' => $float->id,
                'denomination' => $denom,
                'quantity' => $qty,
            ]);
        }

        return $float->fresh();
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
}
