<?php

namespace Tests\Feature;

use App\Models\CashFloatAssignment;
use App\Models\CashFloatDenomination;
use App\Models\User;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FloatActivationDenominationVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('cash float tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('m', 32));
    }

    public function test_exact_verified_denominations_activate_float(): void
    {
        $cashier = $this->createUser('cashier');
        [$employee, $token] = $this->employeeWithPin();
        $float = $this->pendingFloat($cashier, $employee, [10_000 => 3, 5_000 => 2]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/cash-floats/'.$float->id.'/activate', [
                'pin' => '1234',
                'verified_denominations' => [10_000 => 3, 5_000 => 2],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.current_balance', '40000.00');
    }

    public function test_short_count_rejects_activation_and_leaves_float_pending(): void
    {
        $cashier = $this->createUser('cashier');
        [$employee, $token] = $this->employeeWithPin();
        $float = $this->pendingFloat($cashier, $employee, [10_000 => 3]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/cash-floats/'.$float->id.'/activate', [
                'pin' => '1234',
                'verified_denominations' => [10_000 => 2],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Denomination 10000 MMK — Issued: 3, You counted: 2');

        $this->assertSame('PENDING_RECEIPT', $float->fresh()->status);
    }

    public function test_over_count_rejects_activation_and_leaves_float_pending(): void
    {
        $cashier = $this->createUser('cashier');
        [$employee, $token] = $this->employeeWithPin();
        $float = $this->pendingFloat($cashier, $employee, [5_000 => 4]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/cash-floats/'.$float->id.'/activate', [
                'pin' => '1234',
                'verified_denominations' => [5_000 => 5],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Denomination 5000 MMK — Issued: 4, You counted: 5');

        $this->assertSame('PENDING_RECEIPT', $float->fresh()->status);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function employeeWithPin(): array
    {
        $employee = $this->createUser('teller');
        $employee->pin_hash = Hash::make('1234');
        $employee->save();

        return [$employee, app(NgweLweTokenService::class)->create($employee)];
    }

    /**
     * @param  array<int, int>  $denominations
     */
    private function pendingFloat(User $cashier, User $employee, array $denominations): CashFloatAssignment
    {
        $total = 0;
        foreach ($denominations as $denomination => $quantity) {
            $total += $denomination * $quantity;
        }

        $float = CashFloatAssignment::query()->create([
            'employee_id' => $employee->id,
            'issued_by' => $cashier->id,
            'status' => 'PENDING_RECEIPT',
            'total_amount' => $total,
        ]);

        foreach ($denominations as $denomination => $quantity) {
            CashFloatDenomination::query()->create([
                'float_id' => $float->id,
                'denomination' => $denomination,
                'quantity' => $quantity,
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
