<?php

namespace Database\Seeders;

use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\ServiceType;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
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

    /**
     * Wave Money WST table from the supplied branch reference spreadsheet.
     *
     * Columns: amount_from, amount_to, customer_fee, agent_comm_send,
     * agent_comm_receive.
     */
    private const WAVE_WST_COMMISSION_TIERS = [
        [1, 10_000, 400, 69, 88],
        [10_001, 25_000, 700, 123, 172],
        [25_001, 50_000, 1_000, 147, 245],
        [50_001, 100_000, 1_500, 196, 392],
        [100_001, 150_000, 2_000, 294, 490],
        [150_001, 200_000, 2_500, 392, 588],
        [200_001, 300_000, 3_000, 490, 686],
        [300_001, 400_000, 4_000, 653, 915],
        [400_001, 500_000, 4_500, 735, 1_029],
        [500_001, 600_000, 5_400, 882, 1_235],
        [600_001, 700_000, 6_000, 980, 1_372],
        [700_001, 800_000, 6_700, 1_094, 1_532],
        [800_001, 900_000, 7_400, 1_209, 1_692],
        [900_001, 1_000_000, 8_000, 1_307, 1_829],
    ];

    /**
     * KPay WST table from the supplied branch reference spreadsheet.
     *
     * Columns: amount_from, amount_to, customer_fee, agent_comm_send,
     * agent_comm_receive.
     */
    private const KPAY_WST_COMMISSION_TIERS = [
        [1, 10_000, 400, 80, 80],
        [10_001, 25_000, 700, 140, 140],
        [25_001, 50_000, 1_000, 200, 200],
        [50_001, 100_000, 1_500, 300, 300],
        [100_001, 150_000, 2_000, 400, 400],
        [150_001, 200_000, 2_500, 500, 500],
        [200_001, 300_000, 3_000, 600, 600],
        [300_001, 400_000, 4_000, 800, 800],
        [400_001, 500_000, 4_500, 900, 900],
        [500_001, 600_000, 5_200, 1_040, 1_040],
        [600_001, 700_000, 5_800, 1_160, 1_160],
        [700_001, 800_000, 6_500, 1_300, 1_300],
        [800_001, 900_000, 7_200, 1_440, 1_440],
        [900_001, 1_000_000, 7_800, 1_560, 1_560],
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
        $serviceTypes = collect([
            'wave_wst' => $this->masterServiceType('Wave Money', 'WST'),
            'kpay_wst' => $this->masterServiceType('KBZPay', 'WST'),
        ]);
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

    private function masterServiceType(string $companyName, string $serviceName): ServiceType
    {
        $company = Company::query()->where('name', $companyName)->firstOrFail();

        return ServiceType::query()
            ->where('company_id', $company->id)
            ->where('name', $serviceName)
            ->firstOrFail();
    }

    /**
     * @param  Collection<string, ServiceType>  $serviceTypes
     */
    private function seedCommissionTiers(Collection $serviceTypes): void
    {
        foreach ($serviceTypes as $key => $serviceType) {
            CommissionTier::query()
                ->where('service_type_id', $serviceType->id)
                ->delete();

            foreach ($this->commissionTiersFor((string) $key) as [$from, $to, $fee, $sendComm, $receiveComm]) {
                CommissionTier::query()->create([
                    'service_type_id' => $serviceType->id,
                    'amount_from' => number_format($from, 2, '.', ''),
                    'amount_to' => number_format($to, 2, '.', ''),
                    'fee_amount_type' => 'FIXED',
                    'fee_amount_deposit' => number_format($fee, 4, '.', ''),
                    'fee_amount_withdraw' => (string) ($key === 'wave_wst' || $key === 'kpay_wst'
                        ? '0.0000'
                        : number_format($fee, 4, '.', '')),
                    'comm_type' => 'FIXED',
                    'comm_deposit' => number_format($sendComm, 4, '.', ''),
                    'comm_withdraw' => number_format($receiveComm, 4, '.', ''),
                    'additional_fee_type' => 'FIXED',
                    'additional_fee_deposit_amount' => '0.0000',
                    'additional_fee_withdraw_amount' => '0.0000',
                    'is_active' => true,
                ]);
            }
        }
    }

    private function commissionTiersFor(string $serviceTypeKey): array
    {
        return match ($serviceTypeKey) {
            'wave_wst' => self::WAVE_WST_COMMISSION_TIERS,
            'kpay_wst' => self::KPAY_WST_COMMISSION_TIERS,
            default => [],
        };
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
