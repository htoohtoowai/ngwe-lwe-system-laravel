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
use App\Models\BalanceAdjustmentRequest;
use App\Models\Branch;
use App\Models\CashFloatAssignment;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\ProviderFeeTier;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;

class AdminOperationsDataService
{
    public function __construct(
        private readonly DailyReportService $reports,
        private readonly BranchVaultInventoryService $vaultInventory,
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
        $branchId = (int) $selectedBranch->id;
        $adminId = (int) ($request->user()?->id ?? 0);
        $adjustmentCounts = [
            'deposit' => BalanceAdjustmentRequest::query()
                ->withoutGlobalScopes()
                ->where('branch_id', $branchId)
                ->where('direction', BalanceAdjustmentRequest::DIRECTION_DEPOSIT)
                ->where('status', BalanceAdjustmentRequest::STATUS_PENDING)
                ->where('approver_id', $adminId)
                ->count(),
            'withdraw' => BalanceAdjustmentRequest::query()
                ->withoutGlobalScopes()
                ->where('branch_id', $branchId)
                ->where('direction', BalanceAdjustmentRequest::DIRECTION_WITHDRAW)
                ->where('status', BalanceAdjustmentRequest::STATUS_PENDING)
                ->where('approver_id', $adminId)
                ->count(),
        ];

        if ($request->is('admin') || $request->is('admin/overview')) {
            return [
                'selectedBranchId' => $branchId,
                'selectedBranch' => (new BranchResource($selectedBranch))->resolve($request),
                'branches' => BranchResource::collection($branches)->resolve($request),
                'adjustmentCounts' => $adjustmentCounts,
            ];
        }

        $cashFloats = CashFloatAssignment::query()
            ->withoutGlobalScopes()
            ->with(['denominations', 'employee', 'issuer'])
            ->where('branch_id', $branchId)
            ->orderByDesc('created_at')
            ->get();

        $transactions = Transaction::query()
            ->withoutGlobalScopes()
            ->with([
                'agentCommissionEntries.account',
                'agentCommissionEntries.company',
            ])
            ->where('branch_id', $branchId)
            ->latest()
            ->limit(200)
            ->get();

        return [
            'selectedBranchId' => $branchId,
            'selectedBranch' => (new BranchResource($selectedBranch))->resolve($request),
            'adjustmentCounts' => $adjustmentCounts,
            'dailySummary' => $this->reports->summary(
                (string) $request->query(
                    'report_date',
                    now()->toDateString(),
                ),
                $branchId,
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
                $transactions
            )->resolve($request),
            'activityLogs' => [],
            'cashFloats' => CashFloatResource::collection(
                $cashFloats
            )->resolve($request),
            'vaultInventory' => $this->vaultInventory->inventory($branchId),
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
