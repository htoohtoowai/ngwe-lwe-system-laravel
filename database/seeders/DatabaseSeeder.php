<?php

namespace Database\Seeders;

use App\Enums\AccountFeature;
use App\Models\Account;
use App\Models\AccountFeatureAssignment;
use App\Models\AgentCommissionTier;
use App\Models\ProviderFeeTier;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\TransferFeeTier;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Repositories\VaultTransactionRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            'identifier' => '09256149967',
            'balance' => '5000000.00',
            'is_agent' => false,
            'is_fee_account' => false,
            'features' => [AccountFeature::CashIn],
        ],
        [
            'company' => 'KBZPay',
            'name' => 'KBZPay Cash Out Main',
            'identifier' => '09256149968',
            'balance' => '3000000.00',
            'is_agent' => false,
            'is_fee_account' => false,
            'features' => [AccountFeature::CashOut],
        ],
        [
            'company' => 'KBZPay',
            'name' => 'KBZPay Agent Outlet',
            'identifier' => '09256149970',
            'balance' => '2000000.00',
            'is_agent' => true,
            'is_fee_account' => false,
            'features' => [AccountFeature::CashIn, AccountFeature::CashOut, AccountFeature::SendMoney, AccountFeature::ReceiveMoney, AccountFeature::Transfer, AccountFeature::Exchange],
        ],
        [
            'company' => 'KBZPay',
            'name' => 'KBZPay Agent Outlet 2',
            'identifier' => '09256149971',
            'balance' => '2000000.00',
            'is_agent' => true,
            'is_fee_account' => false,
            'features' => [AccountFeature::CashIn, AccountFeature::CashOut, AccountFeature::SendMoney, AccountFeature::ReceiveMoney, AccountFeature::Transfer, AccountFeature::Exchange],
        ],
        [
            'company' => 'KBZPay',
            'name' => 'KBZPay Fee Collection',
            'identifier' => '09256149969',
            'balance' => '0.00',
            'is_agent' => false,
            'is_fee_account' => true,
            'features' => [],
        ],
        [
            'company' => 'Wave Money',
            'name' => 'WavePay Teller Main',
            'identifier' => '09980000001',
            'balance' => '4000000.00',
            'is_agent' => false,
            'is_fee_account' => false,
            'features' => [AccountFeature::CashIn],
        ],
        [
            'company' => 'Wave Money',
            'name' => 'WavePay Cash Out Main',
            'identifier' => '09980000002',
            'balance' => '2500000.00',
            'is_agent' => false,
            'is_fee_account' => false,
            'features' => [AccountFeature::CashOut],
        ],
        [
            'company' => 'Wave Money',
            'name' => 'WavePay Agent Outlet',
            'identifier' => '09980000003',
            'balance' => '1500000.00',
            'is_agent' => true,
            'is_fee_account' => false,
            'features' => [AccountFeature::CashIn, AccountFeature::CashOut, AccountFeature::SendMoney, AccountFeature::ReceiveMoney],
        ],
        [
            'company' => 'Wave Money',
            'name' => 'WavePay Fee Collection',
            'identifier' => '09980000004',
            'balance' => '0.00',
            'is_agent' => false,
            'is_fee_account' => true,
            'features' => [],
        ],
        [
            'company' => 'AYA Bank',
            'name' => 'AYA Bank Transfer Main',
            'identifier' => '1009900000001',
            'balance' => '10000000.00',
            'is_agent' => false,
            'is_fee_account' => false,
            'features' => [AccountFeature::Transfer, AccountFeature::Exchange],
        ],
        [
            'company' => 'AYA Bank',
            'name' => 'AYA Bank Secondary',
            'identifier' => '1009900000002',
            'balance' => '5000000.00',
            'is_agent' => false,
            'is_fee_account' => false,
            'features' => [AccountFeature::Transfer, AccountFeature::Exchange],
        ],
    ];

    private const DEMO_PROVIDER_FEE_TIERS = [
        'KBZPay' => [
            AccountFeature::CashIn->value => ['PERCENTAGE', '0.1000', 'FIXED', '0.0000'],
            AccountFeature::CashOut->value => ['PERCENTAGE', '0.2000', 'FIXED', '0.0000'],
        ],
        'Wave Money' => [
            AccountFeature::CashIn->value => ['PERCENTAGE', '0.1000', 'FIXED', '0.0000'],
            AccountFeature::CashOut->value => ['PERCENTAGE', '0.2000', 'FIXED', '0.0000'],
        ],
    ];

    private const DEMO_AGENT_COMMISSION_TIERS = [
        'AYA Pay' => ['FIXED', '100.0000', '100.0000'],
        'CB Pay' => ['FIXED', '100.0000', '100.0000'],
    ];

    /** Wave Money official Send fee plus Agent OUT/IN commission table supplied for this project. */
    private const WAVE_MONEY_AGENT_TIERS = [
        [1, 10000, 400, 69, 88],
        [10001, 25000, 700, 123, 172],
        [25001, 50000, 1000, 147, 245],
        [50001, 100000, 1500, 196, 392],
        [100001, 150000, 2000, 294, 490],
        [150001, 200000, 2500, 392, 588],
        [200001, 300000, 3000, 490, 686],
        [300001, 400000, 4000, 653, 915],
        [400001, 500000, 4500, 735, 1029],
        [500001, 600000, 5400, 882, 1235],
        [600001, 700000, 6000, 980, 1372],
        [700001, 800000, 6700, 1094, 1532],
        [800001, 900000, 7400, 1209, 1692],
        [900001, 1000000, 8000, 1307, 1829],
    ];

    /** KBZPay Send fee plus Agent OUT commission. IN commission is intentionally zero until source data is supplied. */
    private const KBZPAY_AGENT_TIERS = [
        [1, 10000, 400, 80, 0],
        [10001, 25000, 700, 140, 0],
        [25001, 50000, 1000, 200, 0],
        [50001, 100000, 1500, 300, 0],
        [100001, 150000, 2000, 400, 0],
        [150001, 200000, 2500, 500, 0],
        [200001, 300000, 3000, 600, 0],
        [300001, 400000, 4000, 800, 0],
        [400001, 500000, 4500, 900, 0],
        [500001, 600000, 5200, 1040, 0],
        [600001, 700000, 5800, 1160, 0],
        [700001, 800000, 6500, 1300, 0],
        [800001, 900000, 7200, 1440, 0],
        [900001, 1000000, 7800, 1560, 0],
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
        $this->seedDemoPricingTiers();
        $this->seedDemoTransferFees();
        $this->seedDemoExchangeRates();
    }

    /**
     * @param  array{company:string,name:string,identifier:string,balance:string,is_agent:bool,is_fee_account:bool,features:list<AccountFeature>}  $seed
     */
    private function seedAccount(array $seed): Account
    {
        $company = Company::query()->where('name', $seed['company'])->firstOrFail();
        $accountType = strtoupper((string) $company->category);
        if (! in_array($accountType, ['PAY', 'BANK'], true)) {
            throw new \RuntimeException("Demo account {$seed['name']} needs an explicit PAY or BANK provider category.");
        }

        $account = Account::query()->firstOrNew([
            'company_id' => $company->id,
            'account_identifier' => $seed['identifier'],
        ]);

        $account->fill([
            'account_name' => $seed['name'],
            'account_type' => $accountType,
            'balance' => $seed['balance'],
            'is_active' => true,
            'is_fee_account' => $seed['is_fee_account'],
            'is_agent' => $accountType === 'PAY' && $seed['is_agent'],
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
                    'identifier' => "09{$prefix}1000001",
                    'balance' => '5000000.00',
                    'is_agent' => false,
                    'is_fee_account' => false,
                    'features' => [AccountFeature::CashIn],
                ]);
                $this->seedAccount([
                    'company' => $companyName,
                    'name' => "{$companyName} Cash Out Account",
                    'identifier' => "09{$prefix}1000002",
                    'balance' => '5000000.00',
                    'is_agent' => false,
                    'is_fee_account' => false,
                    'features' => [AccountFeature::CashOut],
                ]);
                $this->seedAccount([
                    'company' => $companyName,
                    'name' => "{$companyName} Send Money Agent",
                    'identifier' => "09{$prefix}1000003",
                    'balance' => '5000000.00',
                    'is_agent' => true,
                    'is_fee_account' => false,
                    'features' => [AccountFeature::SendMoney, AccountFeature::Transfer],
                ]);
                $this->seedAccount([
                    'company' => $companyName,
                    'name' => "{$companyName} Receive Money Agent",
                    'identifier' => "09{$prefix}1000004",
                    'balance' => '5000000.00',
                    'is_agent' => true,
                    'is_fee_account' => false,
                    'features' => [AccountFeature::ReceiveMoney, AccountFeature::Transfer],
                ]);
            } else {
                $this->seedAccount([
                    'company' => $companyName,
                    'name' => "{$companyName} Transfer Main",
                    'identifier' => "10{$prefix}200000001",
                    'balance' => '10000000.00',
                    'is_agent' => false,
                    'is_fee_account' => false,
                    'features' => [AccountFeature::Transfer],
                ]);
                $this->seedAccount([
                    'company' => $companyName,
                    'name' => "{$companyName} Transfer Secondary",
                    'identifier' => "10{$prefix}200000002",
                    'balance' => '7000000.00',
                    'is_agent' => false,
                    'is_fee_account' => false,
                    'features' => [AccountFeature::Transfer],
                ]);
            }

            $this->seedAccount([
                'company' => $companyName,
                'name' => "{$companyName} Exchange Account",
                'identifier' => $category === 'Pay' ? "09{$prefix}3000001" : "10{$prefix}300000001",
                'balance' => '3000000.00',
                'is_agent' => false,
                'is_fee_account' => false,
                'features' => [AccountFeature::Exchange],
            ]);
            $this->seedAccount([
                'company' => $companyName,
                'name' => "{$companyName} Exchange Secondary",
                'identifier' => $category === 'Pay' ? "09{$prefix}3000002" : "10{$prefix}300000002",
                'balance' => '3000000.00',
                'is_agent' => $category === 'Pay',
                'is_fee_account' => false,
                'features' => [AccountFeature::Exchange],
            ]);
            $this->seedAccount([
                'company' => $companyName,
                'name' => "{$companyName} Fee Collection",
                'identifier' => $category === 'Pay' ? "09{$prefix}9000001" : "10{$prefix}900000001",
                'balance' => '0.00',
                'is_agent' => false,
                'is_fee_account' => true,
                'features' => [],
            ]);

            $index++;
        }
    }

    private function seedDemoPricingTiers(): void
    {
        foreach (array_keys(self::DEMO_OPERATION_PROVIDERS) as $companyName) {
            $company = Company::query()->where('name', $companyName)->firstOrFail();
            $providerTiers = self::DEMO_PROVIDER_FEE_TIERS[$companyName] ?? $this->defaultProviderFeeTiersFor($company->category);
            $commissionTier = self::DEMO_AGENT_COMMISSION_TIERS[$companyName] ?? $this->defaultAgentCommissionTierFor($company->category);

            ProviderFeeTier::query()
                ->where('company_id', $company->id)
                ->delete();
            AgentCommissionTier::query()
                ->where('company_id', $company->id)
                ->delete();

            foreach ($providerTiers as $feature => [$feeType, $feeValue, $additionalFeeType, $additionalFeeValue]) {
                ProviderFeeTier::query()->create([
                    'company_id' => $company->id,
                    'feature' => $feature,
                    'amount_from' => '1.00',
                    'amount_to' => '999999999.00',
                    'fee_type' => $feeType,
                    'fee_value' => $feeValue,
                    'additional_fee_type' => $additionalFeeType,
                    'additional_fee_value' => $additionalFeeValue,
                    'is_active' => true,
                ]);
            }

            $agentMoneyTiers = match ($companyName) {
                'Wave Money' => self::WAVE_MONEY_AGENT_TIERS,
                'KBZPay' => self::KBZPAY_AGENT_TIERS,
                default => null,
            };

            if ($agentMoneyTiers !== null) {
                foreach ($agentMoneyTiers as [$from, $to, $sendFee, $outCommission, $inCommission]) {
                    ProviderFeeTier::query()->create([
                        'company_id' => $company->id,
                        'feature' => AccountFeature::SendMoney->value,
                        'amount_from' => number_format($from, 2, '.', ''),
                        'amount_to' => number_format($to, 2, '.', ''),
                        'fee_type' => 'FIXED',
                        'fee_value' => number_format($sendFee, 4, '.', ''),
                        'additional_fee_type' => 'FIXED',
                        'additional_fee_value' => '0.0000',
                        'is_active' => true,
                    ]);

                    AgentCommissionTier::query()->create([
                        'company_id' => $company->id,
                        'amount_from' => number_format($from, 2, '.', ''),
                        'amount_to' => number_format($to, 2, '.', ''),
                        'commission_type' => 'FIXED',
                        'out_commission_value' => number_format($outCommission, 4, '.', ''),
                        'in_commission_value' => number_format($inCommission, 4, '.', ''),
                        'is_active' => true,
                    ]);
                }

                // Receive Money has no customer-fee source table yet, so no ReceiveMoney ProviderFeeTier is seeded.
                continue;
            }

            if ($commissionTier !== null) {
                [$commissionType, $outCommissionValue, $inCommissionValue] = $commissionTier;
                AgentCommissionTier::query()->create([
                    'company_id' => $company->id,
                    'amount_from' => '1.00',
                    'amount_to' => '999999999.00',
                    'commission_type' => $commissionType,
                    'out_commission_value' => $outCommissionValue,
                    'in_commission_value' => $inCommissionValue,
                    'is_active' => true,
                ]);
            }
        }
    }

    private function defaultProviderFeeTiersFor(?string $category): array
    {
        if ($category === 'Bank') {
            return [
                AccountFeature::CashIn->value => ['PERCENTAGE', '0.1000', 'FIXED', '0.0000'],
                AccountFeature::CashOut->value => ['PERCENTAGE', '0.2000', 'FIXED', '0.0000'],
                AccountFeature::SendMoney->value => ['PERCENTAGE', '0.2000', 'FIXED', '0.0000'],
                AccountFeature::ReceiveMoney->value => ['PERCENTAGE', '0.2000', 'FIXED', '0.0000'],
            ];
        }

        return [
            AccountFeature::CashIn->value => ['PERCENTAGE', '0.1000', 'FIXED', '0.0000'],
            AccountFeature::CashOut->value => ['PERCENTAGE', '0.2000', 'FIXED', '0.0000'],
            AccountFeature::SendMoney->value => ['FIXED', '500.0000', 'FIXED', '0.0000'],
            AccountFeature::ReceiveMoney->value => ['FIXED', '500.0000', 'FIXED', '0.0000'],
        ];
    }

    /**
     * Bank accounts never earn agent commission. Pay providers can define one
     * amount-range row containing OUT / Send and IN / Receive values.
     *
     * @return array{string,string,string}|null
     */
    private function defaultAgentCommissionTierFor(?string $category): ?array
    {
        if ($category !== 'Pay') {
            return null;
        }

        return ['FIXED', '100.0000', '100.0000'];
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
                        'fee_value' => '0.2000',
                        'additional_fee_type' => 'FIXED',
                        'additional_fee_value' => '0.0000',
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

        $cashier = User::query()
            ->where('role', 'cashier')
            ->where('is_active', true)
            ->first();

        DB::transaction(function () use ($admin, $cashier): void {
            $batchId = (string) Str::uuid();
            $note = 'Demo vault opening balance';

            app(CashDenominationRepository::class)->recordBulk(
                entryType: 'vault_in',
                denominations: self::DEMO_VAULT_DENOMINATIONS,
                createdBy: $admin->id,
                note: $note,
                batchId: $batchId,
                movementType: 'admin_to_cashier',
                sourceType: 'admin',
                sourceId: $admin->id,
                destinationType: 'cashier_vault',
                destinationId: $cashier?->id,
                affectsMainVault: true,
            );

            app(VaultTransactionRepository::class)->recordBulk(
                txnType: 'adjustment',
                denominations: self::DEMO_VAULT_DENOMINATIONS,
                performedBy: $admin->id,
                note: $note,
                batchId: $batchId,
                movementType: 'admin_to_cashier',
                sourceType: 'admin',
                sourceId: $admin->id,
                destinationType: 'cashier_vault',
                destinationId: $cashier?->id,
            );
        });
    }
}
