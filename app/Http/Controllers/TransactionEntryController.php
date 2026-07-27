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
        return Inertia::render('transactions/CashIn', $this->props($request, TransactionFeeCalculator::MODE_CASH_IN, TransactionFeeCalculator::COMMISSION_SEND, 'cash_in'));
    }

    public function cashInHistory(Request $request): Response
    {
        return Inertia::render('transactions/CashIn', $this->props($request, TransactionFeeCalculator::MODE_CASH_IN, TransactionFeeCalculator::COMMISSION_SEND, 'cash_in', 'history'));
    }

    public function cashOut(Request $request): Response
    {
        return Inertia::render('transactions/CashOut', $this->props($request, TransactionFeeCalculator::MODE_CASH_OUT, TransactionFeeCalculator::COMMISSION_RECEIVE, 'cash_out'));
    }

    public function cashOutHistory(Request $request): Response
    {
        return Inertia::render('transactions/CashOut', $this->props($request, TransactionFeeCalculator::MODE_CASH_OUT, TransactionFeeCalculator::COMMISSION_RECEIVE, 'cash_out', 'history'));
    }

    public function transfer(Request $request): Response
    {
        return Inertia::render('transactions/Transfer', $this->props($request, TransactionFeeCalculator::MODE_CASH_IN, TransactionFeeCalculator::COMMISSION_SEND, 'transfer'));
    }

    public function transferHistory(Request $request): Response
    {
        return Inertia::render('transactions/Transfer', $this->props($request, TransactionFeeCalculator::MODE_CASH_IN, TransactionFeeCalculator::COMMISSION_SEND, 'transfer', 'history'));
    }

    public function exchange(Request $request): Response
    {
        return Inertia::render('transactions/Exchange', [
            ...$this->props($request, TransactionFeeCalculator::MODE_CASH_IN, TransactionFeeCalculator::COMMISSION_SEND, 'exchange'),
            'rate' => $this->rate(),
        ]);
    }

    public function exchangeHistory(Request $request): Response
    {
        return Inertia::render('transactions/Exchange', [
            ...$this->props($request, TransactionFeeCalculator::MODE_CASH_IN, TransactionFeeCalculator::COMMISSION_SEND, 'exchange', 'history'),
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
    private function props(Request $request, string $feeMode, string $commissionDirection, string $transactionType, string $view = 'entry'): array
    {
        $user = $request->user();
        $float = $user?->role === 'teller' ? $this->selectedFloat($request) : null;

        return [
            'role' => $user?->role,
            'view' => $view,
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
            'commission' => $this->commission($request, $commissionDirection),
            'receiveCommission' => $transactionType === 'transfer'
                ? $this->commissionForAccount($request, 'receive_account_id', TransactionFeeCalculator::COMMISSION_RECEIVE)
                : '0.00',
            'payoutCommission' => $transactionType === 'transfer'
                ? $this->commissionForAccount($request, 'payout_account_id', TransactionFeeCalculator::COMMISSION_SEND)
                : '0.00',
            'requiresDenominations' => $user?->role === 'teller',
            'completed' => $this->pullCompleted($request),
            'history' => $this->history($request, $transactionType),
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
     * @return array<int, array{id:int,company:string,company_id:int|null,company_category:string|null,company_logo_url:string|null,service:string,service_type_id:int|null,name:string,number:string|null,balance:string}>
     */
    private function accountProps($accounts = null): array
    {
        return ($accounts ?? $this->accounts->active())
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'company' => $account->serviceType?->company?->name ?? 'Account',
                'company_id' => $account->serviceType?->company_id,
                'company_category' => $account->serviceType?->company?->category,
                'company_logo_url' => $this->companyLogoUrl($account->serviceType?->company?->logo_path),
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
     * @return array<int, array{id:int,company_id:int|null,company:string,company_category:string|null,company_logo_url:string|null,name:string,operation:string}>
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
                'company_category' => $serviceType->company?->category,
                'company_logo_url' => $this->companyLogoUrl($serviceType->company?->logo_path),
                'name' => $serviceType->name,
                'operation' => $serviceType->operation,
            ])
            ->values()
            ->all();
    }

    private function companyLogoUrl(?string $path): ?string
    {
        return $path ? asset('storage/'.$path) : null;
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

    private function commission(Request $request, string $direction): string
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

        return $this->fees->commission($account, $amount, $direction);
    }

    private function commissionForAccount(Request $request, string $accountKey, string $direction): string
    {
        $amount = $request->float('amount');
        $accountId = $request->integer($accountKey);

        if ($amount <= 0 || $accountId <= 0) {
            return '0.00';
        }

        $account = $this->accounts->find($accountId);

        return $account !== null && $account->is_active
            ? $this->fees->commission($account, $amount, $direction)
            : '0.00';
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
     * @return array<string, mixed>
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
            'from_label' => $this->transferSourceLabel($transaction) ?? $this->accountLabel($transaction->account_id) ?? 'Counter float',
            'to_label' => $this->transferDestinationLabel($transaction) ?? $this->accountLabel($transaction->to_account_id) ?? $this->accountLabel($transaction->account_id) ?? 'Counter float',
            'system_receive_label' => $this->accountLabel($transaction->to_account_id),
            'system_payout_label' => $this->accountLabel($transaction->account_id),
            'receive_commission_amount' => Money::normalize($transaction->receive_commission_amount ?? 0),
            'payout_commission_amount' => Money::normalize($transaction->payout_commission_amount ?? 0),
            'destination_customer_name' => $transaction->destination_customer_name,
            'customer_name' => $transaction->customer_name,
            'customer_phone' => $transaction->customer_phone,
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

    private function transferSourceLabel(Transaction $transaction): ?string
    {
        if ($transaction->transaction_type !== 'transfer' || $transaction->source_account_type === null) {
            return null;
        }

        $type = strtoupper((string) $transaction->source_account_type);
        $provider = trim((string) $transaction->source_provider);
        $number = trim((string) $transaction->source_account_number);

        return trim($type.' '.($provider !== '' ? $provider : 'Customer account').($number !== '' ? " ({$number})" : ''));
    }

    private function transferDestinationLabel(Transaction $transaction): ?string
    {
        if ($transaction->transaction_type !== 'transfer' || $transaction->destination_provider === null) {
            return null;
        }

        $provider = trim((string) $transaction->destination_provider);
        $name = trim((string) $transaction->destination_customer_name);
        $number = trim((string) $transaction->destination_account_number);
        $customer = trim(($name !== '' ? $name : 'Customer').($number !== '' ? " ({$number})" : ''));

        return trim(($provider !== '' ? "{$provider} - " : '').$customer);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function history(Request $request, string $transactionType): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return Transaction::query()
            ->where('created_by', $user->id)
            ->where('transaction_type', $transactionType)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'transaction_type' => $transaction->transaction_type,
                'amount' => Money::normalize($transaction->amount ?? 0),
                'fee_amount' => Money::normalize($transaction->customer_fee ?? 0),
                'currency' => $transaction->currency,
                'exchange_rate' => $transaction->exchange_rate,
                'status' => $transaction->status,
                'created_at' => $transaction->created_at?->toDateTimeString(),
                'account_label' => $this->transferSourceLabel($transaction) ?? $this->accountLabel($transaction->account_id),
                'to_account_label' => $this->transferDestinationLabel($transaction) ?? $this->accountLabel($transaction->to_account_id),
                'customer_name' => $transaction->customer_name,
                'customer_phone' => $transaction->customer_phone,
                'note' => $transaction->note,
            ])
            ->values()
            ->all();
    }
}
