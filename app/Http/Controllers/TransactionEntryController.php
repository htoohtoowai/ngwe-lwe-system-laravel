<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\InsufficientFloatDenominationException;
use App\Exceptions\InsufficientFloatException;
use App\Exceptions\InsufficientVaultDenominationException;
use App\Http\Requests\CashInRequest;
use App\Http\Requests\CashOutRequest;
use App\Http\Requests\ExchangeRequest;
use App\Http\Requests\TransferRequest;
use App\Models\Account;
use App\Models\CashFloatAssignment;
use App\Models\ServiceType;
use App\Models\Transaction;
use App\Repositories\AccountRepository;
use App\Repositories\CashDenominationRepository;
use App\Repositories\CashFloatRepository;
use App\Repositories\ExchangeRateRepository;
use App\Services\TransactionFeeCalculator;
use App\Services\TransactionService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class TransactionEntryController extends Controller
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly CashFloatRepository $floats,
        private readonly CashDenominationRepository $cashDenominations,
        private readonly ExchangeRateRepository $exchangeRates,
        private readonly TransactionFeeCalculator $fees,
        private readonly TransactionService $transactions,
    ) {}

    public function cashIn(Request $request): Response
    {
        return Inertia::render('transactions/CashIn', $this->props($request, TransactionFeeCalculator::MODE_CASH_IN));
    }

    public function cashOut(Request $request): Response
    {
        return Inertia::render('transactions/CashOut', $this->props($request, TransactionFeeCalculator::MODE_CASH_OUT));
    }

    public function transfer(Request $request): Response
    {
        return Inertia::render('transactions/Transfer', $this->props($request, TransactionFeeCalculator::MODE_CASH_IN));
    }

    public function exchange(Request $request): Response
    {
        return Inertia::render('transactions/Exchange', [
            ...$this->props($request, TransactionFeeCalculator::MODE_CASH_IN),
            'rate' => $this->rate(),
        ]);
    }

    public function cashInStore(CashInRequest $request): RedirectResponse
    {
        return $this->store($request, function () use ($request): Transaction {
            $data = $request->validated();

            if ($request->hasFile('screenshot')) {
                unset($data['screenshot']);
                $data['screenshot_path'] = $request->file('screenshot')?->store('transaction-screenshots', 'public');
            }

            return $this->transactions->createCashIn($data, $request->user());
        });
    }

    public function cashOutStore(CashOutRequest $request): RedirectResponse
    {
        return $this->store($request, fn () => $this->transactions->createCashOut($request->validated(), $request->user()));
    }

    public function transferStore(TransferRequest $request): RedirectResponse
    {
        return $this->store($request, fn () => $this->transactions->createTransfer($request->validated(), $request->user()));
    }

    public function exchangeStore(ExchangeRequest $request): RedirectResponse
    {
        return $this->store($request, fn () => $this->transactions->createExchange($request->validated(), $request->user()));
    }

    /**
     * @return array<string, mixed>
     */
    private function props(Request $request, string $feeMode): array
    {
        $user = $request->user();
        $float = $user?->role === 'teller' ? $this->selectedFloat($request) : null;

        return [
            'role' => $user?->role,
            'announcement' => 'Use the review step before confirming a transaction.',
            'notificationCount' => $this->pendingCashIns(),
            'float' => $float ? $this->floatProp($float) : null,
            'notes' => $this->notes(),
            'floatStock' => $float ? $this->floats->getDenominationBalance($float->id) : [],
            'cashInRequiresDenominations' => $user?->role === 'teller',
            'cashInStock' => $float ? $this->floats->getDenominationBalance($float->id) : [],
            'cashOutRequiresDenominations' => in_array($user?->role, ['admin', 'teller'], true),
            'cashOutStock' => $user?->role === 'admin'
                ? $this->cashDenominations->getAvailableBalance()
                : ($float ? $this->floats->getDenominationBalance($float->id) : []),
            'accounts' => $this->accountProps(),
            'serviceTypes' => $this->serviceTypeProps(),
            'feeAccounts' => $this->accountProps($this->accounts->feeAccounts()),
            'fee' => $this->fee($request, $feeMode),
            'requiresDenominations' => $user?->role === 'teller',
            'completed' => $this->pullCompleted($request),
        ];
    }

    private function store(Request $request, \Closure $create): RedirectResponse
    {
        if ($request->user()?->role === 'teller' && $this->floats->activeForEmployee($request->user()->id) === null) {
            return redirect()->back()->withErrors([
                'request' => 'An active float is required before entering transactions.',
            ])->withInput();
        }

        try {
            /** @var Transaction $transaction */
            $transaction = $create();
        } catch (InsufficientBalanceException|InsufficientFloatDenominationException|InsufficientFloatException|InsufficientVaultDenominationException|InvalidArgumentException|RuntimeException $exception) {
            return redirect()->back()->withErrors([
                'request' => $exception->getMessage(),
            ])->withInput();
        }

        return redirect()->back()->with('completed', $this->completed($transaction));
    }

    private function pendingCashIns(): int
    {
        return (int) Transaction::query()
            ->where('transaction_type', 'cash_in')
            ->where('status', 'PENDING_CASHIER_CONFIRM')
            ->count();
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
     * @return array<int, array{id:int,company:string,company_id:int|null,service:string,service_type_id:int|null,name:string,number:string|null,balance:string}>
     */
    private function accountProps($accounts = null): array
    {
        return ($accounts ?? $this->accounts->active())
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'company' => $account->serviceType?->company?->name ?? 'Account',
                'company_id' => $account->serviceType?->company_id,
                'service' => $account->serviceType?->name ?? 'Account',
                'service_type_id' => $account->service_type_id,
                'name' => $account->account_name,
                'number' => $account->phone_number,
                'balance' => Money::normalize($account->balance ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id:int,company_id:int|null,company:string,name:string,operation:string}>
     */
    private function serviceTypeProps(): array
    {
        return ServiceType::query()
            ->with('company')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceType $serviceType): array => [
                'id' => $serviceType->id,
                'company_id' => $serviceType->company_id,
                'company' => $serviceType->company?->name ?? 'Account',
                'name' => $serviceType->name,
                'operation' => $serviceType->operation,
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
     * @return array<string, mixed>
     */
    private function floatProp(CashFloatAssignment $float): array
    {
        return [
            'id' => $float->id,
            'status' => $float->status,
            'current_balance' => Money::normalize($float->current_balance ?? 0),
            'issued_amount' => Money::normalize($float->total_amount ?? 0),
            'total_amount' => Money::normalize($float->total_amount ?? 0),
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
     * @return array{buy_rate:string,sell_rate:string}
     */
    private function rate(): array
    {
        $rate = $this->exchangeRates->getLatest('THB', 'MMK');

        return [
            'buy_rate' => $rate?->buy_rate ?? '0.0000',
            'sell_rate' => $rate?->sell_rate ?? '0.0000',
        ];
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
     * @return array{id:int,amount:string,fee_amount:string,status:string,created_at:string,from_label:string,to_label:string}
     */
    private function completed(Transaction $transaction): array
    {
        $transaction = $transaction->refresh();

        return [
            'id' => $transaction->id,
            'amount' => Money::normalize($transaction->amount ?? 0),
            'fee_amount' => Money::normalize($transaction->customer_fee ?? 0),
            'status' => $transaction->status,
            'created_at' => $transaction->created_at?->toDateTimeString() ?? now()->toDateTimeString(),
            'from_label' => $this->accountLabel($transaction->account_id) ?? 'Counter float',
            'to_label' => $this->accountLabel($transaction->to_account_id) ?? $this->accountLabel($transaction->account_id) ?? 'Counter float',
        ];
    }

    private function accountLabel(?int $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }

        $account = $this->accounts->find($accountId);

        if ($account === null) {
            return null;
        }

        $company = $account->serviceType?->company?->name;

        return trim(($company ? "{$company} - " : '').$account->account_name);
    }
}
