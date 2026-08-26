<?php

namespace App\Http\Controllers;

use App\Enums\AgentCommissionDirection;
use App\Enums\AccountFeature;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\InsufficientFloatDenominationException;
use App\Exceptions\InsufficientFloatException;
use App\Exceptions\InsufficientVaultDenominationException;
use App\Http\Requests\CashInRequest;
use App\Http\Requests\CashOutRequest;
use App\Http\Requests\ExchangeRequest;
use App\Http\Requests\ReceiveMoneyRequest;
use App\Http\Requests\SendMoneyRequest;
use App\Http\Requests\TransferRequest;
use App\Models\Account;
use App\Models\CashFloatAssignment;
use App\Models\Transaction;
use App\Repositories\AccountRepository;
use App\Repositories\CashDenominationRepository;
use App\Repositories\CashFloatRepository;
use App\Repositories\ExchangeRateRepository;
use App\Services\AgentCommissionCalculator;
use App\Services\TransactionFeeCalculator;
use App\Services\TransactionService;
use App\Services\TransferFeeCalculator;
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
        private readonly AgentCommissionCalculator $agentCommissions,
        private readonly TransferFeeCalculator $transferFees,
        private readonly TransactionService $transactions,
    ) {}

    public function cashIn(Request $request): Response
    {
        return Inertia::render('transactions/CashIn', $this->props($request, TransactionFeeCalculator::MODE_CASH_IN, 'cash_in'));
    }

    public function cashInHistory(Request $request): Response
    {
        return Inertia::render('transactions/CashIn', $this->props($request, TransactionFeeCalculator::MODE_CASH_IN, 'cash_in', 'history'));
    }

    public function cashOut(Request $request): Response
    {
        return Inertia::render('transactions/CashOut', $this->props($request, TransactionFeeCalculator::MODE_CASH_OUT, 'cash_out'));
    }

    public function cashOutHistory(Request $request): Response
    {
        return Inertia::render('transactions/CashOut', $this->props($request, TransactionFeeCalculator::MODE_CASH_OUT, 'cash_out', 'history'));
    }

    public function sendMoney(Request $request): Response
    {
        return Inertia::render('transactions/SendMoney', $this->props($request, TransactionFeeCalculator::MODE_CASH_IN, 'send_money'));
    }

    public function sendMoneyHistory(Request $request): Response
    {
        return Inertia::render('transactions/SendMoney', $this->props($request, TransactionFeeCalculator::MODE_CASH_IN, 'send_money', 'history'));
    }

    public function receiveMoney(Request $request): Response
    {
        return Inertia::render('transactions/ReceiveMoney', $this->props($request, TransactionFeeCalculator::MODE_CASH_OUT, 'receive_money'));
    }

    public function receiveMoneyHistory(Request $request): Response
    {
        return Inertia::render('transactions/ReceiveMoney', $this->props($request, TransactionFeeCalculator::MODE_CASH_OUT, 'receive_money', 'history'));
    }

    public function transfer(Request $request): Response
    {
        return Inertia::render('transactions/Transfer', $this->props($request, TransactionFeeCalculator::MODE_CASH_IN, 'transfer'));
    }

    public function transferHistory(Request $request): Response
    {
        return Inertia::render('transactions/Transfer', $this->props($request, TransactionFeeCalculator::MODE_CASH_IN, 'transfer', 'history'));
    }

    public function exchange(Request $request): Response
    {
        return Inertia::render('transactions/Exchange', [
            ...$this->props($request, TransactionFeeCalculator::MODE_CASH_IN, 'exchange'),
            'fee' => Money::normalize(0),
            'rate' => $this->rate($request),
        ]);
    }

    public function exchangeHistory(Request $request): Response
    {
        return Inertia::render('transactions/Exchange', [
            ...$this->props($request, TransactionFeeCalculator::MODE_CASH_IN, 'exchange', 'history'),
            'fee' => Money::normalize(0),
            'rate' => $this->rate($request),
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

    public function sendMoneyStore(SendMoneyRequest $request): RedirectResponse
    {
        return $this->store($request, fn () => $this->transactions->createSendMoney($request->validated(), $request->user()));
    }

    public function receiveMoneyStore(ReceiveMoneyRequest $request): RedirectResponse
    {
        return $this->store($request, fn () => $this->transactions->createReceiveMoney($request->validated(), $request->user()));
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
    private function props(Request $request, string $feeMode, string $transactionType, string $view = 'entry'): array
    {
        $user = $request->user();
        $float = $user?->role === 'teller' ? $this->selectedFloat($request) : null;
        $operation = match ($transactionType) {
            'cash_in' => 'CashIn',
            'cash_out' => 'CashOut',
            'send_money' => 'SendMoney',
            'receive_money' => 'ReceiveMoney',
            'transfer' => 'Transfer',
            'exchange' => 'Exchange',
        };
        $feature = match ($transactionType) {
            'cash_in' => AccountFeature::CashIn,
            'cash_out' => AccountFeature::CashOut,
            'send_money' => AccountFeature::SendMoney,
            'receive_money' => AccountFeature::ReceiveMoney,
            'transfer' => AccountFeature::Transfer,
            'exchange' => AccountFeature::Exchange,
        };

        return [
            'role' => $user?->role,
            'view' => $view,
            'announcement' => 'Use the review step before confirming a transaction.',
            'notificationCount' => $this->pendingCashIns($user?->id),
            'float' => $float ? $this->floatProp($float) : null,
            'notes' => $this->notes(),
            'floatStock' => $float ? $this->floats->getDenominationBalance($float->id) : [],
            'cashInRequiresDenominations' => false,
            'cashInStock' => $float ? $this->floats->getDenominationBalance($float->id) : [],
            'cashOutRequiresDenominations' => in_array($user?->role, ['admin', 'teller'], true),
            'cashOutStock' => $user?->role === 'admin'
                ? $this->cashDenominations->getAvailableBalance()
                : ($float ? $this->floats->getDenominationBalance($float->id) : []),
            'accounts' => $transactionType === 'transfer'
                ? []
                : $this->accountProps(
                    in_array($transactionType, ['send_money', 'receive_money'], true)
                        ? $this->accounts->activePayAgentsForFeature($feature)
                        : $this->accounts->activeForFeature($feature)
                ),
            'sendMoneyAccounts' => $transactionType === 'transfer'
                ? $this->accountProps($this->accounts->activeForFeature(AccountFeature::Transfer))
                : [],
            'receiveMoneyAccounts' => $transactionType === 'transfer'
                ? $this->accountProps($this->accounts->activeForFeature(AccountFeature::Transfer))
                : [],
            'feeAccounts' => $this->accountProps($this->accounts->feeAccounts()),
            'fee' => match ($transactionType) {
                'transfer' => $this->transferFee($request),
                'send_money' => $this->sendMoneyQuote($request)['customer_fee'],
                'receive_money' => $this->receiveMoneyQuote($request)['customer_fee'],
                default => $this->fee($request, $feeMode),
            },
            'sendMoneyQuote' => $transactionType === 'send_money'
                ? $this->sendMoneyQuote($request)
                : null,
            'receiveMoneyQuote' => $transactionType === 'receive_money'
                ? $this->receiveMoneyQuote($request)
                : null,
            'commission' => match ($transactionType) {
                'cash_in' => $this->commission($request, -1),
                'cash_out' => $this->commission($request, 1),
                'send_money' => $this->commission($request, -1),
                'receive_money' => $this->commission($request, 1),
                'exchange' => $this->exchangeCommission($request),
                default => '0.00',
            },
            'receiveCommission' => $transactionType === 'transfer'
                ? $this->commissionForAccount($request, 'receive_account_id', 1)
                : '0.00',
            'payoutCommission' => $transactionType === 'transfer'
                ? $this->commissionForAccount($request, 'payout_account_id', -1)
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

    private function pendingCashIns(?int $createdBy): int
    {
        return (int) Transaction::query()
            ->where('created_by', $createdBy)
            ->whereIn('transaction_type', ['cash_in', 'send_money'])
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
     * @return array<int, array{id:int,company:string,company_id:int|null,company_category:string|null,company_logo_url:string|null,service:string,features:list<string>,name:string,number:string|null,balance:string}>
     */
    private function accountProps($accounts = null): array
    {
        return ($accounts ?? $this->accounts->active())
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'company' => $account->company?->name ?? 'Account',
                'company_id' => $account->company_id,
                'company_category' => $account->company?->category,
                'company_logo_url' => $this->companyLogoUrl($account->company_id, $account->company?->logo_path),
                'service' => 'Account',
                'features' => $account->featureAssignments
                    ->pluck('feature')
                    ->map(fn ($feature) => $feature instanceof \BackedEnum ? $feature->value : $feature)
                    ->values()
                    ->all(),
                'name' => $account->account_name,
                'number' => $account->account_identifier,
                'balance' => Money::normalize($account->balance ?? 0),
                'account_type' => $account->account_type instanceof \BackedEnum ? $account->account_type->value : (string) $account->account_type,
                'is_agent' => (bool) $account->is_agent,
            ])
            ->values()
            ->all();
    }

    private function companyLogoUrl(?int $companyId, ?string $path): ?string
    {
        return $companyId !== null && $path
            ? route('companies.logo', ['company' => $companyId])
            : null;
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

    /** @return array{principal:string,customer_total:string,customer_fee:string,additional_fee:string} */
    private function sendMoneyQuote(Request $request): array
    {
        $amount = $request->float('amount');
        $account = $this->accounts->find($request->integer('account_id'));

        if ($amount <= 0 || $account === null || ! $account->is_active) {
            return [
                'principal' => Money::normalize(max(0, $amount)),
                'customer_total' => Money::normalize(max(0, $amount)),
                'customer_fee' => Money::normalize(0),
                'additional_fee' => Money::normalize(0),
            ];
        }

        $fees = $this->fees->resolveForFeature($account, $amount, AccountFeature::SendMoney);

        return [
            'principal' => Money::normalize($amount),
            'customer_total' => Money::normalize($amount + (float) $fees['customer_fee']),
            ...$fees,
        ];
    }

    /** @return array{amount:string,payout:string,customer_total:string,customer_fee:string,additional_fee:string} */
    private function receiveMoneyQuote(Request $request): array
    {
        $amount = max(0, $request->float('amount'));

        return [
            'amount' => Money::normalize($amount),
            'payout' => Money::normalize($amount),
            'customer_total' => Money::normalize($amount),
            'customer_fee' => Money::normalize(0),
            'additional_fee' => Money::normalize(0),
        ];
    }

    private function transferFee(Request $request): string
    {
        $amount = $request->float('amount');
        $receiveAccount = $this->accounts->find($request->integer('receive_account_id'));
        $payoutAccount = $this->accounts->find($request->integer('payout_account_id'));

        if ($amount <= 0 || $receiveAccount === null || $payoutAccount === null) {
            return '0.00';
        }

        $fromCompanyId = $receiveAccount->company_id;
        $toCompanyId = $payoutAccount->company_id;

        if ($fromCompanyId === null || $toCompanyId === null) {
            return '0.00';
        }

        return $this->transferFees
            ->resolve((int) $fromCompanyId, (int) $toCompanyId, $amount)['customer_fee'];
    }

    private function commission(Request $request, int $movementSign): string
    {
        $amount = $request->float('amount');
        $accountId = $request->integer('account_id');

        if ($amount <= 0 || $accountId <= 0 || ! in_array($movementSign, [-1, 1], true)) {
            return '0.00';
        }

        $account = $this->accounts->find($accountId);

        if ($account === null || ! $account->is_active) {
            return '0.00';
        }

        return $this->agentCommissions->resolveForMovement($account, $amount, $amount * $movementSign)['amount'];
    }

    private function commissionForAccount(Request $request, string $accountKey, int $movementSign): string
    {
        $amount = $request->float('amount');
        $accountId = $request->integer($accountKey);

        if ($amount <= 0 || $accountId <= 0 || ! in_array($movementSign, [-1, 1], true)) {
            return '0.00';
        }

        $account = $this->accounts->find($accountId);

        return $account !== null && $account->is_active
            ? $this->agentCommissions->resolveForMovement($account, $amount, $amount * $movementSign)['amount']
            : '0.00';
    }

    private function exchangeCommission(Request $request): string
    {
        $amount = $request->float('amount');
        $account = $this->accounts->find($request->integer('account_id'));
        $currency = strtoupper($request->string('currency', 'MMK')->toString());

        if ($amount <= 0 || $account === null || ! in_array($currency, ['MMK', 'THB'], true)) {
            return '0.00';
        }

        $rate = $this->rate($request);
        $mmkAmount = $currency === 'THB'
            ? $amount * (float) $rate['buy_rate']
            : $amount;

        return $this->agentCommissions->resolveForMovement(
            $account,
            $mmkAmount,
            $mmkAmount,
        )['amount'];
    }

    /**
     * @return array{buy_rate:string,sell_rate:string}
     */
    private function rate(Request $request): array
    {
        $accountId = $request->integer('account_id');
        $account = $accountId > 0 ? $this->accounts->find($accountId) : null;
        $rate = $account !== null
            ? $this->exchangeRates->getLatestForCompany($account->company_id, 'THB', 'MMK')
            : $this->exchangeRates->getLatest('THB', 'MMK');
        $baseAmount = (float) ($rate?->base_amount ?? 1);

        if ($baseAmount <= 0) {
            $baseAmount = 1.0;
        }

        return [
            'buy_rate' => Money::normalize($rate !== null ? (float) $rate->buy_rate / $baseAmount : 0, 4),
            'sell_rate' => Money::normalize($rate !== null ? (float) $rate->sell_rate / $baseAmount : 0, 4),
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
        $transaction = $transaction->refresh()->load('agentCommissionEntries');

        return [
            'id' => $transaction->id,
            'amount' => Money::normalize($transaction->amount ?? 0),
            'currency' => $transaction->currency,
            'fee_amount' => Money::normalize($transaction->customer_fee ?? 0),
            'customer_total' => Money::normalize($transaction->customer_total ?? $transaction->amount ?? 0),
            'fee_mode' => $transaction->fee_mode,
            'status' => $transaction->status,
            'created_at' => $transaction->created_at?->toDateTimeString() ?? now()->toDateTimeString(),
            'from_label' => $this->transferSourceLabel($transaction) ?? $this->accountLabel($transaction->account_id) ?? 'Counter float',
            'to_label' => $this->transferDestinationLabel($transaction) ?? $this->accountLabel($transaction->to_account_id) ?? $this->accountLabel($transaction->account_id) ?? 'Counter float',
            'system_receive_label' => $this->accountLabel($transaction->to_account_id),
            'system_payout_label' => $this->accountLabel($transaction->account_id),
            'receive_commission_amount' => $transaction->earnedAgentCommissionForDirection(AgentCommissionDirection::In),
            'payout_commission_amount' => $transaction->earnedAgentCommissionForDirection(AgentCommissionDirection::Out),
            'commission_amount' => $transaction->earnedAgentCommissionTotal(),
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

        $company = $account->company?->name;

        return trim(($company ? "{$company} - " : '').$account->account_name);
    }

    private function transferSourceLabel(Transaction $transaction): ?string
    {
        if ($transaction->transaction_type !== 'transfer' || $transaction->source_account_type === null) {
            return null;
        }

        $type = $transaction->source_account_type === 'account'
            ? ''
            : strtoupper((string) $transaction->source_account_type);
        $provider = trim((string) $transaction->source_provider);
        $number = trim((string) $transaction->source_account_number);

        return trim(($type !== '' ? $type.' ' : '').($provider !== '' ? $provider : 'Customer account').($number !== '' ? " ({$number})" : ''));
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
                'customer_total' => Money::normalize($transaction->customer_total ?? $transaction->amount ?? 0),
                'fee_mode' => $transaction->fee_mode,
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
