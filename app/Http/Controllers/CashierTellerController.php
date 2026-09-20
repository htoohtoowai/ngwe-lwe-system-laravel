<?php

namespace App\Http\Controllers;

use App\Models\CashFloatAssignment;
use App\Models\CashFloatIssue;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashierTellerController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $cashier = $request->user();
        $cashier->loadMissing('branch');
        $branchId = (int) $cashier->branch_id;

        $floats = CashFloatAssignment::query()
            ->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereIn('status', ['PENDING_RECEIPT', 'ACTIVE', 'PENDING_RECONCILIATION'])
            ->latest('created_at')
            ->get();

        $pendingIssueCounts = CashFloatIssue::query()
            ->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('issue_type', 'ADDITIONAL')
            ->where('status', 'PENDING_RECEIPT')
            ->selectRaw('float_id, COUNT(*) as aggregate')
            ->groupBy('float_id')
            ->pluck('aggregate', 'float_id');

        $tellers = User::query()
            ->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('role', 'teller')
            ->where('is_active', true)
            ->orderBy('full_name')
            ->get()
            ->map(function (User $teller) use ($floats, $pendingIssueCounts): array {
                $float = $floats->first(
                    fn (CashFloatAssignment $row): bool => $row->employee_id === $teller->id,
                );

                return [
                    'id' => $teller->id,
                    'name' => $teller->full_name ?: $teller->username,
                    'float_id' => $float?->id,
                    'float_status' => $float?->status,
                    'current_balance' => Money::normalize($float?->current_balance ?? 0),
                    'pending_additional_issues' => $float
                        ? (int) ($pendingIssueCounts[$float->id] ?? 0)
                        : 0,
                    'today_transactions' => Transaction::query()
                        ->withoutGlobalScopes()
                        ->where('branch_id', (int) $teller->branch_id)
                        ->where('created_by', $teller->id)
                        ->whereDate('created_at', today())
                        ->count(),
                ];
            })
            ->values();

        return Inertia::render('cashier/Tellers', [
            'role' => 'cashier',
            'branch' => [
                'id' => $cashier->branch?->id ?? $branchId,
                'code' => $cashier->branch?->code,
                'name' => $cashier->branch?->name ?? 'Branch',
            ],
            'tellers' => $tellers,
        ]);
    }
}
