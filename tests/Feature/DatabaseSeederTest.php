<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfDatabaseUnavailable('database seeder tests');

        parent::setUp();

        config()->set('ngwe_lwe.auth.secret', str_repeat('d', 32));
    }

    public function test_demo_seeder_creates_login_users_and_operating_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ([
            'owner' => ['role' => 'owner', 'pin' => '1111'],
            'cashier' => ['role' => 'cashier', 'pin' => '2222'],
            'employee' => ['role' => 'employee', 'pin' => '3333'],
        ] as $username => $expected) {
            $user = User::query()->where('username', $username)->firstOrFail();

            $this->assertSame($expected['role'], $user->role);
            $this->assertTrue((bool) $user->is_active);
            $this->assertTrue(Hash::check('password123', $user->password));
            $this->assertTrue(Hash::check($expected['pin'], $user->pin_hash));

            $this->postJson('/api/auth/login', [
                'username' => $username,
                'password' => 'password123',
            ])
                ->assertOk()
                ->assertJsonPath('user.username', $username)
                ->assertJsonPath('user.role', $expected['role'])
                ->assertJsonStructure(['token', 'user']);
        }

        $company = Company::query()->where('name', 'Demo Wave Money')->firstOrFail();
        $this->assertSame('Pay', $company->category);
        $this->assertTrue((bool) $company->is_active);

        $this->assertSame(4, ServiceType::query()->where('company_id', $company->id)->count());
        $this->assertSame(6, Account::query()->whereIn('account_name', [
            'Demo Wave Cash In',
            'Demo Wave Cash Out',
            'Demo Transfer Source',
            'Demo Transfer Target',
            'Demo Exchange Till',
            'Demo Fee Account',
        ])->count());

        $this->assertDatabaseHas('accounts', [
            'account_name' => 'Demo Fee Account',
            'is_fee_account' => 1,
        ]);
        $this->assertDatabaseHas('exchange_rates', [
            'base_currency' => 'THB',
            'quote_currency' => 'MMK',
            'buy_rate' => '145.0000',
            'sell_rate' => '150.0000',
        ]);
        $this->assertGreaterThan(0, DB::table('commission_tiers')->count());
        $this->assertSame(4_135_000, (int) DB::table('vault_denomination_balances')->sum('total_value'));
    }

    public function test_demo_seeder_can_run_twice_without_duplicate_setup_or_vault_credit(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(3, User::query()->whereIn('username', ['owner', 'cashier', 'employee'])->count());
        $this->assertSame(1, Company::query()->where('name', 'Demo Wave Money')->count());

        $company = Company::query()->where('name', 'Demo Wave Money')->firstOrFail();
        $this->assertSame(4, ServiceType::query()->where('company_id', $company->id)->count());

        $this->assertSame(6, Account::query()->whereIn('account_name', [
            'Demo Wave Cash In',
            'Demo Wave Cash Out',
            'Demo Transfer Source',
            'Demo Transfer Target',
            'Demo Exchange Till',
            'Demo Fee Account',
        ])->count());

        $this->assertSame(8, DB::table('cash_denomination_logs')
            ->where('note', 'Demo vault opening balance')
            ->count());
        $this->assertSame(4_135_000, (int) DB::table('vault_denomination_balances')->sum('total_value'));
    }
}
