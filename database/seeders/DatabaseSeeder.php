<?php

namespace Database\Seeders;

use App\Enums\AccountFeature;
use App\Models\Account;
use App\Models\AccountFeatureAssignment;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\TransferFeeTier;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
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

    private const DEMO_ACCOUNTS = [
        [
            'company' => 'KBZPay',
            'name' => 'KBZPay Teller Main',
            'phone' => '09970000001',
            'balance' => '5000000.00',
            'is_agent' => false,
            'is_fee_account' => false,
            'features' => [AccountFeature::CashIn, AccountFeature::SendMoney, AccountFeature::ReceiveMoney],
        ],
        [
            'company' => 'KBZPay',
            'name' => 'KBZPay Cash Out Main',
            'phone' => '09970000002',
            'balance' => '3000000.00',
            'is_agent' => false,
            'is_fee_account' => false,
            'features' => [AccountFeature::CashOut],
        ],
        [
            'company' => 'KBZPay',
            'name' => 'KBZPay Agent Outlet',
            'phone' => '09970000003',
            'balance' => '2000000.00',
            'is_agent' => true,
            'is_fee_account' => false,
            'features' => [AccountFeature::CashIn, AccountFeature::CashOut, AccountFeature::SendMoney, AccountFeature::ReceiveMoney],
        ],
        [
            'company' => 'KBZPay',
            'name' => 'KBZPay Fee Collection',
            'phone' => '09970000004',
            'balance' => '0.00',
            'is_agent' => false,
            'is_fee_account' => true,
            'features' => [],
        ],
        [
            'company' => 'Wave Money',
            'name' => 'WavePay Teller Main',
            'phone' => '09980000001',
            'balance' => '4000000.00',
            'is_agent' => false,
            'is_fee_account' => false,
            'features' => [AccountFeature::CashIn, AccountFeature::SendMoney, AccountFeature::ReceiveMoney],
        ],
        [
            'company' => 'Wave Money',
            'name' => 'WavePay Cash Out Main',
            'phone' => '09980000002',
            'balance' => '2500000.00',
            'is_agent' => false,
            'is_fee_account' => false,
            'features' => [AccountFeature::CashOut],
        ],
        [
            'company' => 'Wave Money',
            'name' => 'WavePay Agent Outlet',
            'phone' => '09980000003',
            'balance' => '1500000.00',
            'is_agent' => true,
            'is_fee_account' => false,
            'features' => [AccountFeature::CashIn, AccountFeature::CashOut, AccountFeature::SendMoney, AccountFeature::ReceiveMoney],
        ],
        [
            'company' => 'Wave Money',
            'name' => 'WavePay Fee Collection',
            'phone' => '09980000004',
            'balance' => '0.00',
            'is_agent' => false,
            'is_fee_account' => true,
            'features' => [],
        ],
        [
            'company' => 'AYA Bank',
            'name' => 'AYA Bank Transfer Main',
            'phone' => '09990000001',
            'balance' => '10000000.00',
            'is_agent' => false,
            'is_fee_account' => false,
            'features' => [AccountFeature::Transfer, AccountFeature::Exchange],
        ],
        [
            'company' => 'AYA Bank',
            'name' => 'AYA Bank Agent Counter',
            'phone' => '09990000002',
            'balance' => '5000000.00',
            'is_agent' => true,
            'is_fee_account' => false,
            'features' => [AccountFeature::Transfer, AccountFeature::Exchange],
        ],
    ];

    private const DEMO_FEATURE_TIERS = [
        'KBZPay' => [
            AccountFeature::CashIn->value => ['PERCENTAGE', '0.1000', '0.0000', 'PERCENTAGE', '0.0200'],
            AccountFeature::CashOut->value => ['PERCENTAGE', '0.2000', '0.0000', 'PERCENTAGE', '0.0400'],
            AccountFeature::SendMoney->value => ['FIXED', '500.0000', '0.0000', 'FIXED', '100.0000'],
            AccountFeature::ReceiveMoney->value => ['FIXED', '500.0000', '0.0000', 'FIXED', '100.0000'],
        ],
        'Wave Money' => [
            AccountFeature::CashIn->value => ['PERCENTAGE', '0.1000', '0.0000', 'PERCENTAGE', '0.0200'],
            AccountFeature::CashOut->value => ['PERCENTAGE', '0.2000', '0.0000', 'PERCENTAGE', '0.0400'],
            AccountFeature::SendMoney->value => ['FIXED', '500.0000', '0.0000', 'FIXED', '100.0000'],
            AccountFeature::ReceiveMoney->value => ['FIXED', '500.0000', '0.0000', 'FIXED', '100.0000'],
        ],
    ];

    private const DEMO_OPERATION_PROVIDERS = [
        'KBZPay' => 'Pay',
        'Wave Money' => 'Pay',
        'AYA Pay' => 'Pay',
        'CB Pay' => 'Pay',
        'KBZ Bank' => 'Bank',
        'AYA Bank' => 'Bank',
        'CB Bank' => 'Bank',
        'Yoma Bank' => 'Bank',
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if ($this->shouldSeedDemoUsers()) {
            $this->call(DemoUserSeeder::class);
        }

        $this->call(MyanmarMasterDataSeeder::class);
        $this->seedSetupData();
        $this->seedDemoCompanyAccounts();

        $admin = User::query()->where('username', 'admin')->first();
        if ($admin instanceof User) {
            $this->seedVaultOpeningBalance($admin);
        }
    }

    private function shouldSeedDemoUsers(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    private function seedSetupData(): void
    {
        ExchangeRate::query()->updateOrCreate(
            [
                'base_currency' => 'THB',
                'quote_currency' => 'MMK',
                'company_id' => null,
            ],
            [
                'base_amount' => '1.00',
                'buy_rate' => '145.0000',
                'sell_rate' => '150.0000',
                'is_active' => true,
            ],
        );
    }

    private function seedDemoCompanyAccounts(): void
    {
        foreach (self::DEMO_ACCOUNTS as $seed) {
            $this->seedAccount($seed);
        }

        $this->seedProviderOperationAccounts();
        $this->seedDemoFeatureTiers();
        $this->seedDemoTransferFees();
        $this->seedDemoExchangeRates();
    }

    /**
     * @param  array{company:string,name:string,phone:string,balance:string,is_agent:bool,is_fee_account:bool,features:list<AccountFeature>}  $seed
     */
    private function seedAccount(array $seed): Account
    {
        $company = Company::query()->where('name', $seed['company'])->firstOrFail();
        $account = Account::query()->firstOrNew([
            'account_name' => $seed['name'],
            'phone_number' => $seed['phone'],
        ]);

        $account->fill([
            'company_id' => $company->id,
            'balance' => $seed['balance'],
            'is_active' => true,
            'is_fee_account' => $seed['is_fee_account'],
            'is_agent' => $seed['is_agent'],
        ]);
        $account->save();

        $featureValues = array_map(fn (AccountFeature $feature) => $feature->value, $seed['features']);
        $staleFeatureQuery = AccountFeatureAssignment::query()->where('account_id', $account->id);

        if ($featureValues === []) {
            $staleFeatureQuery->delete();
        } else {
            $staleFeatureQuery->whereNotIn('feature', $featureValues)->delete();
        }

        foreach ($seed['features'] as $feature) {
            AccountFeatureAssignment::query()->updateOrCreate(
                [
                    'account_id' => $account->id,
                    'feature' => $feature->value,
                ],
                [],
            );
        }

        return $account;
    }

    private function seedProviderOperationAccounts(): void
    {
        $index = 10;

        foreach (self::DEMO_OPERATION_PROVIDERS as $companyName => $category) {
            $prefix = str_pad((string) $index, 2, '0', STR_PAD_LEFT);

            if ($category === 'Pay') {
                $this->seedAccount([
                    'company' => $companyName,
                    'name' => "{$companyName} Cash In Account",
                    'phone' => "09{$prefix}1000001",
                    'balance' => '5000000.00',
                    'is_agent' => false,
                    'is_fee_account' => false,
                    'features' => [AccountFeature::CashIn],
                ]);
                $this->seedAccount([
                    'company' => $companyName,
                    'name' => "{$companyName} Cash Out Account",
                    'phone' => "09{$prefix}1000002",
                    'balance' => '5000000.00',
                    'is_agent' => false,
                    'is_fee_account' => false,
                    'features' => [AccountFeature::CashOut],
                ]);
                $this->seedAccount([
                    'company' => $companyName,
                    'name' => "{$companyName} Send Money Agent",
                    'phone' => "09{$prefix}1000003",
                    'balance' => '5000000.00',
                    'is_agent' => true,
                    'is_fee_account' => false,
                    'features' => [AccountFeature::SendMoney, AccountFeature::Transfer],
                ]);
                $this->seedAccount([
                    'company' => $companyName,
                    'name' => "{$companyName} Receive Money Agent",
                    'phone' => "09{$prefix}1000004",
                    'balance' => '5000000.00',
                    'is_agent' => true,
                    'is_fee_account' => false,
                    'features' => [AccountFeature::ReceiveMoney, AccountFeature::Transfer],
                ]);
            } else {
                $this->seedAccount([
                    'company' => $companyName,
                    'name' => "{$companyName} Transfer Main",
                    'phone' => "09{$prefix}2000001",
                    'balance' => '10000000.00',
                    'is_agent' => false,
                    'is_fee_account' => false,
                    'features' => [AccountFeature::Transfer],
                ]);
                $this->seedAccount([
                    'company' => $companyName,
                    'name' => "{$companyName} Transfer Agent",
                    'phone' => "09{$prefix}2000002",
                    'balance' => '7000000.00',
                    'is_agent' => true,
                    'is_fee_account' => false,
                    'features' => [AccountFeature::Transfer, AccountFeature::SendMoney, AccountFeature::ReceiveMoney],
                ]);
            }

            $this->seedAccount([
                'company' => $companyName,
                'name' => "{$companyName} Exchange Account",
                'phone' => "09{$prefix}3000001",
                'balance' => '3000000.00',
                'is_agent' => false,
                'is_fee_account' => false,
                'features' => [AccountFeature::Exchange],
            ]);
            $this->seedAccount([
                'company' => $companyName,
                'name' => "{$companyName} Exchange Agent",
                'phone' => "09{$prefix}3000002",
                'balance' => '3000000.00',
                'is_agent' => true,
                'is_fee_account' => false,
                'features' => [AccountFeature::Exchange],
            ]);
            $this->seedAccount([
                'company' => $companyName,
                'name' => "{$companyName} Fee Collection",
                'phone' => "09{$prefix}9000001",
                'balance' => '0.00',
                'is_agent' => false,
                'is_fee_account' => true,
                'features' => [],
            ]);

            $index++;
        }
    }

    private function seedDemoFeatureTiers(): void
    {
        foreach (array_keys(self::DEMO_OPERATION_PROVIDERS) as $companyName) {
            $company = Company::query()->where('name', $companyName)->firstOrFail();
            $tiers = self::DEMO_FEATURE_TIERS[$companyName] ?? $this->defaultFeatureTiersFor($company->category);

            CommissionTier::query()
                ->where('company_id', $company->id)
                ->whereIn('feature', array_keys($tiers))
                ->delete();

            foreach ($tiers as $feature => [$feeType, $feeAmount, $additionalFee, $commType, $commAmount]) {
                CommissionTier::query()->create([
                    'company_id' => $company->id,
                    'feature' => $feature,
                    'amount_from' => '1.00',
                    'amount_to' => '999999999.00',
                    'fee_type' => $feeType,
                    'fee_amount' => $feeAmount,
                    'additional_fee_type' => 'FIXED',
                    'additional_fee_amount' => $additionalFee,
                    'comm_type' => $commType,
                    'comm_amount' => $commAmount,
                    'is_active' => true,
                ]);
            }
        }
    }

    private function defaultFeatureTiersFor(?string $category): array
    {
        $cashIn = ['PERCENTAGE', '0.1000', '0.0000', 'PERCENTAGE', '0.0200'];
        $cashOut = ['PERCENTAGE', '0.2000', '0.0000', 'PERCENTAGE', '0.0400'];

        if ($category === 'Bank') {
            return [
                AccountFeature::CashIn->value => $cashIn,
                AccountFeature::CashOut->value => $cashOut,
                AccountFeature::SendMoney->value => ['PERCENTAGE', '0.2000', '0.0000', 'PERCENTAGE', '0.0300'],
                AccountFeature::ReceiveMoney->value => ['PERCENTAGE', '0.2000', '0.0000', 'PERCENTAGE', '0.0300'],
            ];
        }

        return [
            AccountFeature::CashIn->value => $cashIn,
            AccountFeature::CashOut->value => $cashOut,
            AccountFeature::SendMoney->value => ['FIXED', '500.0000', '0.0000', 'FIXED', '100.0000'],
            AccountFeature::ReceiveMoney->value => ['FIXED', '500.0000', '0.0000', 'FIXED', '100.0000'],
        ];
    }

    private function seedDemoTransferFees(): void
    {
        $companies = Company::query()
            ->whereIn('name', array_keys(self::DEMO_OPERATION_PROVIDERS))
            ->orderBy('name')
            ->get();

        foreach ($companies as $from) {
            foreach ($companies as $to) {
                if ($from->id === $to->id) {
                    continue;
                }

                TransferFeeTier::query()->updateOrCreate(
                    [
                        'company_from_id' => $from->id,
                        'company_to_id' => $to->id,
                        'amount_from' => '1.00',
                        'amount_to' => '999999999.00',
                    ],
                    [
                        'fee_type' => 'PERCENTAGE',
                        'fee_amount' => '0.2000',
                        'additional_fee_type' => 'FIXED',
                        'additional_fee_amount' => '0.0000',
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    private function seedDemoExchangeRates(): void
    {
        $rates = [
            ['company_id' => null, 'buy_rate' => '145.0000', 'sell_rate' => '150.0000'],
        ];

        foreach (array_keys(self::DEMO_OPERATION_PROVIDERS) as $companyName) {
            $company = Company::query()->where('name', $companyName)->firstOrFail();
            $rates[] = ['company_id' => $company->id, 'buy_rate' => '145.0000', 'sell_rate' => '150.0000'];
        }

        foreach ($rates as $rate) {
            ExchangeRate::query()->updateOrCreate(
                [
                    'company_id' => $rate['company_id'],
                    'base_currency' => 'THB',
                    'quote_currency' => 'MMK',
                ],
                [
                    'base_amount' => '1.00',
                    'buy_rate' => $rate['buy_rate'],
                    'sell_rate' => $rate['sell_rate'],
                    'is_active' => true,
                ],
            );
        }
    }

    private function seedVaultOpeningBalance(User $admin): void
    {
        $existingQuantity = (int) DB::table('vault_denomination_balances')->sum('quantity');
        if ($existingQuantity > 0) {
            return;
        }

        app(CashDenominationRepository::class)->recordBulk(
            entryType: 'vault_in',
            denominations: self::DEMO_VAULT_DENOMINATIONS,
            createdBy: $admin->id,
            note: 'Demo vault opening balance',
        );
    }
}
