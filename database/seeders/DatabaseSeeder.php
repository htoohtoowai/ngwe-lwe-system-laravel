<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\ServiceType;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'password123';

    private const DEMO_USERS = [
        [
            'username' => 'owner',
            'email' => 'owner@ngwe-lwe.local',
            'full_name' => 'Demo Owner',
            'role' => 'owner',
            'pin' => '1111',
        ],
        [
            'username' => 'cashier',
            'email' => 'cashier@ngwe-lwe.local',
            'full_name' => 'Demo Cashier',
            'role' => 'cashier',
            'pin' => '2222',
        ],
        [
            'username' => 'employee',
            'email' => 'employee@ngwe-lwe.local',
            'full_name' => 'Demo Employee',
            'role' => 'employee',
            'pin' => '3333',
        ],
    ];

    private const DEMO_VAULT_DENOMINATIONS = [
        20000 => 100,
        10000 => 100,
        5000 => 100,
        1000 => 500,
        500 => 200,
        200 => 100,
        100 => 100,
        50 => 100,
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $users = $this->seedDemoUsers();

        $this->seedSetupData();
        $this->seedVaultOpeningBalance($users['owner']);
    }

    /**
     * @return array<string, User>
     */
    private function seedDemoUsers(): array
    {
        $users = [];

        foreach (self::DEMO_USERS as $seed) {
            $users[$seed['username']] = User::query()->updateOrCreate(
                ['username' => $seed['username']],
                [
                    'name' => $seed['full_name'],
                    'email' => $seed['email'],
                    'full_name' => $seed['full_name'],
                    'role' => $seed['role'],
                    'is_active' => true,
                    'auth_version' => 0,
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'pin_hash' => Hash::make($seed['pin']),
                ],
            );
        }

        return $users;
    }

    private function seedSetupData(): void
    {
        $company = Company::query()->updateOrCreate(
            ['name' => 'Demo Wave Money'],
            [
                'category' => 'Pay',
                'is_active' => true,
            ],
        );

        $serviceTypes = $this->seedServiceTypes($company);

        $this->seedAccounts($serviceTypes);
        $this->seedCommissionTiers($serviceTypes);

        ExchangeRate::query()->updateOrCreate(
            [
                'base_currency' => 'THB',
                'quote_currency' => 'MMK',
            ],
            [
                'base_amount' => '1.00',
                'buy_rate' => '145.0000',
                'sell_rate' => '150.0000',
            ],
        );
    }

    /**
     * @return Collection<string, ServiceType>
     */
    private function seedServiceTypes(Company $company): Collection
    {
        $serviceTypes = collect();

        foreach ([
            'cash_in' => ['name' => 'Demo Cash In', 'operation' => 'CashIn'],
            'cash_out' => ['name' => 'Demo Cash Out', 'operation' => 'CashOut'],
            'transfer' => ['name' => 'Demo Transfer', 'operation' => 'Transfer'],
            'exchange' => ['name' => 'Demo Exchange', 'operation' => 'Exchange'],
        ] as $key => $seed) {
            $serviceTypes[$key] = ServiceType::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'name' => $seed['name'],
                ],
                [
                    'operation' => $seed['operation'],
                    'is_active' => true,
                ],
            );
        }

        return $serviceTypes;
    }

    /**
     * @param  Collection<string, ServiceType>  $serviceTypes
     */
    private function seedAccounts(Collection $serviceTypes): void
    {
        foreach ([
            [
                'service_type' => 'cash_in',
                'account_name' => 'Demo Wave Cash In',
                'phone_number' => '09970000001',
                'balance' => '5000000.00',
                'is_fee_account' => false,
            ],
            [
                'service_type' => 'cash_out',
                'account_name' => 'Demo Wave Cash Out',
                'phone_number' => '09970000002',
                'balance' => '1000000.00',
                'is_fee_account' => false,
            ],
            [
                'service_type' => 'transfer',
                'account_name' => 'Demo Transfer Source',
                'phone_number' => '09970000003',
                'balance' => '3000000.00',
                'is_fee_account' => false,
            ],
            [
                'service_type' => 'transfer',
                'account_name' => 'Demo Transfer Target',
                'phone_number' => '09970000004',
                'balance' => '500000.00',
                'is_fee_account' => false,
            ],
            [
                'service_type' => 'exchange',
                'account_name' => 'Demo Exchange Till',
                'phone_number' => '09970000005',
                'balance' => '1000000.00',
                'is_fee_account' => false,
            ],
            [
                'service_type' => 'cash_in',
                'account_name' => 'Demo Fee Account',
                'phone_number' => '09970000099',
                'balance' => '0.00',
                'is_fee_account' => true,
            ],
        ] as $seed) {
            /** @var ServiceType $serviceType */
            $serviceType = $serviceTypes[$seed['service_type']];
            $account = Account::query()->firstOrNew([
                'service_type_id' => $serviceType->id,
                'account_name' => $seed['account_name'],
            ]);

            if (! $account->exists) {
                $account->balance = $seed['balance'];
            }

            $account->fill([
                'phone_number' => $seed['phone_number'],
                'commission_rate' => '0.0000',
                'is_active' => true,
                'is_fee_account' => $seed['is_fee_account'],
            ]);
            $account->save();
        }
    }

    /**
     * @param  Collection<string, ServiceType>  $serviceTypes
     */
    private function seedCommissionTiers(Collection $serviceTypes): void
    {
        foreach ($serviceTypes as $serviceType) {
            CommissionTier::query()->updateOrCreate(
                [
                    'service_type_id' => $serviceType->id,
                    'amount_from' => '1.00',
                    'amount_to' => '999999999999.00',
                ],
                [
                    'fee_amount_type' => 'FIXED',
                    'fee_amount_deposit' => '500.0000',
                    'fee_amount_withdraw' => '500.0000',
                    'comm_type' => 'FIXED',
                    'comm_deposit' => '100.0000',
                    'comm_withdraw' => '100.0000',
                    'additional_fee_type' => 'FIXED',
                    'additional_fee_deposit_amount' => '0.0000',
                    'additional_fee_withdraw_amount' => '0.0000',
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedVaultOpeningBalance(User $owner): void
    {
        $existingQuantity = (int) DB::table('vault_denomination_balances')->sum('quantity');
        if ($existingQuantity > 0) {
            return;
        }

        app(CashDenominationRepository::class)->recordBulk(
            entryType: 'vault_in',
            denominations: self::DEMO_VAULT_DENOMINATIONS,
            createdBy: $owner->id,
            note: 'Demo vault opening balance',
        );
    }
}
