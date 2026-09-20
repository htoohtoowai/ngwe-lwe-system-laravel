<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Http\Resources\BalanceAdjustmentRequestResource;
use App\Http\Resources\BranchResource;
use App\Models\Account;
use App\Models\BalanceAdjustmentRequest;
use App\Models\Branch;
use App\Services\BalanceAdjustmentRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminAdjustmentRequestController extends Controller
{
    public function __construct(
        private readonly BalanceAdjustmentRequestService $adjustments,
    ) {}

    public function index(Request $request): Response
    {
        $branches = Branch::query()->orderBy('name')->get();
        $selectedBranch = $this->selectedBranch($request, $branches);
        $branchId = (int) $selectedBranch->id;

        $accounts = Account::query()
            ->withoutGlobalScopes()
            ->with(['company', 'featureAssignments', 'branch'])
            ->where('is_active', true)
            ->whereIn('account_type', ['PAY', 'BANK'])
            ->where(function (Builder $query) use ($branchId): void {
                $query->whereNull('branch_id')
                    ->orWhere('branch_id', $branchId);
            })
            ->orderBy('account_name')
            ->get();

        $selectedAccountId = $request->integer('account_id');
        if (
            $selectedAccountId <= 0
            || ! $accounts->contains(
                fn (Account $account): bool => (int) $account->id === $selectedAccountId,
            )
        ) {
            $selectedAccountId = null;
        }

        $selectedDirection = $request->query('direction', BalanceAdjustmentRequest::DIRECTION_DEPOSIT);
        if (! in_array($selectedDirection, [
            BalanceAdjustmentRequest::DIRECTION_DEPOSIT,
            BalanceAdjustmentRequest::DIRECTION_WITHDRAW,
        ], true)) {
            $selectedDirection = BalanceAdjustmentRequest::DIRECTION_DEPOSIT;
        }

        $rows = BalanceAdjustmentRequest::query()
            ->withoutGlobalScopes()
            ->with([
                'branch',
                'account.company',
                'requester',
                'assignedCashier',
                'approver',
                'confirmer',
                'rejecter',
            ])
            ->where('branch_id', $branchId)
            ->latest('created_at')
            ->limit(200)
            ->get();

        return Inertia::render('admin/Adjustments', [
            'role' => 'admin',
            'branches' => BranchResource::collection($branches)->resolve($request),
            'selectedBranchId' => $branchId,
            'selectedAccountId' => $selectedAccountId,
            'selectedDirection' => $selectedDirection,
            'accounts' => AccountResource::collection($accounts)->resolve($request),
            'rows' => BalanceAdjustmentRequestResource::collection($rows)->resolve($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'target_type' => [
                'required',
                Rule::in([
                    BalanceAdjustmentRequest::TARGET_CASH,
                    BalanceAdjustmentRequest::TARGET_ACCOUNT,
                ]),
            ],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'direction' => [
                'required',
                Rule::in([
                    BalanceAdjustmentRequest::DIRECTION_DEPOSIT,
                    BalanceAdjustmentRequest::DIRECTION_WITHDRAW,
                ]),
            ],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['integer', 'min:0'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $this->adjustments->create($request->user(), $data);

        return back()->with(
            'success',
            'Adjustment request sent to the branch Cashier for PIN confirmation.',
        );
    }


    public function approve(
        Request $request,
        BalanceAdjustmentRequest $adjustmentRequest,
    ): RedirectResponse {
        $approved = $this->adjustments->approveByAdmin(
            $request->user(),
            $adjustmentRequest,
        );

        return back()->with(
            'success',
            $approved->status === BalanceAdjustmentRequest::STATUS_APPROVED
                ? 'Cash request approved. Waiting for the Cashier to confirm the physical handover with PIN.'
                : 'Cashier request approved and balance updated.',
        );
    }

    public function reject(
        Request $request,
        BalanceAdjustmentRequest $adjustmentRequest,
    ): RedirectResponse {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->adjustments->rejectByAdmin(
            $request->user(),
            $adjustmentRequest,
            $data['note'] ?? null,
        );

        return back()->with('success', 'Cashier request rejected.');
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
