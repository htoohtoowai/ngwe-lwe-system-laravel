<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\InsufficientFloatDenominationException;
use App\Exceptions\InsufficientFloatException;
use App\Http\Requests\CashInRequest;
use App\Http\Requests\CashOutRequest;
use App\Http\Requests\ExchangeRequest;
use App\Http\Requests\TransferRequest;
use App\Models\Account;
use App\Models\CashFloatAssignment;
use App\Models\Transaction;
use App\Repositories\AccountRepository;
use App\Repositories\CashFloatRepository;
use App\Repositories\ExchangeRateRepository;
use App\Repositories\TransactionRepository;
use App\Services\TransactionFeeCalculator;
use App\Services\TransactionService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class TellerController extends Controller
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly CashFloatRepository $floats,
        private readonly TransactionRepository $transactions,
        private readonly ExchangeRateRepository $exchangeRates,
        private readonly TransactionFeeCalculator $fees,
        private readonly TransactionService $transactionService,
    ) {}

    public function counter(Request $request): Response
    {
        return Inertia::render('teller/Counter', [
            'float' => $this->floatProp($request),
            'denominations' => $this->denominationRows($request),
            'today' => $this->today($request),
            'recent' => $this->recent($request),
        ]);
    }

    public function cashIn(Request $request): Response
    {
        return Inertia::render('teller/CashIn', [
            'float' => $this->floatProp($request),
            'notes' => $this->notes(),
            'floatStock' => $this->onHand($request),
            'accounts' => $this->accounts(),
            'completed' => $this->pullCompleted($request),
        ]);
    }

    public function cashOut(Request $request): Response
    {
        return Inertia::render('teller/CashOut', [
            'float' => $this->floatProp($request),
            'notes' => $this->notes(),
            'floatStock' => $this->onHand($request),
            'accounts' => $this->accounts(),
            'fee' => $this->fee($request, TransactionFeeCalculator::MODE_CASH_OUT),
            'completed' => $this->pullCompleted($request),
        ]);
    }

    public function transfer(Request $request): Response
    {
        return Inertia::render('teller/Transfer', [
            'float' => $this->floatProp($request),
            'notes' => $this->notes(),
            'floatStock' => $this->onHand($request),
            'accounts' => $this->accounts(),
            'fee' => $this->fee($request, TransactionFeeCalculator::MODE_CASH_IN),
            'completed' => $this->pullCompleted($request),
        ]);
    }

    public function exchange(Request $request): Response
    {
        $rate = $this->exchangeRates->getLatest('THB', 'MMK');

        return Inertia::render('teller/Exchange', [
            'float' => $this->floatProp($request),
            'notes' => $this->notes(),
            'floatStock' => $this->onHand($request),
            'accounts' => $this->accounts(),
            'fee' => $this->fee($request, TransactionFeeCalculator::MODE_CASH_IN),
            'rate' => [
                'buy_rate' => $rate?->buy_rate ?? '0.0000',
                'sell_rate' => $rate?->sell_rate ?? '0.0000',
            ],
            'completed' => $this->pullCompleted($request),
        ]);
    }

    public function floatPage(Request $request): Response
    {
        return Inertia::render('teller/Float', [
            'float' => $this->floatProp($request),
            'notes' => $this->notes(),
            'issued' => $this->issued($request),
            'onHand' => $this->onHand($request),
        ]);
    }

    public function cashInStore(CashInRequest $request): RedirectResponse
    {
        return $this->storeTransaction(
            fn (): Transaction => $this->transactionService->createCashIn($request->validated(), $request->user())
        );
    }

    public function cashOutStore(CashOutRequest $request): RedirectResponse
    {
        return $this->storeTransaction(
            fn (): Transaction => $this->transactionService->createCashOut($request->validated(), $request->user())
        );
    }

    public function transferStore(TransferRequest $request): RedirectResponse
    {
        return $this->storeTransaction(
            fn (): Transaction => $this->transactionService->createTransfer($request->validated(), $request->user())
        );
    }

    public function exchangeStore(ExchangeRequest $request): RedirectResponse
    {
        return $this->storeTransaction(
            fn (): Transaction => $this->transactionService->createExchange($request->validated(), $request->user())
        );
    }

    private function storeTransaction(\Closure $create): RedirectResponse
    {
        try {
            /** @var Transaction $transaction */
            $transaction = $create();
        } catch (InsufficientBalanceException|InsufficientFloatDenominationException|InsufficientFloatException|InvalidArgumentException|RuntimeException $exception) {
            return redirect()->back()->withErrors([
                'request' => $exception->getMessage(),
            ])->withInput();
        }

        return redirect()->back()->with('completed', $this->completed($transaction));
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

    private function fee(Request $request, string $mode): string
    {
        $amount = $request->float('amount');
        $accountId = $request->integer('account_id');

        if ($amount <= 0 || $accountId <= 0) {
            return '0.00';
        }

        $account = $this->accounts->find($accountId);

        if ($account === null || ! $account->is_active) {
            return '0.00';
        }

        return $this->fees->resolveFees($account, $amount, $mode)['customer_fee'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pullCompleted(Request $request): ?array
    {
        $completed = $request->session()->pull('completed');

        return is_array($completed) ? $completed : null;
    }

    /**
     * @return array{id:int,type:string,amount:string,fee_amount:string,status:string,created_at:string,account_label:string|null,change_given:string}
     */
    private function completed(Transaction $transaction): array
    {
        $transaction = $transaction->refresh();

        return [
            'id' => $transaction->id,
            'type' => $transaction->transaction_type,
            'amount' => $transaction->amount,
            'fee_amount' => $transaction->customer_fee ?? '0.00',
            'status' => $transaction->status,
            'created_at' => $transaction->created_at?->toDateTimeString() ?? now()->toDateTimeString(),
            'account_label' => $this->accountLabel($transaction),
            'change_given' => $transaction->change_given ?? '0.00',
        ];
    }

    private function accountLabel(Transaction $transaction): ?string
    {
        $from = $transaction->account_id ? $this->accountName((int) $transaction->account_id) : null;
        $to = $transaction->to_account_id ? $this->accountName((int) $transaction->to_account_id) : null;

        if ($from !== null && $to !== null) {
            return "{$from} -> {$to}";
        }

        return $from ?? $to;
    }

    private function accountName(int $accountId): ?string
    {
        $account = $this->accounts->find($accountId);

        if ($account === null) {
            return null;
        }

        $company = $account->serviceType?->company?->name;

        return trim(($company ? "{$company} - " : '').$account->account_name);
    }
}
