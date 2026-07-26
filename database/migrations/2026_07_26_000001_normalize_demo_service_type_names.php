<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $renames = [
            'Demo Cash In' => 'WST',
            'Demo Cash Out' => 'CashOut',
            'Demo Transfer' => 'Transfer',
            'Demo Exchange' => 'Exchange',
        ];

        foreach ($renames as $from => $to) {
            foreach (DB::table('service_types')->where('name', $from)->get(['id', 'company_id']) as $serviceType) {
                $exists = DB::table('service_types')
                    ->where('company_id', $serviceType->company_id)
                    ->where('name', $to)
                    ->exists();

                if (! $exists) {
                    DB::table('service_types')->where('id', $serviceType->id)->update(['name' => $to]);
                }
            }
        }
    }

    public function down(): void
    {
        $renames = [
            'WST' => 'Demo Cash In',
            'CashOut' => 'Demo Cash Out',
            'Transfer' => 'Demo Transfer',
            'Exchange' => 'Demo Exchange',
        ];

        foreach ($renames as $from => $to) {
            foreach (DB::table('service_types')->where('name', $from)->get(['id', 'company_id']) as $serviceType) {
                $exists = DB::table('service_types')
                    ->where('company_id', $serviceType->company_id)
                    ->where('name', $to)
                    ->exists();

                if (! $exists) {
                    DB::table('service_types')->where('id', $serviceType->id)->update(['name' => $to]);
                }
            }
        }
    }
};
