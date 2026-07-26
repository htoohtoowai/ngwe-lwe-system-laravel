<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashFloatAssignment;
use App\Models\Company;
use App\Models\ServiceType;
use App\Models\Transaction;
use App\Models\User;
use App\Services\NgweLweTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TellerHistoryPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('teller history pages tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('h', 32));
    }

    public function test_teller_transaction_history_pages_show_own_rows_by_type(): void
    {
        [$teller, $token] = $this->userWithToken('teller');
        [$otherTeller] = $this->userWithToken('teller');
        [$accountA, $accountB] = $this->accounts();

        $rows = [
            '/transactions/cash-in/history' => [
                'component' => 'transactions/CashIn',
                'type' => 'cash_in',
                'transaction' => $this->transaction($teller, 'cash_in', $accountA),
            ],
            '/transactions/cash-out/history' => [
                'component' => 'transactions/CashOut',
                'type' => 'cash_out',
                'transaction' => $this->transaction($teller, 'cash_out', $accountA),
            ],
            '/transactions/transfer/history' => [
                'component' => 'transactions/Transfer',
                'type' => 'transfer',
                'transaction' => $this->transaction($teller, 'transfer', $accountA, $accountB),
            ],
            '/transactions/exchange/history' => [
                'component' => 'transactions/Exchange',
                'type' => 'exchange',
                'transaction' => $this->transaction($teller, 'exchange', $accountA),
            ],
        ];

        foreach ($rows as $route => $expected) {
            $this->transaction($otherTeller, $expected['type'], $accountA, $accountB);

            $this->withHeader('Authorization', 'Bearer '.$token)
                ->get($route)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($expected['component'])
                    ->where('view', 'history')
                    ->has('history', 1)
                    ->where('history.0.id', $expected['transaction']->id)
                    ->where('history.0.transaction_type', $expected['type'])
                );
        }
    }

    public function test_teller_transfer_and_exchange_entry_routes_render_distinct_pages(): void
    {
        [, $token] = $this->userWithToken('teller');
        $this->accounts();

        foreach ([
            '/transactions/transfer' => 'transactions/Transfer',
            '/transactions/exchange' => 'transactions/Exchange',
        ] as $route => $component) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->get($route)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component)
                    ->where('view', 'entry')
                );
        }
    }

    public function test_teller_float_history_page_shows_own_floats_only(): void
    {
        [$cashier] = $this->userWithToken('cashier');
        [$teller, $token] = $this->userWithToken('teller');
        [$otherTeller] = $this->userWithToken('teller');

        $float = CashFloatAssignment::query()->create([
            'employee_id' => $teller->id,
            'issued_by' => $cashier->id,
            'status' => 'CLOSED',
            'total_amount' => 25_000,
            'current_balance' => 0,
            'closing_total' => 25_000,
            'closed_at' => now(),
        ]);
        CashFloatAssignment::query()->create([
            'employee_id' => $otherTeller->id,
            'issued_by' => $cashier->id,
            'status' => 'CLOSED',
            'total_amount' => 50_000,
            'current_balance' => 0,
            'closing_total' => 50_000,
            'closed_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/teller/float/history')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('teller/Float')
                ->where('view', 'history')
                ->has('floats', 1)
                ->where('floats.0.id', $float->id)
            );
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

    /**
     * @return array{0: Account, 1: Account}
     */
    private function accounts(): array
    {
        $company = Company::query()->create([
            'name' => 'Wave-'.uniqid('', true),
            'category' => 'Pay',
        ]);
        $serviceType = ServiceType::query()->create([
            'company_id' => $company->id,
            'name' => 'WST',
            'operation' => 'All',
            'is_active' => true,
        ]);

        return [
            Account::query()->create([
                'service_type_id' => $serviceType->id,
                'account_name' => 'Wave Main',
                'phone_number' => '0900000000',
                'balance' => 100_000,
                'is_active' => true,
            ]),
            Account::query()->create([
                'service_type_id' => $serviceType->id,
                'account_name' => 'Wave Second',
                'phone_number' => '0911111111',
                'balance' => 100_000,
                'is_active' => true,
            ]),
        ];
    }

    private function transaction(User $creator, string $type, Account $account, ?Account $toAccount = null): Transaction
    {
        return Transaction::query()->create([
            'transaction_type' => $type,
            'account_id' => $account->id,
            'to_account_id' => $toAccount?->id,
            'customer_name' => 'Aung',
            'customer_phone' => '0912345678',
            'amount' => 10_000,
            'customer_fee' => 100,
            'commission_amount' => 0,
            'created_by' => $creator->id,
            'status' => 'COMPLETED',
            'currency' => 'MMK',
            'created_at' => now(),
        ]);
    }
}
