<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ServiceType;
use Illuminate\Database\Seeder;

class TransferScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSystemAccount(
            companyName: 'KBZPay',
            serviceName: 'Transfer',
            accountName: 'System KBZPay Transfer',
            accountNumber: '09980000100',
            openingBalance: '3000000.00',
        );

        $this->seedSystemAccount(
            companyName: 'CB Bank',
            serviceName: 'Bank Transfer',
            accountName: 'System CB Bank Transfer',
            accountNumber: '001-001122-001',
            openingBalance: '5000000.00',
        );
    }

    private function seedSystemAccount(
        string $companyName,
        string $serviceName,
        string $accountName,
        string $accountNumber,
        string $openingBalance,
    ): void {
        $company = Company::query()->where('name', $companyName)->firstOrFail();
        $serviceType = ServiceType::query()->firstOrCreate(
            ['company_id' => $company->id, 'name' => $serviceName],
            ['operation' => 'Transfer', 'is_active' => true],
        );

        $account = Account::query()->firstOrNew([
            'service_type_id' => $serviceType->id,
            'account_name' => $accountName,
        ]);

        if (! $account->exists) {
            $account->balance = $openingBalance;
        }

        $account->fill([
            'phone_number' => $accountNumber,
            'commission_rate' => '0.0000',
            'is_active' => true,
            'is_fee_account' => false,
        ])->save();

        if (! $serviceType->commissionTiers()->exists()) {
            CommissionTier::query()->create([
                'service_type_id' => $serviceType->id,
                'amount_from' => '1.00',
                'amount_to' => '999999999999.00',
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
            ]);
        }
    }
}
