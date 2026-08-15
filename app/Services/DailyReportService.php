<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AgentCommissionEntry;
use App\Models\DailyReconciliationLog;
use App\Models\DailySummary;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\CashDenominationRepository;
use App\Repositories\CashFloatRepository;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DailyReportService
{
    public function __construct(
        private readonly CashDenominationRepository $vault,
        private readonly CashFloatRepository $floats,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(string $date): array
    {
        return [
            ...$this->transactionSummary($date),
            ...$this->cashSnapshot(),
        ];
    }

    public function close(User $closedBy, string $date, ?string $notes = null): DailyReconciliationLog
    {
        $summary = $this->summary($date);

        return DB::transaction(function () use ($closedBy, $date, $notes, $summary): DailyReconciliationLog {
            DailySummary::query()->updateOrCreate(
                ['summary_date' => $date],
                [
                    'total_cash_in' => $summary['total_cash_in'],
                    'total_cash_out' => $summary['total_cash_out'],
                    'total_transfer' => $summary['total_transfer'],
                    'total_exchange' => $summary['total_exchange'],
                    'total_commission' => $summary['total_commission'],
                    'total_customer_fees' => $summary['total_customer_fees'],
                    'total_profit' => $summary['total_profit'],
                    'transaction_count' => $summary['transaction_count'],
                ],
            );

            return DailyReconciliationLog::query()->create([
                'recon_date' => $date,
                'closed_by' => $closedBy->id,
                'total_cash_in' => $summary['total_cash_in'],
                'total_cash_out' => $summary['total_cash_out'],
                'total_transfer' => $summary['total_transfer'],
                'total_exchange' => $summary['total_exchange'],
                'total_commission' => $summary['total_commission'],
                'total_customer_fees' => $summary['total_customer_fees'],
                'main_vault_total' => $summary['main_vault_total'],
                'employee_floats_total' => $summary['employee_floats_total'],
                'total_cash' => $summary['total_cash'],
                'total_digital' => $summary['total_digital'],
                'grand_total' => $summary['grand_total'],
                'employee_snapshots' => $summary['employee_snapshots'],
                'account_snapshots' => $summary['account_snapshots'],
                'vault_snapshot' => $summary['vault_snapshot'],
                'notes' => $notes,
            ])->load('closer');
        });
    }

    public function reconciliations(
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $perPage = 30,
    ): LengthAwarePaginator {
        return DailyReconciliationLog::query()
            ->with('closer')
            ->when($dateFrom !== null, fn ($query) => $query->whereDate('recon_date', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($query) => $query->whereDate('recon_date', '<=', $dateTo))
            ->orderByDesc('closed_at')
            ->paginate(max(1, min($perPage, 100)));
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionSummary(string $date): array
    {
        $completed = Transaction::query()
            ->whereDate('created_at', $date)
            ->where('status', 'COMPLETED');

        $totalCommission = Money::normalize(AgentCommissionEntry::query()
            ->where('status', 'EARNED')
            ->whereHas('transaction', fn ($query) => $query
                ->whereDate('created_at', $date)
                ->where('status', 'COMPLETED'))
            ->sum('commission_amount'));
        $totalCustomerFees = $this->sumMoney(clone $completed, 'customer_fee');

        return [
            'summary_date' => $date,
            'total_cash_in' => $this->sumType($completed, 'cash_in'),
            'total_cash_out' => $this->sumType($completed, 'cash_out'),
            'total_transfer' => $this->sumType($completed, 'transfer'),
            'total_exchange' => $this->sumType($completed, 'exchange'),
            'total_commission' => $totalCommission,
            'total_customer_fees' => $totalCustomerFees,
            'total_profit' => Money::normalize((float) $totalCommission + (float) $totalCustomerFees),
            'transaction_count' => (clone $completed)->count(),
            'pending_cash_in_count' => Transaction::query()
                ->whereDate('created_at', $date)
                ->where('transaction_type', 'cash_in')
                ->where('status', 'PENDING_CASHIER_CONFIRM')
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cashSnapshot(): array
    {
        $vault = $this->vault->getVaultBalance();
        $mainVaultTotal = $this->denominationTotal($vault);
        [$employeeSnapshots, $employeeFloatTotal] = $this->employeeSnapshots();
        $accountSnapshots = $this->accountSnapshots();
        $totalDigital = Money::normalize(array_sum(array_map(
            fn (array $account): float => (float) $account['balance'],
            $accountSnapshots,
        )));
        $totalCash = Money::normalize($mainVaultTotal + $employeeFloatTotal);

        return [
            'main_vault_total' => Money::normalize($mainVaultTotal),
            'employee_floats_total' => Money::normalize($employeeFloatTotal),
            'total_cash' => $totalCash,
            'total_digital' => $totalDigital,
            'grand_total' => Money::normalize((float) $totalCash + (float) $totalDigital),
            'vault_snapshot' => [
                'denominations' => $this->stringifyKeys($vault),
                'denomination_rows' => $this->denominationRows($vault),
                'total' => Money::normalize($mainVaultTotal),
            ],
            'employee_snapshots' => $employeeSnapshots,
            'account_snapshots' => $accountSnapshots,
        ];
    }

    private function sumType($query, string $type): string
    {
        return $this->sumMoney((clone $query)->where('transaction_type', $type), 'amount');
    }

    private function sumMoney($query, string $column): string
    {
        return Money::normalize($query->sum($column) ?? 0);
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function employeeSnapshots(): array
    {
        $openFloats = $this->floats->list(status: null)
            ->whereIn('status', ['PENDING_RECEIPT', 'ACTIVE', 'PENDING_RECONCILIATION']);

        $snapshots = [];
        $employeeTotal = 0;

        foreach ($openFloats as $float) {
            $denominations = [];
            foreach (Money::supportedDenominations() as $denom) {
                $denominations[(string) $denom] = 0;
            }
            foreach ($float->denominations as $line) {
                $denominations[(string) $line->denomination] = (int) $line->quantity;
            }

            $denomTotal = $this->denominationTotal($denominations);
            $employeeTotal += $denomTotal;

            $snapshots[] = [
                'float_id' => $float->id,
                'employee_id' => $float->employee_id,
                'employee_name' => $float->employee?->full_name,
                'status' => $float->status,
                'current_balance' => Money::normalize($float->current_balance ?? 0),
                'total_amount' => Money::normalize($float->total_amount),
                'denomination_balance' => $denominations,
                'denom_total' => Money::normalize($denomTotal),
            ];
        }

        return [$snapshots, $employeeTotal];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function accountSnapshots(): array
    {
        return Account::query()
            ->with(['company', 'featureAssignments'])
            ->where('is_active', true)
            ->orderBy('account_name')
            ->get()
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'account_name' => $account->account_name,
                'company' => $account->company?->name,
                'balance' => Money::normalize($account->balance),
                'is_fee_account' => (bool) $account->is_fee_account,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int|string, int>  $balance
     */
    private function denominationTotal(array $balance): int
    {
        $total = 0;
        foreach ($balance as $denom => $qty) {
            $total += ((int) $denom) * ((int) $qty);
        }

        return $total;
    }

    /**
     * @param  array<int, int>  $balance
     * @return array<string, int>
     */
    private function stringifyKeys(array $balance): array
    {
        $out = [];
        foreach ($balance as $denom => $qty) {
            $out[(string) $denom] = (int) $qty;
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $balance
     * @return array<int, array{denomination: int, quantity: int, total: int}>
     */
    private function denominationRows(array $balance): array
    {
        $rows = [];
        foreach ($balance as $denom => $qty) {
            $rows[] = [
                'denomination' => (int) $denom,
                'quantity' => (int) $qty,
                'total' => ((int) $denom) * ((int) $qty),
            ];
        }

        return $rows;
    }
}
