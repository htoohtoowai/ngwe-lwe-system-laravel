<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Http\Resources\BalanceAdjustmentRequestResource;
use App\Models\Account;
use App\Models\BalanceAdjustmentRequest;
use App\Models\Transaction;
use App\Services\BalanceAdjustmentRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CashierAdjustmentRequestController extends Controller
{
    public function __construct(
        private readonly BalanceAdjustmentRequestService $adjustments,
    ) {}

    public function index(Request $request): Response
    {
        $selectedDirection = $request->query(
            'direction',
            BalanceAdjustmentRequest::DIRECTION_DEPOSIT,
        );

        if (! in_array($selectedDirection, [
            BalanceAdjustmentRequest::DIRECTION_DEPOSIT,
            BalanceAdjustmentRequest::DIRECTION_WITHDRAW,
        ], true)) {
            $selectedDirection = BalanceAdjustmentRequest::DIRECTION_DEPOSIT;
        }

        $cashier = $request->user();
        $cashier->loadMissing('branch');
        $branchId = (int) $cashier->branch_id;

        $accounts = Account::query()
            ->withoutGlobalScopes()
            ->with(['company', 'branch'])
            ->where('is_active', true)
            ->whereIn('account_type', ['PAY', 'BANK'])
            ->where(function (Builder $query) use ($branchId): void {
                $query->whereNull('branch_id')
                    ->orWhere('branch_id', $branchId);
            })
            ->orderBy('account_name')
            ->get();

        $rows = BalanceAdjustmentRequest::query()
            ->with([
                'branch',
                'account.company',
                'requester',
                'assignedCashier',
                'approver',
                'confirmer',
                'rejecter',
            ])
            ->where('assigned_cashier_id', $cashier->id)
            ->latest('created_at')
            ->limit(200)
            ->get();

        return Inertia::render('cashier/AdminRequests', [
            'role' => 'cashier',
            'notificationCount' => $this->notificationCount((int) $cashier->id),
            'selectedDirection' => $selectedDirection,
            'branch' => [
                'id' => $branchId,
                'code' => $cashier->branch?->code,
                'name' => $cashier->branch?->name ?? 'Branch',
            ],
            'accounts' => AccountResource::collection($accounts)->resolve($request),
            'rows' => BalanceAdjustmentRequestResource::collection($rows)
                ->resolve($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
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

        $data['branch_id'] = (int) $request->user()->branch_id;
        $this->adjustments->create($request->user(), $data);

        return back()->with(
            'success',
            'Request sent to Admin for approval.',
        );
    }

    public function confirm(
        Request $request,
        BalanceAdjustmentRequest $adjustmentRequest,
    ): RedirectResponse {
        $data = $request->validate([
            'pin' => ['required', 'string', 'regex:/^[0-9]{4,8}$/'],
        ]);

        $confirmed = $this->adjustments->confirm(
            $request->user(),
            $adjustmentRequest,
            $data['pin'],
        );

        return back()->with(
            'success',
            $adjustmentRequest->status === BalanceAdjustmentRequest::STATUS_APPROVED
                ? 'Physical cash handover confirmed and branch vault updated.'
                : 'Admin request confirmed and balance updated.',
        );
    }

    public function reject(
        Request $request,
        BalanceAdjustmentRequest $adjustmentRequest,
    ): RedirectResponse {
        $data = $request->validate([
            'pin' => ['required', 'string', 'regex:/^[0-9]{4,8}$/'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->adjustments->reject(
            $request->user(),
            $adjustmentRequest,
            $data['pin'],
            $data['note'] ?? null,
        );

        return back()->with('success', 'Admin request rejected.');
    }

    private function notificationCount(int $cashierId): int
    {
        $pendingTransactions = Transaction::query()
            ->whereIn('transaction_type', ['cash_in', 'send_money'])
            ->where('status', 'PENDING_CASHIER_CONFIRM')
            ->count();

        $pendingAdminRequests = BalanceAdjustmentRequest::query()
            ->where('status', BalanceAdjustmentRequest::STATUS_PENDING)
            ->where('approver_id', $cashierId)
            ->count();

        $approvedCashHandovers = BalanceAdjustmentRequest::query()
            ->where('status', BalanceAdjustmentRequest::STATUS_APPROVED)
            ->where('target_type', BalanceAdjustmentRequest::TARGET_CASH)
            ->where('requested_by', $cashierId)
            ->where('assigned_cashier_id', $cashierId)
            ->count();

        return $pendingTransactions
            + $pendingAdminRequests
            + $approvedCashHandovers;
    }
}
