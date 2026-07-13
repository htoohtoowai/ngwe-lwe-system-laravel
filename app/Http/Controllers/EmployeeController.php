<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\CashFloatAssignment;
use App\Models\Transaction;
use App\Repositories\AccountRepository;
use App\Repositories\CashFloatRepository;
use App\Repositories\ExchangeRateRepository;
use App\Repositories\TransactionRepository;
use App\Services\TransactionFeeCalculator;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly CashFloatRepository $floats,
        private readonly TransactionRepository $transactions,
        private readonly ExchangeRateRepository $exchangeRates,
        private readonly TransactionFeeCalculator $fees,
    ) {}

    public function counter(Request $request): Response
    {
        return Inertia::render('employee/Counter', $this->props($request, [
            'denominations' => $this->denominationRows($request),
            'today' => $this->today($request),
            'recent' => $this->recent($request),
        ]));
    }

    public function cashIn(Request $request): Response
    {
        return Inertia::render('employee/CashIn', $this->props($request, [
            'accounts' => $this->accounts(),
        ]));
    }

    public function cashOut(Request $request): Response
    {
        return Inertia::render('employee/CashOut', $this->props($request, [
            'accounts' => $this->accounts(),
            'fee' => $this->fee($request, TransactionFeeCalculator::MODE_CASH_OUT),
        ]));
    }

    public function transfer(Request $request): Response
    {
        return Inertia::render('employee/Transfer', $this->props($request, [
            'accounts' => $this->accounts(),
            'fee' => $this->fee($request, TransactionFeeCalculator::MODE_CASH_IN, 'from_account_id'),
        ]));
    }

    public function exchange(Request $request): Response
    {
        $rate = $this->exchangeRates->getLatest('THB', 'MMK');

        return Inertia::render('employee/Exchange', $this->props($request, [
            'accounts' => $this->accounts(),
            'fee' => $this->fee($request, TransactionFeeCalculator::MODE_CASH_IN),
            'rate' => [
                'buy_rate' => $rate?->buy_rate ?? '0.0000',
                'sell_rate' => $rate?->sell_rate ?? '0.0000',
            ],
        ]));
    }

    public function floatPage(Request $request): Response
    {
        return Inertia::render('employee/Float', $this->props($request, [
            'issued' => $this->issued($request),
            'onHand' => $this->onHand($request),
        ]));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function props(Request $request, array $extra = []): array
    {
        return [
            'float' => $this->floatProp($request),
            'notes' => $this->notes(),
            'floatStock' => $this->onHand($request),
            ...$extra,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function notes(): array
    {
        $notes = Money::supportedDenominations();
        rsort($notes);

        return $notes;
    }

    /**
     * @return array<int, array{id:int,name:string,company:string,balance:string}>
     */
    private function accounts(): array
    {
        return $this->accounts->active()
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->account_name,
                'company' => $account->serviceType?->company?->name ?? 'Account',
                'balance' => $account->balance,
            ])
            ->values()
            ->all();
    }

    private function selectedFloat(Request $request): ?CashFloatAssignment
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $floats = $this->floats->list($user->id);

        return $floats->firstWhere('status', 'ACTIVE')
            ?? $floats->firstWhere('status', 'PENDING_RECEIPT')
            ?? $floats->firstWhere('status', 'PENDING_RECONCILIATION')
            ?? $floats->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function floatProp(Request $request): ?array
    {
        $float = $this->selectedFloat($request);

        if ($float === null) {
            return null;
        }

        return [
            'id' => $float->id,
            'status' => $float->status,
            'current_balance' => $float->current_balance ?? '0.00',
            'issued_amount' => $float->total_amount,
            'total_amount' => $float->total_amount,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function issued(Request $request): array
    {
        $float = $this->selectedFloat($request);

        if ($float === null) {
            return [];
        }

        return $float->denominations
            ->mapWithKeys(fn ($row): array => [(int) $row->denomination => (int) $row->quantity])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function onHand(Request $request): array
    {
        $float = $this->selectedFloat($request);

        if ($float === null) {
            return [];
        }

        return $this->floats->getDenominationBalance($float->id);
    }

    /**
     * @return array<int, array{note:int,quantity:int}>
     */
    private function denominationRows(Request $request): array
    {
        $stock = $this->onHand($request) ?: $this->issued($request);

        return collect($this->notes())
            ->map(fn (int $note): array => [
                'note' => $note,
                'quantity' => (int) ($stock[$note] ?? 0),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recent(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return $this->transactions->recentForUser($user, 20)
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'type' => $transaction->transaction_type,
                'amount' => $transaction->amount,
                'fee_amount' => $transaction->customer_fee ?? '0.00',
                'status' => $transaction->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{cash_in:string,cash_out:string,transfer:string,exchange:string,count:int}
     */
    private function today(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [
                'cash_in' => '0.00',
                'cash_out' => '0.00',
                'transfer' => '0.00',
                'exchange' => '0.00',
                'count' => 0,
            ];
        }

        $today = now()->toDateString();
        $rows = $this->transactions->recentForUser($user, 1000)
            ->filter(fn (Transaction $transaction): bool => $transaction->created_at?->toDateString() === $today);

        return [
            'cash_in' => Money::normalize($rows->where('transaction_type', 'cash_in')->sum(fn ($row) => (float) $row->amount)),
            'cash_out' => Money::normalize($rows->where('transaction_type', 'cash_out')->sum(fn ($row) => (float) $row->amount)),
            'transfer' => Money::normalize($rows->where('transaction_type', 'transfer')->sum(fn ($row) => (float) $row->amount)),
            'exchange' => Money::normalize($rows->where('transaction_type', 'exchange')->sum(fn ($row) => (float) $row->amount)),
            'count' => $rows->count(),
        ];
    }

    private function fee(Request $request, string $mode, string $accountKey = 'account_id'): string
    {
        $amount = $request->float('amount');
        $accountId = $request->integer($accountKey) ?: $request->integer('account_id');

        if ($amount <= 0 || $accountId <= 0) {
            return '0.00';
        }

        $account = $this->accounts->find($accountId);

        if ($account === null || ! $account->is_active) {
            return '0.00';
        }

        return $this->fees->resolveFees($account, $amount, $mode)['customer_fee'];
    }
}
