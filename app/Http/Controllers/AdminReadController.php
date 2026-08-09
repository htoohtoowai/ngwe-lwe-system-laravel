<?php

namespace App\Http\Controllers;

use App\Http\Resources\DailyReconciliationResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\VaultTransactionResource;
use App\Models\Transaction;
use App\Repositories\TransactionRepository;
use App\Repositories\VaultTransactionRepository;
use App\Services\DailyReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminReadController extends Controller
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly VaultTransactionRepository $vaultTransactions,
        private readonly DailyReportService $reports,
    ) {}

    public function transactions(Request $request, ?string $type = null): Response
    {
        return Inertia::render($this->transactionComponent($type), [
            'role' => $request->user()?->role,
            'announcement' => 'Admin transaction records.',
            'notificationCount' => $this->notificationCount(),
            'rows' => TransactionResource::collection(
                $this->transactions->filter(type: $type, limit: 1000)
            )->resolve($request),
        ]);
    }

    public function vaultLog(Request $request): Response
    {
        return Inertia::render('admin/vault/Log', [
            'role' => $request->user()?->role,
            'notificationCount' => $this->notificationCount(),
            'rows' => VaultTransactionResource::collection(
                $this->vaultTransactions->paginateLog(perPage: 200)
            )->resolve($request),
        ]);
    }

    public function reconciliations(Request $request): Response
    {
        return Inertia::render('admin/reports/Reconciliations', [
            'role' => $request->user()?->role,
            'notificationCount' => $this->notificationCount(),
            'rows' => DailyReconciliationResource::collection(
                $this->reports->reconciliations(perPage: 100)
            )->resolve($request),
        ]);
    }

    private function transactionComponent(?string $type): string
    {
        return match ($type) {
            'cash_in' => 'admin/transactions/CashIn',
            'cash_out' => 'admin/transactions/CashOut',
            'transfer' => 'admin/transactions/Transfer',
            'exchange' => 'admin/transactions/Exchange',
            default => 'admin/transactions/All',
        };
    }

    private function notificationCount(): int
    {
        return Transaction::query()
            ->where('transaction_type', 'cash_in')
            ->where('status', 'PENDING_CASHIER_CONFIRM')
            ->count();
    }
}
