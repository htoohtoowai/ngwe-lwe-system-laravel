<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CashFloatAssignment;
use App\Models\CashFloatDenomination;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Services\CashFloatService;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CashFloatLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('cash float tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('g', 32));
    }

    public function test_cashier_can_issue_float_with_denominations_and_computed_total(): void
    {
        [$cashier, $cashierToken] = $this->userWithToken('cashier');
        $employee = $this->activeEmployee();

        // Vault must be stocked before a float can be issued (issue now writes a `vault_out` log).
        $this->seedVaultBalance([10_000 => 10, 5_000 => 10, 1_000 => 50], $cashier);

        $response = $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/cash-floats', [
                'employee_id' => $employee->id,
                'denominations' => [
                    10_000 => 5,   // 50000
                    5_000 => 4,    // 20000
                    1_000 => 10,   // 10000
                ],
                'note' => 'Morning float',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING_RECEIPT')
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.issued_by', $cashier->id)
            ->assertJsonPath('data.total_amount', '80000.00');

        $this->assertNotNull($response->json('data.denominations'));
        $this->assertSame(3, count($response->json('data.denominations')));

        $log = ActivityLog::query()
            ->where('action', 'float_issued')
            ->firstOrFail();
        $this->assertSame($cashier->id, $log->user_id);
        $this->assertSame('80000.00', $log->details['total_amount']);
    }

    public function test_issue_rejects_unsupported_denomination(): void
    {
        [, $cashierToken] = $this->userWithToken('cashier');
        $employee = $this->activeEmployee();

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/cash-floats', [
                'employee_id' => $employee->id,
                'denominations' => [
                    300 => 5,
                ],
            ])
            ->assertStatus(422);
    }

    public function test_employee_cannot_issue_float(): void
    {
        [, $employeeToken] = $this->userWithToken('teller');
        $employee = $this->activeEmployee();

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/cash-floats', [
                'employee_id' => $employee->id,
                'denominations' => [10_000 => 1],
            ])
            ->assertForbidden();
    }

    public function test_employee_activates_own_float_and_current_balance_matches_total(): void
    {
        [$cashier] = $this->userWithToken('cashier');
        $employee = $this->activeEmployee();
        $this->setPin($employee, '1234');
        $employeeToken = app(NgweLweTokenService::class)->create($employee);
        $float = $this->issueFloat($cashier, $employee, [10_000 => 3]);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/cash-floats/'.$float->id.'/activate', [
                'pin' => '1234',
                'verified_denominations' => [10_000 => 3],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.current_balance', '30000.00');

        $this->assertNotNull($float->fresh()->received_at);
        $this->assertSame(1, ActivityLog::query()->where('action', 'float_activated')->count());
    }

    public function test_employee_cannot_activate_someone_else_float(): void
    {
        [$cashier] = $this->userWithToken('cashier');
        $employee1 = $this->activeEmployee('emp_a');
        $employee2 = $this->activeEmployee('emp_b');
        $this->setPin($employee2, '5678');
        $token2 = app(NgweLweTokenService::class)->create($employee2);
        $float = $this->issueFloat($cashier, $employee1, [10_000 => 1]);

        $this->withHeader('Authorization', 'Bearer '.$token2)
            ->postJson('/api/cash-floats/'.$float->id.'/activate', [
                'pin' => '5678',
                'verified_denominations' => [10_000 => 1],
            ])
            ->assertStatus(403);

        $this->assertSame('PENDING_RECEIPT', $float->fresh()->status);
    }

    public function test_activating_already_active_float_returns_409(): void
    {
        [$cashier] = $this->userWithToken('cashier');
        $employee = $this->activeEmployee();
        $this->setPin($employee, '1234');
        $employeeToken = app(NgweLweTokenService::class)->create($employee);
        $float = $this->issueFloat($cashier, $employee, [10_000 => 1]);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/cash-floats/'.$float->id.'/activate', [
                'pin' => '1234',
                'verified_denominations' => [10_000 => 1],
            ])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/cash-floats/'.$float->id.'/activate', [
                'pin' => '1234',
                'verified_denominations' => [10_000 => 1],
            ])
            ->assertStatus(409);
    }

    public function test_full_return_lifecycle_records_activity_and_closes_float(): void
    {
        [$cashier, $cashierToken] = $this->userWithToken('cashier');
        $this->setPin($cashier, '9999');
        $employee = $this->activeEmployee();
        $this->setPin($employee, '1234');
        $employeeToken = app(NgweLweTokenService::class)->create($employee);
        $this->seedVaultBalance([10_000 => 5], $cashier);
        $float = app(CashFloatService::class)->issue($cashier, $employee->id, [10_000 => 5]);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/cash-floats/'.$float->id.'/activate', [
                'pin' => '1234',
                'verified_denominations' => [10_000 => 5],
            ])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/cash-floats/'.$float->id.'/initiate-return', [
                'return_denominations' => [
                    10_000 => 3,
                    5_000 => 2,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING_RECONCILIATION');

        $this->withHeader('Authorization', 'Bearer '.$cashierToken)
            ->postJson('/api/cash-floats/'.$float->id.'/confirm-return', [
                'closing_total' => 40000,
                'pin' => '9999',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.closing_total', '40000.00')
            ->assertJsonPath('data.current_balance', '0.00');

        foreach (['float_issued', 'float_activated', 'float_return_initiated', 'float_return_confirmed'] as $action) {
            $this->assertSame(1, ActivityLog::query()->where('action', $action)->count(), $action);
        }
    }

    public function test_initiate_return_requires_active_float(): void
    {
        [$cashier] = $this->userWithToken('cashier');
        $employee = $this->activeEmployee();
        $employeeToken = app(NgweLweTokenService::class)->create($employee);
        $float = $this->issueFloat($cashier, $employee, [10_000 => 1]);

        $this->withHeader('Authorization', 'Bearer '.$employeeToken)
            ->postJson('/api/cash-floats/'.$float->id.'/initiate-return', [
                'return_denominations' => [10_000 => 1],
            ])
            ->assertStatus(409);
    }

    public function test_employee_list_only_shows_own_floats(): void
    {
        [$cashier] = $this->userWithToken('cashier');
        $employeeA = $this->activeEmployee('emp_a');
        $employeeB = $this->activeEmployee('emp_b');
        $tokenA = app(NgweLweTokenService::class)->create($employeeA);
        $this->issueFloat($cashier, $employeeA, [10_000 => 1]);
        $this->issueFloat($cashier, $employeeB, [10_000 => 2]);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->getJson('/api/cash-floats')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($employeeA->id, $data[0]['employee_id']);
    }

    public function test_employee_show_forbidden_for_other_employee_float(): void
    {
        [$cashier] = $this->userWithToken('cashier');
        $employeeA = $this->activeEmployee('emp_a');
        $employeeB = $this->activeEmployee('emp_b');
        $tokenB = app(NgweLweTokenService::class)->create($employeeB);
        $float = $this->issueFloat($cashier, $employeeA, [10_000 => 1]);

        $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->getJson('/api/cash-floats/'.$float->id)
            ->assertStatus(403);
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

    private function activeEmployee(string $prefix = 'emp'): User
    {
        return User::factory()->create([
            'username' => $prefix.'_'.uniqid('', true),
            'role' => 'teller',
            'is_active' => true,
            'password' => Hash::make('password123'),
        ]);
    }

    /**
     * @param  array<int, int>  $denominations
     */
    private function issueFloat(User $cashier, User $employee, array $denominations): CashFloatAssignment
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

    /**
     * @param  array<int, int>  $denominations
     */
    private function seedVaultBalance(array $denominations, User $creator): void
    {
        app(CashDenominationRepository::class)->recordBulk(
            entryType: 'vault_in',
            denominations: $denominations,
            createdBy: $creator->id,
            note: 'Test seed',
        );
    }

    private function setPin(User $user, string $pin): void
    {
        $user->pin_hash = Hash::make($pin);
        $user->save();
    }
}
