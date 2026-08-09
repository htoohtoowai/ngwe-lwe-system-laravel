<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MyanmarMasterDataSeeder extends Seeder
{
    private const LOCAL_IMAGE_DIR = 'C:\\Users\\User\\Downloads\\Company Image';

    /**
     * @var array<int, array{name:string,category:string,local_logo?:string,remote_logo?:string}>
     */
    private const COMPANIES = [
        ['name' => 'KBZ Bank', 'category' => 'Bank', 'local_logo' => 'KBZ Bank.png'],
        ['name' => 'KBZPay', 'category' => 'Pay', 'local_logo' => 'KBZ Pay.png'],
        ['name' => 'Wave Money', 'category' => 'Pay', 'local_logo' => 'Wave Money.jpg'],
        ['name' => 'AYA Bank', 'category' => 'Bank', 'local_logo' => 'AYA BANK.png'],
        ['name' => 'AYA Pay', 'category' => 'Pay', 'local_logo' => 'AYA PAY.png'],
        ['name' => 'CB Bank', 'category' => 'Bank', 'local_logo' => 'CB BANK.jpg'],
        ['name' => 'CB Pay', 'category' => 'Pay', 'local_logo' => 'CB PAY.jpg'],
        ['name' => 'Yoma Bank', 'category' => 'Bank', 'local_logo' => 'YOMA BANK.svg'],

        ['name' => 'AGD Bank', 'category' => 'Bank', 'remote_logo' => 'agd-bank.png'],
        ['name' => 'A Bank', 'category' => 'Bank', 'remote_logo' => 'a-bank.png'],
        ['name' => 'Asia Yangon Bank', 'category' => 'Bank', 'remote_logo' => 'asia-yangon-bank.png'],
        ['name' => 'CHID Bank', 'category' => 'Bank', 'remote_logo' => 'chid-bank.png'],
        ['name' => 'Farmers Development Bank', 'category' => 'Bank', 'remote_logo' => 'farmers-development-bank.png'],
        ['name' => 'First Private Bank', 'category' => 'Bank', 'remote_logo' => 'first-private-bank.png'],
        ['name' => 'G Bank', 'category' => 'Bank', 'remote_logo' => 'g-bank.png'],
        ['name' => 'Global Treasure Bank', 'category' => 'Bank', 'remote_logo' => 'global-treasure-bank.png'],
        ['name' => 'Innwa Bank', 'category' => 'Bank', 'remote_logo' => 'innwa-bank.png'],
        ['name' => 'Mineral Development Bank', 'category' => 'Bank', 'remote_logo' => 'mineral-development-bank.png'],
        ['name' => 'Myanma Agricultural Development Bank', 'category' => 'Bank', 'remote_logo' => 'myanma-agricultural-development-bank.png'],
        ['name' => 'Myanma Apex Bank', 'category' => 'Bank', 'remote_logo' => 'myanma-apex-bank.png'],
        ['name' => 'Myanma Economic Bank', 'category' => 'Bank', 'remote_logo' => 'myanma-economic-bank.png'],
        ['name' => 'Myanma Foreign Trade Bank', 'category' => 'Bank', 'remote_logo' => 'myanma-foreign-trade-bank.png'],
        ['name' => 'Myanma Investment and Commercial Bank', 'category' => 'Bank', 'remote_logo' => 'myanma-investment-and-commercial-bank.png'],
        ['name' => 'Myanmar Citizens Bank', 'category' => 'Bank', 'remote_logo' => 'myanmar-citizens-bank.png'],
        ['name' => 'Myanmar Metro Bank', 'category' => 'Bank', 'remote_logo' => 'myanmar-metro-bank.png'],
        ['name' => 'Myanmar Oriental Bank', 'category' => 'Bank', 'remote_logo' => 'myanmar-oriental-bank.png'],
        ['name' => 'Myanma Tourism Bank', 'category' => 'Bank', 'remote_logo' => 'myanma-tourism-bank.png'],
        ['name' => 'Myawaddy Bank', 'category' => 'Bank', 'remote_logo' => 'myawaddy-bank.png'],
        ['name' => 'Naypyitaw Development Bank', 'category' => 'Bank', 'remote_logo' => 'naypyitaw-development-bank.png'],
        ['name' => 'Rural Development Bank', 'category' => 'Bank', 'remote_logo' => 'rural-development-bank.png'],
        ['name' => 'Shwe Bank', 'category' => 'Bank', 'remote_logo' => 'shwe-bank.png'],
        ['name' => 'SME Development Bank', 'category' => 'Bank', 'remote_logo' => 'sme-development-bank.png'],
        ['name' => 'Tun Commercial Bank', 'category' => 'Bank', 'remote_logo' => 'tun-commercial-bank.png'],
        ['name' => 'uab Bank', 'category' => 'Bank', 'remote_logo' => 'uab-bank.png'],
        ['name' => 'Yadanabon Bank', 'category' => 'Bank', 'remote_logo' => 'yadanabon-bank.png'],
        ['name' => 'Yangon City Bank', 'category' => 'Bank', 'remote_logo' => 'yangon-city-bank.png'],

        ['name' => 'CTZ Pay', 'category' => 'Pay', 'remote_logo' => 'ctz-pay.png'],
        ['name' => 'M-Pitesan', 'category' => 'Pay', 'remote_logo' => 'm-pitesan.png'],
        ['name' => 'MPT Pay', 'category' => 'Pay', 'remote_logo' => 'mpt-pay.png'],
        ['name' => 'MPU', 'category' => 'Pay', 'remote_logo' => 'mpu.png'],
        ['name' => 'OK Dollar', 'category' => 'Pay', 'remote_logo' => 'ok-dollar.png'],
        ['name' => 'OnePay', 'category' => 'Pay', 'remote_logo' => 'onepay.png'],
        ['name' => 'Trusty Pay', 'category' => 'Pay', 'remote_logo' => 'trusty-pay.png'],
        ['name' => 'TrueMoney Myanmar', 'category' => 'Pay', 'remote_logo' => 'truemoney-myanmar.png'],
        ['name' => 'uabpay', 'category' => 'Pay', 'remote_logo' => 'uabpay.png'],
        ['name' => 'ZegoMoney', 'category' => 'Pay', 'remote_logo' => 'zegomoney.png'],
    ];

    public function run(): void
    {
        File::ensureDirectoryExists(storage_path('app/public/company-logos'));

        foreach (self::COMPANIES as $seed) {
            $logoPath = $this->logoPathFor($seed);

            $company = Company::query()->updateOrCreate(
                ['name' => $seed['name']],
                [
                    'category' => $seed['category'],
                    'is_active' => true,
                    ...($logoPath !== null ? ['logo_path' => $logoPath] : []),
                ],
            );

            $this->seedServiceTypes($company);
        }
    }

    /**
     * @param  array{name:string,category:string,local_logo?:string,remote_logo?:string}  $seed
     */
    private function logoPathFor(array $seed): ?string
    {
        if (isset($seed['local_logo'])) {
            $extension = pathinfo($seed['local_logo'], PATHINFO_EXTENSION);
            $target = 'company-logos/'.Str::slug($seed['name']).'.'.$extension;
            $seedAsset = database_path('seeders/'.$target);

            if (File::exists($seedAsset)) {
                File::copy($seedAsset, storage_path('app/public/'.$target));

                return $target;
            }

            $source = self::LOCAL_IMAGE_DIR.'\\'.$seed['local_logo'];
            if (File::exists($source)) {
                File::copy($source, storage_path('app/public/'.$target));

                return $target;
            }
        }

        if (isset($seed['remote_logo'])) {
            $target = 'company-logos/'.$seed['remote_logo'];
            $seedAsset = database_path('seeders/'.$target);
            if (File::exists($seedAsset)) {
                File::copy($seedAsset, storage_path('app/public/'.$target));

                return $target;
            }

            if (File::exists(storage_path('app/public/'.$target))) {
                return $target;
            }
        }

        return null;
    }

    private function seedServiceTypes(Company $company): void
    {
        if (in_array($company->category, ['Bank', 'Both'], true)) {
            $company->serviceTypes()->updateOrCreate(
                ['name' => 'Bank Transfer'],
                ['operation' => 'Transfer', 'is_active' => true],
            );
            $company->serviceTypes()->updateOrCreate(
                ['name' => 'Exchange'],
                ['operation' => 'Exchange', 'is_active' => true],
            );
        }

        if (in_array($company->category, ['Pay', 'Both'], true)) {
            foreach ([
                'WST' => 'CashIn',
                'CashOut' => 'CashOut',
                'Transfer' => 'Transfer',
                'Exchange' => 'Exchange',
            ] as $name => $operation) {
                $company->serviceTypes()->updateOrCreate(
                    ['name' => $name],
                    ['operation' => $operation, 'is_active' => true],
                );
            }
        }
    }
}
