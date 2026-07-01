<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\AccountRepository;
use App\Repositories\CashFloatRepository;
use App\Repositories\ExchangeRateRepository;
use App\Repositories\TransactionRepository;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Application service that ports the calculation-sensitive create-transaction
 * flows from viewmodels/transaction_viewmodel.py and the operation repositories
 * in repositories/cash_in_repository.py, cash_out_repository.py, and
 * transfer_repository.py.
 *
 * When the creator's role is `employee`, cash-out additionally validates and
 * deducts the employee's active float via `EmployeeFloatValidator` and
 * `CashFloatRepository`. Owner / cashier-initiated cash-out remains
 * float-agnostic (matches Python behavior).
 */
class TransactionService
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly AccountRepository $accounts,
        private readonly TransactionFeeCalculator $calculator,
        private readonly ExchangeRateRepository $exchangeRates,
        private readonly CashFloatRepository $floats,
        private readonly EmployeeFloatValidator $floatValidator,
    ) {}

    /**
     * @param  array{
     *   account_id: int,
     *   amount: float|string,
     *   customer_name: string,
     *   customer_phone: string,
     *   customer_fee?: float|string,
     *   additional_fee_amount?: float|string,
     *   fee_account_id?: int|null,
     *   screenshot_path?: string|null,
     *   note?: string|null,
     *   amount_received?: float|string|null,
     *   change_denominations?: array<int|string, int|string>|null,
     * }  $data
     */
    public function createCashIn(array $data, User $creator): Transaction
    {
        $amount = Money::normalize($data['amount']);
        $this->guardPositive($amount);

        $amountReceived = isset($data['amount_received']) && $data['amount_received'] !== null
            ? Money::normalize($data['amount_received'])
            : $amount;

        if ((float) $amountReceived < (float) $amount) {
            throw new InvalidArgumentException('amount_received must be greater than or equal to amount.');
        }

        $changeDueFloat = (float) $amountReceived - (float) $amount;
        $changeDue = Money::normalize($changeDueFloat);
        $rawChangeBreakdown = is_array($data['change_denominations'] ?? null) ? $data['change_denominations'] : [];
        $normalizedChangeDenominations = null;

        if ($changeDueFloat > 0) {
            if ($creator->role !== 'employee') {
                throw new InvalidArgumentException(
                    'Employee float is required to give Cash In overpayment change.'
                );
            }
            $normalizedChangeDenominations = $this->floatValidator->validateFloatOperation(
                $creator->id,
                $rawChangeBreakdown,
                $changeDue,
                'cash-in overpayment change',
            );
        } elseif ($rawChangeBreakdown !== []) {
            throw new InvalidArgumentException(
                'change_denominations is only allowed when amount_received exceeds amount.'
            );
        }

        $account = $this->accounts->find((int) $data['account_id']);
        if ($account === null || ! $account->is_active) {
            throw new InvalidArgumentException("Account #{$data['account_id']} not found or inactive.");
        }

        $fees = $this->calculator->resolveFees($account, $amount, TransactionFeeCalculator::MODE_CASH_IN);
        $commission = $this->calculator->commission($account, $amount, TransactionFeeCalculator::COMMISSION_SEND);
        $fromCompanyId = $account->serviceType?->company_id;

        return DB::transaction(function () use ($data, $creator, $account, $amount, $fees, $commission, $fromCompanyId, $changeDue, $normalizedChangeDenominations): Transaction {
            try {
                $this->accounts->debitBalance($account->id, $amount);
            } catch (InsufficientBalanceException $exception) {
                throw $exception;
            }

            $txn = $this->transactions->create([
                'transaction_type' => 'cash_in',
                'account_id' => $account->id,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'amount' => $amount,
                'commission_amount' => $commission,
                'customer_fee' => $fees['customer_fee'],
                'additional_fee_amount' => $fees['additional_fee'],
                'balance_change' => Money::normalize('-'.$amount),
                'currency' => 'MMK',
                'fee_account_id' => $data['fee_account_id'] ?? null,
                'screenshot_path' => $data['screenshot_path'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $creator->id,
                'from_company_id' => $fromCompanyId,
                'status' => 'PENDING_CASHIER_CONFIRM',
                'vault_impact' => 'none',
                'change_given' => $changeDue,
                'change_denominations' => $normalizedChangeDenominations,
            ]);

            if ($normalizedChangeDenominations !== null) {
                $activeFloat = $this->floats->activeForEmployee($creator->id);
                if ($activeFloat === null) {
                    throw new RuntimeException('Employee float went inactive during cash-in overpayment.');
                }
                $this->floats->deductDenominations($activeFloat->id, $normalizedChangeDenominations);
                $this->floats->deductBalance($creator->id, $changeDue);

                $this->log($creator->id, 'cash_in_overpayment_change_given', $txn->id, [
                    'type' => 'cash_in',
                    'account_id' => $account->id,
                    'amount' => $amount,
                    'change_due' => $changeDue,
                    'change_denominations' => $normalizedChangeDenominations,
                ]);
            }

            $this->log($creator->id, 'transaction_created', $txn->id, [
                'type' => 'cash_in',
                'account_id' => $account->id,
                'amount' => $amount,
                'balance_delta' => Money::normalize('-'.$amount),
                'status' => 'PENDING_CASHIER_CONFIRM',
                'vault_impact' => 'none',
                'change_due' => $changeDue,
            ]);

            return $txn;
        });
    }

    /**
     * @param  array{
     *   account_id: int,
     *   amount: float|string,
     *   customer_name: string,
     *   customer_phone: string,
     *   customer_fee?: float|string,
     *   additional_fee_amount?: float|string,
     *   fee_account_id?: int|null,
     *   screenshot_path?: string|null,
     *   note?: string|null,
     *   denominations?: array<int|string, int|string>|null,
     * }  $data
     */
    public function createCashOut(array $data, User $creator): Transaction
    {
        $amount = Money::normalize($data['amount']);
        $this->guardPositive($amount);

        $account = $this->accounts->find((int) $data['account_id']);
        if ($account === null || ! $account->is_active) {
            throw new InvalidArgumentException("Account #{$data['account_id']} not found or inactive.");
        }

        $fees = $this->calculator->resolveFees($account, $amount, TransactionFeeCalculator::MODE_CASH_OUT);
        $commission = $this->calculator->commission($account, $amount, TransactionFeeCalculator::COMMISSION_RECEIVE);
        $fromCompanyId = $account->serviceType?->company_id;

        $normalizedDenominations = null;
        if ($creator->role === 'employee') {
            $normalizedDenominations = $this->floatValidator->validateFloatOperation(
                $creator->id,
                is_array($data['denominations'] ?? null) ? $data['denominations'] : [],
                $amount,
                'cash-out',
            );
        }

        return DB::transaction(function () use ($data, $creator, $account, $amount, $fees, $commission, $fromCompanyId, $normalizedDenominations): Transaction {
            $applied = $this->accounts->incrementBalance($account->id, $amount);
            if ($applied === null) {
                throw new RuntimeException("Unable to credit Cash Out balance for active account #{$account->id}.");
            }

            $txn = $this->transactions->create([
                'transaction_type' => 'cash_out',
                'account_id' => $account->id,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'amount' => $amount,
                'commission_amount' => $commission,
                'customer_fee' => $fees['customer_fee'],
                'additional_fee_amount' => $fees['additional_fee'],
                'balance_change' => $amount,
                'currency' => 'MMK',
                'fee_account_id' => $data['fee_account_id'] ?? null,
                'screenshot_path' => $data['screenshot_path'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $creator->id,
                'from_company_id' => $fromCompanyId,
                'status' => 'COMPLETED',
            ]);

            if ($normalizedDenominations !== null) {
                $activeFloat = $this->floats->activeForEmployee($creator->id);
                if ($activeFloat === null) {
                    throw new RuntimeException('Employee float went inactive during cash-out.');
                }
                $this->floats->deductDenominations($activeFloat->id, $normalizedDenominations);
                $this->floats->deductBalance($creator->id, $amount);
            }

            $this->creditFeeAccount($data['fee_account_id'] ?? null, $fees['customer_fee']);

            $this->log($creator->id, 'transaction_created', $txn->id, [
                'type' => 'cash_out',
                'account_id' => $account->id,
                'amount' => $amount,
                'balance_delta' => $amount,
                'denominations' => $normalizedDenominations,
            ]);

            return $txn;
        });
    }

    /**
     * @param  array{
     *   from_account_id: int,
     *   to_account_id: int,
     *   amount: float|string,
     *   customer_fee?: float|string,
     *   additional_fee_amount?: float|string,
     *   fee_account_id?: int|null,
     *   screenshot_path?: string|null,
     *   note?: string|null,
     *   denominations?: array<int|string, int|string>|null,
     * }  $data
     */
    public function createTransfer(array $data, User $creator): Transaction
    {
        $amount = Money::normalize($data['amount']);
        $this->guardPositive($amount);

        if ((int) $data['from_account_id'] === (int) $data['to_account_id']) {
            throw new InvalidArgumentException('Source and target accounts must be different.');
        }

        $fromAccount = $this->accounts->find((int) $data['from_account_id']);
        $toAccount = $this->accounts->find((int) $data['to_account_id']);
        if ($fromAccount === null || ! $fromAccount->is_active) {
            throw new InvalidArgumentException("Source account #{$data['from_account_id']} not found or inactive.");
        }
        if ($toAccount === null || ! $toAccount->is_active) {
            throw new InvalidArgumentException("Target account #{$data['to_account_id']} not found or inactive.");
        }

        $fees = $this->calculator->resolveFees($fromAccount, $amount, TransactionFeeCalculator::MODE_CASH_IN);
        $commission = $this->calculator->commission($fromAccount, $amount, TransactionFeeCalculator::COMMISSION_SEND);
        $fromCompanyId = $fromAccount->serviceType?->company_id;
        $toCompanyId = $toAccount->serviceType?->company_id;

        $normalizedDenominations = null;
        if ($creator->role === 'employee') {
            $normalizedDenominations = $this->floatValidator->validateFloatOperation(
                $creator->id,
                is_array($data['denominations'] ?? null) ? $data['denominations'] : [],
                $amount,
                'transfer',
            );
        }

        return DB::transaction(function () use ($data, $creator, $fromAccount, $toAccount, $amount, $fees, $commission, $fromCompanyId, $toCompanyId, $normalizedDenominations): Transaction {
            $this->accounts->debitBalance($fromAccount->id, $amount);

            $credited = $this->accounts->incrementBalance($toAccount->id, $amount);
            if ($credited === null) {
                throw new RuntimeException("Unable to credit transfer to active account #{$toAccount->id}.");
            }

            $txn = $this->transactions->create([
                'transaction_type' => 'transfer',
                'account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => $amount,
                'commission_amount' => $commission,
                'customer_fee' => $fees['customer_fee'],
                'additional_fee_amount' => $fees['additional_fee'],
                'balance_change' => Money::normalize('-'.$amount),
                'currency' => 'MMK',
                'fee_account_id' => $data['fee_account_id'] ?? null,
                'screenshot_path' => $data['screenshot_path'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $creator->id,
                'from_company_id' => $fromCompanyId,
                'to_company_id' => $toCompanyId,
                'status' => 'COMPLETED',
            ]);

            if ($normalizedDenominations !== null) {
                $activeFloat = $this->floats->activeForEmployee($creator->id);
                if ($activeFloat === null) {
                    throw new RuntimeException('Employee float went inactive during transfer.');
                }
                $this->floats->deductDenominations($activeFloat->id, $normalizedDenominations);
                $this->floats->deductBalance($creator->id, $amount);
            }

            $this->creditFeeAccount($data['fee_account_id'] ?? null, $fees['customer_fee']);

            $this->log($creator->id, 'transaction_created', $txn->id, [
                'type' => 'transfer',
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => $amount,
                'from_balance_delta' => Money::normalize('-'.$amount),
                'to_balance_delta' => $amount,
                'denominations' => $normalizedDenominations,
            ]);

            return $txn;
        });
    }

    /**
     * @param  array{
     *   account_id: int,
     *   amount: float|string,
     *   currency: string,
     *   customer_fee?: float|string,
     *   additional_fee_amount?: float|string,
     *   fee_account_id?: int|null,
     *   screenshot_path?: string|null,
     *   note?: string|null,
     *   denominations?: array<int|string, int|string>|null,
     * }  $data
     */
    public function createExchange(array $data, User $creator): Transaction
    {
        $amount = Money::normalize($data['amount']);
        $this->guardPositive($amount);

        $currency = strtoupper((string) $data['currency']);
        if (! in_array($currency, ['MMK', 'THB'], true)) {
            throw new InvalidArgumentException('Currency must be MMK or THB.');
        }

        $account = $this->accounts->find((int) $data['account_id']);
        if ($account === null || ! $account->is_active) {
            throw new InvalidArgumentException("Account #{$data['account_id']} not found or inactive.");
        }

        $rate = $this->exchangeRates->getLatest('THB', 'MMK');
        if ($rate === null) {
            throw new InvalidArgumentException('Exchange rate not set for THB/MMK.');
        }

        $baseAmount = (float) $rate->base_amount;
        if ($baseAmount <= 0) {
            $baseAmount = 1.0;
        }

        $rawRate = $currency === 'MMK'
            ? ((float) $rate->sell_rate) / $baseAmount
            : ((float) $rate->buy_rate) / $baseAmount;
        $exchangeRate = Money::normalize($rawRate, 4);

        $fees = $this->calculator->resolveFees($account, $amount, TransactionFeeCalculator::MODE_CASH_IN);
        $commission = $this->calculator->commission($account, $amount, TransactionFeeCalculator::COMMISSION_SEND);
        $fromCompanyId = $account->serviceType?->company_id;

        $normalizedDenominations = null;
        if ($creator->role === 'employee') {
            $normalizedDenominations = $this->floatValidator->validateFloatOperation(
                $creator->id,
                is_array($data['denominations'] ?? null) ? $data['denominations'] : [],
                $amount,
                'exchange',
            );
        }

        return DB::transaction(function () use ($data, $creator, $account, $amount, $currency, $exchangeRate, $fees, $commission, $fromCompanyId, $normalizedDenominations): Transaction {
            $applied = $this->accounts->incrementBalance($account->id, $amount);
            if ($applied === null) {
                throw new RuntimeException("Unable to credit exchange balance for active account #{$account->id}.");
            }

            $txn = $this->transactions->create([
                'transaction_type' => 'exchange',
                'account_id' => $account->id,
                'amount' => $amount,
                'commission_amount' => $commission,
                'customer_fee' => $fees['customer_fee'],
                'additional_fee_amount' => $fees['additional_fee'],
                'balance_change' => $amount,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'fee_account_id' => $data['fee_account_id'] ?? null,
                'screenshot_path' => $data['screenshot_path'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $creator->id,
                'from_company_id' => $fromCompanyId,
                'status' => 'COMPLETED',
            ]);

            if ($normalizedDenominations !== null) {
                $activeFloat = $this->floats->activeForEmployee($creator->id);
                if ($activeFloat === null) {
                    throw new RuntimeException('Employee float went inactive during exchange.');
                }
                $this->floats->deductDenominations($activeFloat->id, $normalizedDenominations);
                $this->floats->deductBalance($creator->id, $amount);
            }

            $this->creditFeeAccount($data['fee_account_id'] ?? null, $fees['customer_fee']);

            $this->log($creator->id, 'transaction_created', $txn->id, [
                'type' => 'exchange',
                'account_id' => $account->id,
                'amount' => $amount,
                'currency' => $currency,
                'balance_delta' => $amount,
                'exchange_rate' => $exchangeRate,
                'denominations' => $normalizedDenominations,
            ]);

            return $txn;
        });
    }

    public function confirmPendingCashIn(Transaction $transaction, User $cashier): Transaction
    {
        if ($transaction->transaction_type !== 'cash_in') {
            throw new InvalidArgumentException('Only Cash In transactions can be confirmed here.');
        }

        return DB::transaction(function () use ($transaction, $cashier): Transaction {
            $updated = $this->transactions->confirmPendingCashIn($transaction, $cashier->id);
            if ($updated === null) {
                throw new RuntimeException("Transaction #{$transaction->id} is not pending cashier confirmation.");
            }

            $this->log($cashier->id, 'cash_in_confirmed', $updated->id, [
                'type' => 'cash_in',
                'account_id' => $updated->account_id,
                'amount' => Money::normalize($updated->amount),
                'status' => 'COMPLETED',
                'vault_impact' => 'main_vault_increase',
            ]);

            return $updated;
        });
    }

    public function cancelPendingCashIn(Transaction $transaction, User $cashier, ?string $note = null): Transaction
    {
        if ($transaction->transaction_type !== 'cash_in') {
            throw new InvalidArgumentException('Only Cash In transactions can be cancelled here.');
        }

        return DB::transaction(function () use ($transaction, $cashier, $note): Transaction {
            $reversal = Money::normalize($transaction->amount ?? 0);
            $refunded = $this->accounts->incrementBalance($transaction->account_id, $reversal);
            if ($refunded === null) {
                throw new RuntimeException("Unable to reverse Cash In balance for active account #{$transaction->account_id}.");
            }

            $updated = $this->transactions->cancelPendingCashIn($transaction, $cashier->id, $note);
            if ($updated === null) {
                throw new RuntimeException("Transaction #{$transaction->id} is no longer pending confirmation.");
            }

            $this->log($cashier->id, 'cash_in_auto_reversed', $updated->id, [
                'type' => 'cash_in',
                'account_id' => $updated->account_id,
                'amount' => Money::normalize($updated->amount),
                'balance_delta' => $reversal,
                'status' => 'CANCELLED',
            ]);

            return $updated;
        });
    }

    private function guardPositive(string $normalized): void
    {
        if ((float) $normalized <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }
    }

    private function creditFeeAccount(?int $feeAccountId, string $fee): void
    {
        if ($feeAccountId === null || (float) $fee <= 0) {
            return;
        }

        $this->accounts->incrementBalance($feeAccountId, $fee);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function log(int $userId, string $action, int $entityId, array $details): void
    {
        ActivityLog::query()->create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => 'transaction',
            'entity_id' => $entityId,
            'details' => $details,
        ]);
    }
}
