<?php

namespace App\Http\Controllers;

use App\Http\Resources\BalanceAdjustmentRequestResource;
use App\Models\BalanceAdjustmentRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BalanceAdjustmentRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $adminIds = User::query()
            ->withoutGlobalScopes()
            ->where('role', 'admin')
            ->select('id');

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
            ->whereIn('requested_by', $adminIds)
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
            'rows' => BalanceAdjustmentRequestResource::collection($rows)
                ->resolve($request),
        ]);
    }

    public function confirm(
        Request $request,
        BalanceAdjustmentRequest $adjustmentRequest,
    ): RedirectResponse {
        $data = $request->validate([
            'pin' => ['required', 'string', 'regex:/^[0-9]{4,8}$/'],
        ]);

        $this->adjustments->confirm(
            $request->user(),
            $adjustmentRequest,
            $data['pin'],
        );

        return back()->with(
            'success',
            'Admin request confirmed and balance updated.',
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

        $adminIds = User::query()
            ->withoutGlobalScopes()
            ->where('role', 'admin')
            ->select('id');

        $pendingAdminRequests = BalanceAdjustmentRequest::query()
            ->where('status', BalanceAdjustmentRequest::STATUS_PENDING)
            ->where('approver_id', $cashierId)
            ->whereIn('requested_by', $adminIds)
            ->count();

        return $pendingTransactions + $pendingAdminRequests;
    }
}
