<?php

namespace App\Services;

use App\Http\Controllers\Api\VaultController;
use App\Http\Resources\AccountResource;
use App\Http\Resources\ActivityLogResource;
use App\Http\Resources\CashFloatResource;
use App\Http\Resources\CommissionTierResource;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\ExchangeRateResource;
use App\Http\Resources\ServiceTypeResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\UserResource;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\ServiceType;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\CashFloatRepository;
use Illuminate\Http\Request;

class AdminOperationsDataService
{
    public function __construct(
        private readonly DailyReportService $reports,
        private readonly CashFloatRepository $floats,
    ) {}

    /** @return array<string, mixed> */
    public function get(Request $request): array
    {
        return [
            'dailySummary' => $this->reports->summary((string) $request->query('report_date', now()->toDateString())),
            'companies' => CompanyResource::collection(Company::query()->orderBy('name')->get())->resolve($request),
            'serviceTypes' => ServiceTypeResource::collection(ServiceType::query()->with('company')->orderBy('name')->get())->resolve($request),
            'accounts' => AccountResource::collection(Account::query()->with(['company', 'serviceType.company', 'featureAssignments'])->orderBy('account_name')->get())->resolve($request),
            'users' => UserResource::collection(User::query()->orderBy('full_name')->get())->resolve($request),
            'transactions' => TransactionResource::collection(Transaction::query()->latest()->limit(200)->get())->resolve($request),
            'activityLogs' => ActivityLogResource::collection(ActivityLog::query()->with('user')->latest()->limit(200)->get())->resolve($request),
            'cashFloats' => CashFloatResource::collection($this->floats->list())->resolve($request),
            'vaultInventory' => app(VaultController::class)->inventory()->getData(true)['data'] ?? null,
            'exchangeRates' => ExchangeRateResource::collection(ExchangeRate::query()->with('company')->latest()->limit(50)->get())->resolve($request),
            'commissionTiers' => CommissionTierResource::collection(CommissionTier::query()->with(['company', 'serviceType.company'])->orderBy('amount_from')->get())->resolve($request),
        ];
    }
}
