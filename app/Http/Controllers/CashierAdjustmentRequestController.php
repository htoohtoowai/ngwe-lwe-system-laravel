<?php

namespace App\Http\Controllers;

use App\Http\Resources\BalanceAdjustmentRequestResource;
use App\Models\BalanceAdjustmentRequest;
use App\Models\Transaction;
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
        $rows = BalanceAdjustmentRequest::query()
            ->with([
                'branch',
                'account.company',
                'requester',
                'assignedCashier',
                'confirmer',
                'rejecter',
            ])
            ->where('assigned_cashier_id', $request->user()->id)
            ->latest('created_at')
            ->limit(200)
            ->get();

        return Inertia::render('cashier/AdminRequests', [
            'role' => 'cashier',
            'notificationCount' => $this->notificationCount(),
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

        return back()->with('success', 'Admin adjustment request confirmed.');
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

        return back()->with('success', 'Admin adjustment request rejected.');
    }

    private function notificationCount(): int
    {
        $pendingTransactions = Transaction::query()
            ->whereIn('transaction_type', ['cash_in', 'send_money'])
            ->where('status', 'PENDING_CASHIER_CONFIRM')
            ->count();

        $pendingAdminRequests = BalanceAdjustmentRequest::query()
            ->where('status', BalanceAdjustmentRequest::STATUS_PENDING)
            ->count();

        return $pendingTransactions + $pendingAdminRequests;
    }
}
