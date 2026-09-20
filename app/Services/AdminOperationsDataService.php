<?php

namespace App\Services;

use App\Http\Resources\AccountResource;
use App\Http\Resources\AgentCommissionTierResource;
use App\Http\Resources\BranchResource;
use App\Http\Resources\CashFloatResource;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\ExchangeRateResource;
use App\Http\Resources\ProviderFeeTierResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\UserResource;
use App\Models\Account;
use App\Models\AgentCommissionTier;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\ProviderFeeTier;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\CashFloatRepository;
use Illuminate\Http\Request;

class AdminOperationsDataService
{
    public function __construct(
        private readonly DailyReportService $reports,
        private readonly CashFloatRepository $floats,
        private readonly VaultInventoryService $vaultInventory,
    ) {}

    /** @return array<string, mixed> */
    public function get(Request $request): array
    {
        $branches = Branch::query()
            ->with('cashier')
            ->withCount([
                'users as staff_count',
                'users as active_staff_count' => fn ($query) => $query->where('is_active', true),
                'tellers as teller_count',
            ])
            ->orderBy('name')
            ->get();

        $selectedBranch = $this->selectedBranch($request, $branches);

        return [
            'selectedBranchId' => (int) $selectedBranch->id,
            'selectedBranch' => (new BranchResource($selectedBranch))->resolve($request),
            'dailySummary' => $this->reports->summary(
                (string) $request->query('report_date', now()->toDateString()),
                (int) $selectedBranch->id,
            ),
            'branches' => BranchResource::collection($branches)->resolve($request),
            'companies' => CompanyResource::collection(
                Company::query()->orderBy('name')->get()
            )->resolve($request),
            'accounts' => AccountResource::collection(
                Account::query()
                    ->with(['company', 'featureAssignments', 'branch'])
                    ->orderBy('account_name')
                    ->get()
            )->resolve($request),
            'users' => UserResource::collection(
                User::query()->with('branch')->orderBy('full_name')->get()
            )->resolve($request),
            'transactions' => TransactionResource::collection(
                Transaction::query()
                    ->with([
                        'agentCommissionEntries.account',
                        'agentCommissionEntries.company',
                    ])
                    ->latest()
                    ->limit(200)
                    ->get()
            )->resolve($request),
            // System activity audit is paginated on /admin/audit-logs.
            'activityLogs' => [],
            'cashFloats' => CashFloatResource::collection(
                $this->floats->list()
            )->resolve($request),
            'vaultInventory' => $this->vaultInventory->inventory(),
            'exchangeRates' => ExchangeRateResource::collection(
                ExchangeRate::query()
                    ->with('company')
                    ->latest('id')
                    ->limit(50)
                    ->get()
            )->resolve($request),
            'providerFeeTiers' => ProviderFeeTierResource::collection(
                ProviderFeeTier::query()
                    ->with('company')
                    ->orderBy('amount_from')
                    ->get()
            )->resolve($request),
            'agentCommissionTiers' => AgentCommissionTierResource::collection(
                AgentCommissionTier::query()
                    ->with('company')
                    ->orderBy('amount_from')
                    ->get()
            )->resolve($request),
        ];
    }

    private function selectedBranch(Request $request, $branches): Branch
    {
        $requestedId = $request->integer('branch_id');

        if ($requestedId > 0) {
            $selected = $branches->firstWhere('id', $requestedId);

            if ($selected instanceof Branch) {
                return $selected;
            }
        }

        $main = $branches->firstWhere('code', Branch::MAIN_CODE);

        return $main instanceof Branch ? $main : Branch::main();
    }
}
