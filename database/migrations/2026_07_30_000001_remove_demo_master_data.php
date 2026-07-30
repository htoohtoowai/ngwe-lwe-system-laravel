<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ([
                'Demo Wave Money' => 'Wave Money',
                'Demo KBZPay' => 'KBZPay',
            ] as $demoCompanyName => $masterCompanyName) {
                $demoCompany = DB::table('companies')->where('name', $demoCompanyName)->first();
                $masterCompany = DB::table('companies')->where('name', $masterCompanyName)->first();

                if ($demoCompany === null || $masterCompany === null) {
                    continue;
                }

                $serviceTypes = DB::table('service_types')
                    ->where('company_id', $demoCompany->id)
                    ->orderBy('id')
                    ->get();

                foreach ($serviceTypes as $serviceType) {
                    $isLegacy = str_starts_with($serviceType->name, 'Demo ');
                    $masterServiceName = match ($serviceType->name) {
                        'Demo Cash In' => 'WST',
                        'Demo Cash Out' => 'CashOut',
                        'Demo Transfer' => 'Transfer',
                        'Demo Exchange' => 'Exchange',
                        default => $serviceType->name,
                    };

                    $masterServiceType = DB::table('service_types')
                        ->where('company_id', $masterCompany->id)
                        ->where('name', $masterServiceName)
                        ->first();

                    if ($masterServiceType === null) {
                        $masterServiceTypeId = DB::table('service_types')->insertGetId([
                            'company_id' => $masterCompany->id,
                            'name' => $masterServiceName,
                            'operation' => $serviceType->operation,
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } else {
                        $masterServiceTypeId = $masterServiceType->id;
                    }

                    DB::table('accounts')
                        ->where('service_type_id', $serviceType->id)
                        ->get(['id', 'account_name'])
                        ->each(function (object $account) use ($masterServiceTypeId, $isLegacy): void {
                            $name = preg_replace('/^Demo\s+/i', '', $account->account_name) ?? $account->account_name;

                            DB::table('accounts')
                                ->where('id', $account->id)
                                ->update([
                                    'service_type_id' => $masterServiceTypeId,
                                    'account_name' => $isLegacy ? $name.' Legacy' : $name,
                                    'updated_at' => now(),
                                ]);
                        });

                    if ($isLegacy) {
                        DB::table('commission_tiers')
                            ->where('service_type_id', $serviceType->id)
                            ->delete();
                    } else {
                        DB::table('commission_tiers')
                            ->where('service_type_id', $masterServiceTypeId)
                            ->delete();
                        DB::table('commission_tiers')
                            ->where('service_type_id', $serviceType->id)
                            ->update(['service_type_id' => $masterServiceTypeId]);
                    }

                    DB::table('service_types')->where('id', $serviceType->id)->delete();
                }

                DB::table('transactions')
                    ->where('from_company_id', $demoCompany->id)
                    ->update(['from_company_id' => $masterCompany->id]);
                DB::table('transactions')
                    ->where('to_company_id', $demoCompany->id)
                    ->update(['to_company_id' => $masterCompany->id]);

                DB::table('companies')->where('id', $demoCompany->id)->delete();
            }
        });
    }

    public function down(): void
    {
        // Demo master data is intentionally not recreated.
    }
};
