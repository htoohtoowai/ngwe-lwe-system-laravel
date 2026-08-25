<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\AgentCommissionTier;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\ProviderFeeTier;
use App\Models\TransferFeeTier;
use App\Models\User;
use App\Observers\AuditableObserver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);
        DB::prohibitDestructiveCommands(app()->isProduction());

        foreach ([
            User::class,
            Account::class,
            Company::class,
            ExchangeRate::class,
            ProviderFeeTier::class,
            AgentCommissionTier::class,
            TransferFeeTier::class,
        ] as $model) {
            $model::observe(AuditableObserver::class);
        }
    }
}
