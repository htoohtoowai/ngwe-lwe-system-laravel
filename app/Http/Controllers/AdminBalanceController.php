<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Http\Resources\BranchResource;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminBalanceController extends Controller
{
    public function __invoke(Request $request, string $type): Response
    {
        abort_unless(in_array($type, ['pay', 'bank'], true), 404);

        $branches = Branch::query()->orderBy('name')->get();
        $selectedBranch = $this->selectedBranch($request, $branches);
        $branchId = (int) $selectedBranch->id;
        $accountType = strtoupper($type);

        $accounts = Account::query()
            ->withoutGlobalScopes()
            ->with(['company', 'branch'])
            ->where('is_active', true)
            ->where('account_type', $accountType)
            ->where(function (Builder $query) use ($branchId): void {
                $query->whereNull('branch_id')
                    ->orWhere('branch_id', $branchId);
            })
            ->orderBy('company_id')
            ->orderBy('account_name')
            ->get();

        return Inertia::render('admin/BalanceAccounts', [
            'role' => 'admin',
            'type' => $type,
            'branches' => BranchResource::collection($branches)->resolve($request),
            'selectedBranchId' => $branchId,
            'accounts' => AccountResource::collection($accounts)->resolve($request),
            'notificationCount' => Transaction::query()
                ->withoutGlobalScopes()
                ->whereIn('transaction_type', ['cash_in', 'send_money'])
                ->where('status', 'PENDING_CASHIER_CONFIRM')
                ->count(),
        ]);
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
