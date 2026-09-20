<?php

use App\Enums\AccountFeature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_commission_tiers', function (Blueprint $table) {
            $table->string('feature', 32)
                ->default(AccountFeature::SendMoney->value)
                ->after('company_id');

            $table->index(
                ['company_id', 'feature', 'is_active'],
                'agent_comm_tiers_company_feature_active_index',
            );
        });

        $existingTiers = DB::table('agent_commission_tiers')->orderBy('id')->get();

        foreach ($existingTiers as $tier) {
            foreach ([
                AccountFeature::CashIn->value,
                AccountFeature::CashOut->value,
                AccountFeature::ReceiveMoney->value,
            ] as $feature) {
                DB::table('agent_commission_tiers')->insert([
                    'company_id' => $tier->company_id,
                    'feature' => $feature,
                    'amount_from' => $tier->amount_from,
                    'amount_to' => $tier->amount_to,
                    'commission_type' => $tier->commission_type,
                    'out_commission_value' => $tier->out_commission_value,
                    'in_commission_value' => $tier->in_commission_value,
                    'is_active' => $tier->is_active,
                    'created_at' => $tier->created_at,
                    'updated_at' => $tier->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('agent_commission_tiers')
            ->where('feature', '!=', AccountFeature::SendMoney->value)
            ->delete();

        Schema::table('agent_commission_tiers', function (Blueprint $table) {
            $table->dropIndex('agent_comm_tiers_company_feature_active_index');
            $table->dropColumn('feature');
        });
    }
};
