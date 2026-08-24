<?php

namespace App\Services;

use App\Enums\AccountFeature;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\InsufficientVaultDenominationException;
use App\Models\Account;
use App\Models\AgentCommissionEntry;
use App\Models\AgentCommissionTier;
use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\AccountRepository;
use App\Repositories\CashDenominationRepository;
use App\Repositories\CashFloatRepository;
use App\Repositories\ExchangeRateRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\VaultTransactionRepository;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Application service that ports the calculation-sensitive create-transaction
 * flows from viewmodels/transaction_viewmodel.py and the operation repositories
 * in repositories/cash_in_repository.py, cash_out_repository.py, and
 * transfer_repository.py.
 *
 * Employee floats are private working vaults. Cash-in temporarily receives
 * notes into the teller float, removes the exact cashier handoff and any
 * customer change, and only posts the handoff to the shared main vault when
 * a cashier confirms. Owners use the shared main vault directly.
 */
class TransactionService
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly AccountRepository $accounts,
        private readonly TransactionFeeCalculator $calculator,
        private readonly AgentCommissionCalculator $agentCommissions,
        private readonly TransferFeeCalculator $transferFees,
        private readonly ExchangeRateRepository $exchangeRates,
        private readonly CashFloatRepository $floats,
        private readonly EmployeeFloatValidator $floatValidator,
        private readonly CashDenominationRepository $cashDenominations,
        private readonly VaultTransactionRepository $vaultTransactions,
        private readonly RealtimeBroadcastService $broadcasts,
    ) {}

    /**
     * @param  array{
     *   account_id: int,
     *   amount: float|string,
     *   customer_name: string,
     *   customer_phone: string,
     *   fee_account_id?: int|null,
     *   screenshot_path?: string|null,
     *   note?: string|null,
     *   amount_received?: float|string|null,
     *   received_denominations?: array<int|string, int|string>|null,
     *   handoff_denominations?: array<int|string, int|string>|null,
     *   change_denominations?: array<int|string, int|string>|null,
     * }  $data
     */
    public function createCashIn(array $data, User $creator): Transaction
    {
        $amount = Money::normalize($data['amount']);
        $this->guardPositive($amount);

        $account = $this->accounts->find((int) $data['account_id']);
        if ($account === null || ! $account->is_active) {
            throw new InvalidArgumentException("Account #{$data['account_id']} not found or inactive.");
        }
        $this->guardAccountFeature($account, AccountFeature::CashIn, 'Cash In');

        $fees = $this->calculator->resolveFees($account, $amount, TransactionFeeCalculator::MODE_CASH_IN);
        $commissionResult = $this->agentCommissions->resolveForMovement($account, $amount, -((float) $amount));
        $commission = $commissionResult['amount'];
        $feePayment = $this->resolveFeePayment($data, $account, $fees['customer_fee']);
        // Cash changes the physical settlement only when cash fee payment is explicitly selected.
        $cashFee = ($feePayment['method'] === 'cash' && array_key_exists('fee_payment_method', $data))
            ? (float) $fees['customer_fee']
            : 0.0;
        $cashSettlementAmount = Money::normalize((float) $amount + $cashFee);
        $fromCompanyId = $account->company_id;

        $amountReceived = isset($data['amount_received']) && $data['amount_received'] !== null
            ? Money::normalize($data['amount_received'])
            : $cashSettlementAmount;

        $rawReceivedDenominations = is_array($data['received_denominations'] ?? null)
            ? $data['received_denominations']
            : [];
        $normalizedReceivedDenominations = $this->floatValidator->normalizeReceivedDenominations($rawReceivedDenominations);
        $rawHandoffDenominations = is_array($data['handoff_denominations'] ?? null)
            ? $data['handoff_denominations']
            : [];
        $normalizedHandoffDenominations = $this->floatValidator->normalizeReceivedDenominations($rawHandoffDenominations);

        if ($normalizedReceivedDenominations === []) {
            throw new InvalidArgumentException('Denomination breakdown is required for Cash In received cash.');
        }

        if ($creator->role === 'teller' && $normalizedHandoffDenominations === []) {
            throw new InvalidArgumentException('Denomination breakdown is required for Teller Cash In cashier handoff.');
        }

        if ($normalizedReceivedDenominations !== []) {
            $receivedTotal = Money::denominationTotal($normalizedReceivedDenominations);
            if ((float) $receivedTotal !== (float) $amountReceived) {
                throw new InvalidArgumentException(
                    "Received denomination total {$receivedTotal} does not match cash received {$amountReceived}."
                );
            }
        }

        if ((float) $amountReceived < (float) $cashSettlementAmount) {
            throw new InvalidArgumentException("amount_received must be greater than or equal to {$cashSettlementAmount}.");
        }

        if ($creator->role === 'teller') {
            $handoffTotal = Money::denominationTotal($normalizedHandoffDenominations);
            if ($handoffTotal !== (int) $cashSettlementAmount) {
                throw new InvalidArgumentException("Handoff denomination total {$handoffTotal} does not match Cash In settlement amount {$cashSettlementAmount}.");
            }
        } elseif ($normalizedHandoffDenominations !== []) {
            throw new InvalidArgumentException('handoff_denominations are only allowed for Teller Cash In.');
        }

        $changeDueFloat = (float) $amountReceived - (float) $cashSettlementAmount;
        $changeDue = Money::normalize($changeDueFloat);
        $rawChangeBreakdown = is_array($data['change_denominations'] ?? null) ? $data['change_denominations'] : [];
        $normalizedChangeDenominations = null;

        if ($changeDueFloat > 0) {
            if ($creator->role !== 'teller') {
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

        $transaction = DB::transaction(function () use ($data, $creator, $account, $amount, $amountReceived, $cashSettlementAmount, $fees, $feePayment, $commission, $commissionResult, $fromCompanyId, $changeDue, $normalizedReceivedDenominations, $normalizedHandoffDenominations, $normalizedChangeDenominations): Transaction {
            try {
                $this->accounts->debitBalance($account->id, $amount);
            } catch (InsufficientBalanceException $exception) {
                throw $exception;
            }

            $cashInBalanceChange = Money::normalize(
                -((float) $amount) + (float) $commission,
            );

            $txn = $this->transactions->create([
                'transaction_type' => 'cash_in',
                'account_id' => $account->id,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'amount' => $amount,
                'customer_fee' => $fees['customer_fee'],
                'additional_fee_amount' => $fees['additional_fee'],
                'balance_change' => $cashInBalanceChange,
                'currency' => 'MMK',
                'fee_account_id' => $feePayment['fee_account_id'],
                'fee_payment_method' => $feePayment['method'],
                'screenshot_path' => $data['screenshot_path'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $creator->id,
                'from_company_id' => $fromCompanyId,
                'status' => 'PENDING_CASHIER_CONFIRM',
                'vault_impact' => 'none',
                'change_given' => $changeDue,
                'received_denominations' => $normalizedReceivedDenominations !== [] ? $normalizedReceivedDenominations : null,
                'handoff_denominations' => $normalizedHandoffDenominations !== [] ? $normalizedHandoffDenominations : null,
                'change_denominations' => $normalizedChangeDenominations,
            ]);

            $this->creditFeeAccount($feePayment['fee_account_id'], $fees['customer_fee']);
            $this->creditAgentCommission($account->id, $commission);
            $this->recordAgentCommission($txn, $account, $amount, $commissionResult);

            if ($creator->role === 'teller') {
                $activeFloat = $this->floats->activeForEmployee($creator->id);
                if ($activeFloat === null) {
                    throw new RuntimeException('Employee float went inactive during cash-in.');
                }

                $this->floats->addDenominations($activeFloat->id, $normalizedReceivedDenominations);
                $this->floats->incrementBalance($creator->id, $amountReceived);

                $this->recordPhysicalCashMovement(
                    entryType: 'vault_in',
                    txnType: 'cash_in_received',
                    denominations: $normalizedReceivedDenominations,
                    performedBy: $creator->id,
                    floatId: $activeFloat->id,
                    transactionId: $txn->id,
                    movementType: 'customer_to_teller',
                    sourceType: 'customer',
                    sourceId: null,
                    destinationType: 'teller_float',
                    destinationId: $activeFloat->id,
                    affectsMainVault: false,
                    note: "Cash In received txn #{$txn->id}",
                );

                if ($normalizedChangeDenominations !== null) {
                    $this->floats->deductDenominations($activeFloat->id, $normalizedChangeDenominations);
                    $this->floats->deductBalance($creator->id, $changeDue);

                    $this->recordPhysicalCashMovement(
                        entryType: 'vault_out',
                        txnType: 'cash_in_change',
                        denominations: $normalizedChangeDenominations,
                        performedBy: $creator->id,
                        floatId: $activeFloat->id,
                        transactionId: $txn->id,
                        movementType: 'teller_to_customer',
                        sourceType: 'teller_float',
                        sourceId: $activeFloat->id,
                        destinationType: 'customer',
                        destinationId: null,
                        affectsMainVault: false,
                        note: "Cash In overpayment change txn #{$txn->id}",
                    );

                    $this->log($creator->id, 'cash_in_overpayment_change_given', $txn->id, [
                        'type' => 'cash_in',
                        'account_id' => $account->id,
                        'amount' => $amount,
                        'change_due' => $changeDue,
                        'change_denominations' => $normalizedChangeDenominations,
                    ]);
                }

                // Validate the handoff after received notes are in the float;
                // the handoff may consist of those newly received notes.
                $normalizedHandoffDenominations = $this->floatValidator->validateFloatOperation(
                    $creator->id,
                    $normalizedHandoffDenominations,
                    $cashSettlementAmount,
                    'cash-in cashier handoff',
                );
                $this->floats->deductDenominations($activeFloat->id, $normalizedHandoffDenominations);
                $this->floats->deductBalance($creator->id, $cashSettlementAmount);

                $this->recordPhysicalCashMovement(
                    entryType: 'vault_out',
                    txnType: 'cash_in_handoff',
                    denominations: $normalizedHandoffDenominations,
                    performedBy: $creator->id,
                    floatId: $activeFloat->id,
                    transactionId: $txn->id,
                    movementType: 'teller_to_cashier',
                    sourceType: 'teller_float',
                    sourceId: $activeFloat->id,
                    destinationType: 'cashier_handoff',
                    destinationId: null,
                    affectsMainVault: false,
                    note: "Cash In cashier handoff txn #{$txn->id}",
                );
            }

            $this->log($creator->id, 'transaction_created', $txn->id, [
                'type' => 'cash_in',
                'account_id' => $account->id,
                'amount' => $amount,
                'balance_delta' => $cashInBalanceChange,
                'status' => 'PENDING_CASHIER_CONFIRM',
                'vault_impact' => 'none',
                'change_due' => $changeDue,
                'received_denominations' => $normalizedReceivedDenominations,
                'handoff_denominations' => $normalizedHandoffDenominations,
            ]);

            return $txn;
        });

        $transaction->load(['agentCommissionEntries.account', 'agentCommissionEntries.company']);
        $this->broadcasts->transactionCreated($transaction);

        return $transaction;
    }

    /**
     * @param  array{
     *   account_id: int,
     *   amount: float|string,
     *   customer_name: string,
     *   customer_phone: string,
     *   destination_provider?: string|null,
     *   destination_account_number?: string|null,
     *   fee_account_id?: int|null,
     *   screenshot_path?: string|null,
     *   note?: string|null,
     *   denominations?: array<int|string, int|string>|null,
     *   fee_denominations?: array<int|string, int|string>|null,
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
        $this->guardAccountFeature($account, AccountFeature::CashOut, 'Cash Out');

        $fees = $this->calculator->resolveFees($account, $amount, TransactionFeeCalculator::MODE_CASH_OUT);
        $commissionResult = $this->agentCommissions->resolveForMovement($account, $amount, (float) $amount);
        $commission = $commissionResult['amount'];
        $feePayment = $this->resolveCashOutFeePayment($data, $account, $fees['customer_fee']);
        $fromCompanyId = $account->company_id;

        $normalizedDenominations = null;
        if ($creator->role === 'teller') {
            $normalizedDenominations = $this->floatValidator->validateFloatOperation(
                $creator->id,
                is_array($data['denominations'] ?? null) ? $data['denominations'] : [],
                $amount,
                'cash-out',
            );
        } elseif ($creator->role === 'admin') {
            $normalizedDenominations = $this->floatValidator->normalizeReceivedDenominations(
                is_array($data['denominations'] ?? null) ? $data['denominations'] : [],
            );
            if ($normalizedDenominations === []) {
                throw new InvalidArgumentException('Denomination breakdown is required for Admin Cash Out from the main vault.');
            }

            $denominationTotal = Money::denominationTotal($normalizedDenominations);
            if ($denominationTotal !== (int) $amount) {
                throw new InvalidArgumentException(
                    "Denomination total {$denominationTotal} does not match cash-out amount {$amount}."
                );
            }

            $available = $this->cashDenominations->getAvailableBalance();
            foreach ($normalizedDenominations as $denomination => $quantity) {
                $availableQuantity = (int) ($available[$denomination] ?? 0);
                if ($quantity > $availableQuantity) {
                    throw new InsufficientVaultDenominationException(
                        (int) $denomination,
                        $availableQuantity,
                        $quantity,
                    );
                }
            }
        }

        $rawFeeDenominations = is_array($data['fee_denominations'] ?? null) ? $data['fee_denominations'] : [];
        $cashFeeDenominationsExpected = $creator->role === 'teller'
            && $feePayment['method'] === 'cash'
            && (float) $fees['customer_fee'] > 0
            && (array_key_exists('fee_payment_method', $data) || $rawFeeDenominations !== []);

        $normalizedFeeDenominations = null;
        if ($cashFeeDenominationsExpected) {
            $normalizedFeeDenominations = $this->floatValidator->normalizeReceivedDenominations(
                $rawFeeDenominations,
            );

            if ($normalizedFeeDenominations === []) {
                throw new InvalidArgumentException('Fee denomination breakdown is required when Cash Out fee is received in cash.');
            }

            $feeDenominationTotal = Money::denominationTotal($normalizedFeeDenominations);
            if ($feeDenominationTotal !== (int) Money::normalize($fees['customer_fee'])) {
                throw new InvalidArgumentException(
                    "Fee denomination total {$feeDenominationTotal} does not match Cash Out fee {$fees['customer_fee']}."
                );
            }
        } elseif ($rawFeeDenominations !== []) {
            throw new InvalidArgumentException('fee_denominations are only allowed for Teller Cash Out fees paid in cash.');
        }

        $transaction = DB::transaction(function () use ($data, $creator, $account, $amount, $fees, $feePayment, $commission, $commissionResult, $fromCompanyId, $normalizedDenominations, $normalizedFeeDenominations): Transaction {
            $balanceCreditAccountId = $feePayment['method'] === 'account'
                ? (int) $feePayment['fee_account_id']
                : $account->id;
            $balanceCreditAmount = Money::normalize(
                (float) $amount + ($feePayment['method'] === 'account' ? (float) $fees['customer_fee'] : 0),
            );
            $cashOutBalanceChange = Money::normalize((float) $balanceCreditAmount + (float) $commission);

            $applied = $this->accounts->incrementBalance($balanceCreditAccountId, $cashOutBalanceChange);
            if ($applied === null) {
                throw new RuntimeException("Unable to credit Cash Out balance for active account #{$balanceCreditAccountId}.");
            }

            $txn = $this->transactions->create([
                'transaction_type' => 'cash_out',
                'account_id' => $account->id,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'amount' => $amount,
                'customer_fee' => $fees['customer_fee'],
                'additional_fee_amount' => $fees['additional_fee'],
                'balance_change' => $cashOutBalanceChange,
                'currency' => 'MMK',
                'fee_account_id' => $feePayment['fee_account_id'],
                'fee_payment_method' => $feePayment['method'],
                'screenshot_path' => $data['screenshot_path'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $creator->id,
                'from_company_id' => $fromCompanyId,
                'status' => 'COMPLETED',
                'received_denominations' => $normalizedFeeDenominations,
            ]);

            $this->recordAgentCommission($txn, $account, $amount, $commissionResult);

            if ($normalizedDenominations !== null) {
                if ($creator->role === 'teller') {
                    $activeFloat = $this->floats->activeForEmployee($creator->id);
                    if ($activeFloat === null) {
                        throw new RuntimeException('Employee float went inactive during cash-out.');
                    }
                    $this->floats->deductDenominations($activeFloat->id, $normalizedDenominations);
                    $this->floats->deductBalance($creator->id, $amount);

                    $this->recordPhysicalCashMovement(
                        entryType: 'vault_out',
                        txnType: 'cash_out',
                        denominations: $normalizedDenominations,
                        performedBy: $creator->id,
                        floatId: $activeFloat->id,
                        transactionId: $txn->id,
                        movementType: 'teller_to_customer',
                        sourceType: 'teller_float',
                        sourceId: $activeFloat->id,
                        destinationType: 'customer',
                        destinationId: null,
                        affectsMainVault: false,
                        note: "Cash Out txn #{$txn->id}",
                    );
                } else {
                    $this->recordPhysicalCashMovement(
                        entryType: 'vault_out',
                        txnType: 'cash_out',
                        denominations: $normalizedDenominations,
                        performedBy: $creator->id,
                        floatId: null,
                        transactionId: $txn->id,
                        movementType: 'cashier_to_customer',
                        sourceType: 'cashier_vault',
                        sourceId: null,
                        destinationType: 'customer',
                        destinationId: null,
                        affectsMainVault: true,
                        note: "Owner Cash Out from main vault txn #{$txn->id}",
                    );
                }
            }

            if ($normalizedFeeDenominations !== null) {
                $activeFloat = $this->floats->activeForEmployee($creator->id);
                if ($activeFloat === null) {
                    throw new RuntimeException('Employee float went inactive while receiving Cash Out fee.');
                }

                $this->floats->addDenominations($activeFloat->id, $normalizedFeeDenominations);
                $this->floats->incrementBalance($creator->id, $fees['customer_fee']);

                $this->recordPhysicalCashMovement(
                    entryType: 'vault_in',
                    txnType: 'cash_out_fee_received',
                    denominations: $normalizedFeeDenominations,
                    performedBy: $creator->id,
                    floatId: $activeFloat->id,
                    transactionId: $txn->id,
                    movementType: 'customer_to_teller',
                    sourceType: 'customer',
                    sourceId: null,
                    destinationType: 'teller_float',
                    destinationId: $activeFloat->id,
                    affectsMainVault: false,
                    note: "Cash Out cash fee received txn #{$txn->id}",
                );
            }

            $this->log($creator->id, 'transaction_created', $txn->id, [
                'type' => 'cash_out',
                'account_id' => $account->id,
                'amount' => $amount,
                'balance_credit_account_id' => $balanceCreditAccountId,
                'balance_delta' => $cashOutBalanceChange,
                'denominations' => $normalizedDenominations,
                'fee_denominations' => $normalizedFeeDenominations,
            ]);

            return $txn;
        });

        $transaction->load(['agentCommissionEntries.account', 'agentCommissionEntries.company']);
        $this->broadcasts->transactionCreated($transaction);

        return $transaction;
    }

    /**
     * @param  array{
     *   from_account_id: int,
     *   to_account_id: int,
     *   source_account_type?: string|null,
     *   source_provider?: string|null,
     *   source_account_number?: string|null,
     *   destination_provider?: string|null,
     *   destination_customer_name?: string|null,
     *   destination_account_number?: string|null,
     *   amount: float|string,
     *   customer_name: string,
     *   customer_phone?: string|null,
     *   fee_account_id?: int|null,
     *   screenshot_path?: string|null,
     *   note?: string|null,
     *   denominations?: array<int|string, int|string>|null,
     *   fee_denominations?: array<int|string, int|string>|null,
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
        $this->guardAccountFeature($fromAccount, AccountFeature::Transfer, 'Transfer');
        $this->guardAccountFeature($toAccount, AccountFeature::Transfer, 'Transfer');

        $customerTransfer = ! empty($data['source_account_type']);
        $sourceCompanyId = $this->companyId($customerTransfer ? $toAccount : $fromAccount);
        $destinationCompanyId = $this->companyId($customerTransfer ? $fromAccount : $toAccount);
        $fees = $this->transferFees->resolve($sourceCompanyId, $destinationCompanyId, $amount);
        $receiveCommissionResult = $this->agentCommissions->resolveForMovement($toAccount, $amount, (float) $amount);
        $payoutCommissionResult = $this->agentCommissions->resolveForMovement($fromAccount, $amount, -((float) $amount));
        $receiveCommission = $receiveCommissionResult['amount'];
        $payoutCommission = $payoutCommissionResult['amount'];
        $feePayment = $customerTransfer
            ? $this->resolveTransferFeePayment($data, $toAccount)
            : $this->resolveFeePayment($data, $fromAccount, $fees['customer_fee']);
        $fromCompanyId = $sourceCompanyId;
        $toCompanyId = $destinationCompanyId;

        $rawDenominations = is_array($data['denominations'] ?? null) ? $data['denominations'] : [];
        if ($rawDenominations !== []) {
            throw new InvalidArgumentException('denominations are not required for account-to-account Transfer.');
        }

        $rawFeeDenominations = is_array($data['fee_denominations'] ?? null) ? $data['fee_denominations'] : [];
        $cashFeeDenominationsExpected = $creator->role === 'teller'
            && $feePayment['method'] === 'cash'
            && (float) $fees['customer_fee'] > 0
            && (array_key_exists('fee_payment_method', $data) || $rawFeeDenominations !== []);

        $normalizedFeeDenominations = null;
        if ($cashFeeDenominationsExpected) {
            $normalizedFeeDenominations = $this->floatValidator->normalizeReceivedDenominations(
                $rawFeeDenominations,
            );

            if ($normalizedFeeDenominations === []) {
                throw new InvalidArgumentException('Fee denomination breakdown is required when Transfer fee is received in cash.');
            }

            $feeDenominationTotal = Money::denominationTotal($normalizedFeeDenominations);
            if ($feeDenominationTotal !== (int) Money::normalize($fees['customer_fee'])) {
                throw new InvalidArgumentException(
                    "Fee denomination total {$feeDenominationTotal} does not match Transfer fee {$fees['customer_fee']}."
                );
            }
        } elseif ($rawFeeDenominations !== []) {
            throw new InvalidArgumentException('fee_denominations are only allowed for Teller Transfer fees paid in cash.');
        }

        $transaction = DB::transaction(function () use ($data, $creator, $customerTransfer, $fromAccount, $toAccount, $amount, $fees, $feePayment, $receiveCommission, $payoutCommission, $receiveCommissionResult, $payoutCommissionResult, $fromCompanyId, $toCompanyId, $normalizedFeeDenominations): Transaction {
            $payoutDebit = Money::normalize(
                (float) $amount
                + (! $customerTransfer && $feePayment['method'] === 'account' ? (float) $fees['customer_fee'] : 0),
            );
            $this->accounts->debitBalance($fromAccount->id, $payoutDebit);
            $this->creditAgentCommission($fromAccount->id, $payoutCommission);

            $receiveCredit = Money::normalize(
                (float) $amount
                + (float) $receiveCommission
                + ($customerTransfer && $feePayment['method'] === 'account' ? (float) $fees['customer_fee'] : 0),
            );
            $credited = $this->accounts->incrementBalance($toAccount->id, $receiveCredit);
            $fromBalanceChange = Money::normalize(-((float) $payoutDebit) + (float) $payoutCommission);

            if ($credited === null) {
                throw new RuntimeException("Unable to credit transfer to active account #{$toAccount->id}.");
            }

            $txn = $this->transactions->create([
                'transaction_type' => 'transfer',
                'account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => $amount,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'] ?? null,
                'source_account_type' => $data['source_account_type'] ?? null,
                'source_provider' => $data['source_provider'] ?? null,
                'source_account_number' => $data['source_account_number'] ?? null,
                'destination_provider' => $data['destination_provider'] ?? null,
                'destination_customer_name' => $data['destination_customer_name'] ?? null,
                'destination_account_number' => $data['destination_account_number'] ?? null,
                'customer_fee' => $fees['customer_fee'],
                'additional_fee_amount' => $fees['additional_fee'],
                'balance_change' => $fromBalanceChange,
                'currency' => 'MMK',
                'fee_account_id' => $feePayment['fee_account_id'],
                'fee_payment_method' => $feePayment['method'],
                'screenshot_path' => $data['screenshot_path'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $creator->id,
                'from_company_id' => $fromCompanyId,
                'to_company_id' => $toCompanyId,
                'status' => 'COMPLETED',
                'received_denominations' => $normalizedFeeDenominations,
            ]);

            $this->recordAgentCommission($txn, $fromAccount, $amount, $payoutCommissionResult);
            $this->recordAgentCommission($txn, $toAccount, $amount, $receiveCommissionResult);

            if ($normalizedFeeDenominations !== null) {
                $activeFloat = $this->floats->activeForEmployee($creator->id);
                if ($activeFloat === null) {
                    throw new RuntimeException('Employee float went inactive while receiving Transfer fee.');
                }
                $this->floats->addDenominations($activeFloat->id, $normalizedFeeDenominations);
                $this->floats->incrementBalance($creator->id, $fees['customer_fee']);

                $this->recordPhysicalCashMovement(
                    entryType: 'vault_in',
                    txnType: 'transfer_fee_received',
                    denominations: $normalizedFeeDenominations,
                    performedBy: $creator->id,
                    floatId: $activeFloat->id,
                    transactionId: $txn->id,
                    movementType: 'customer_to_teller',
                    sourceType: 'customer',
                    sourceId: null,
                    destinationType: 'teller_float',
                    destinationId: $activeFloat->id,
                    affectsMainVault: false,
                    note: "Transfer cash fee received txn #{$txn->id}",
                );
            }

            if (! $customerTransfer) {
                $this->creditFeeAccount($feePayment['fee_account_id'], $fees['customer_fee']);
            }

            $this->log($creator->id, 'transaction_created', $txn->id, [
                'type' => 'transfer',
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'source_account_type' => $data['source_account_type'] ?? null,
                'source_provider' => $data['source_provider'] ?? null,
                'source_account_number' => $data['source_account_number'] ?? null,
                'destination_provider' => $data['destination_provider'] ?? null,
                'destination_customer_name' => $data['destination_customer_name'] ?? null,
                'destination_account_number' => $data['destination_account_number'] ?? null,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'] ?? null,
                'amount' => $amount,
                'from_balance_delta' => $fromBalanceChange,
                'to_balance_delta' => $receiveCredit,
                'receive_commission_amount' => $receiveCommission,
                'payout_commission_amount' => $payoutCommission,
                'fee_denominations' => $normalizedFeeDenominations,
            ]);

            return $txn;
        });

        $transaction->load(['agentCommissionEntries.account', 'agentCommissionEntries.company']);
        $this->broadcasts->transactionCreated($transaction);

        return $transaction;
    }

    /**
     * @param  array{
     *   account_id: int,
     *   amount: float|string,
     *   currency: string,
     *   customer_name: string,
     *   customer_phone: string,
     *   exchange_payment_method?: string,
     *   fee_account_id?: int|null,
     *   screenshot_path?: string|null,
     *   note?: string|null,
     *   denominations?: array<int|string, int|string>|null,
     *   received_denominations?: array<int|string, int|string>|null,
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
        $this->guardAccountFeature($account, AccountFeature::Exchange, 'Exchange');

        $rate = $this->exchangeRates->getLatestForCompany($account->company_id, 'THB', 'MMK');
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
        $mmkSettlementAmount = Money::normalize($currency === 'THB'
            ? (float) $amount * (float) $exchangeRate
            : (float) $amount);
        $exchangePaymentMethod = (string) ($data['exchange_payment_method'] ?? 'cash');
        if (! in_array($exchangePaymentMethod, ['cash', 'account'], true)) {
            throw new InvalidArgumentException('Exchange payment method must be cash or account.');
        }

        $fees = [
            'customer_fee' => Money::normalize(0),
            'additional_fee' => Money::normalize(0),
        ];
        // The selected exchange account is credited by the MMK settlement amount,
        // so agent commission uses the account's IN value regardless of the transaction feature.
        $commissionResult = $this->agentCommissions->resolveForMovement(
            $account,
            $mmkSettlementAmount,
            (float) $mmkSettlementAmount,
        );
        $commission = $commissionResult['amount'];
        $feePayment = $this->resolveFeePayment($data, $account, $fees['customer_fee']);
        $fromCompanyId = $account->company_id;

        $normalizedDenominations = null;
        if ($creator->role === 'teller' && $currency === 'THB' && $exchangePaymentMethod === 'cash') {
            $normalizedDenominations = $this->floatValidator->validateFloatOperation(
                $creator->id,
                is_array($data['denominations'] ?? null) ? $data['denominations'] : [],
                $mmkSettlementAmount,
                'exchange',
            );
        } elseif (is_array($data['denominations'] ?? null) && $data['denominations'] !== []) {
            throw new InvalidArgumentException('denominations are only required when Exchange pays MMK cash from the teller vault.');
        }

        $rawReceivedDenominations = is_array($data['received_denominations'] ?? null) ? $data['received_denominations'] : [];
        $normalizedReceivedDenominations = null;
        if ($creator->role === 'teller' && $currency === 'MMK' && $exchangePaymentMethod === 'cash') {
            $normalizedReceivedDenominations = $this->floatValidator->normalizeReceivedDenominations($rawReceivedDenominations);
            if ($normalizedReceivedDenominations === []) {
                throw new InvalidArgumentException('Received denomination breakdown is required when MMK Exchange payment is cash.');
            }

            $receivedTotal = Money::denominationTotal($normalizedReceivedDenominations);
            if ($receivedTotal !== (int) $mmkSettlementAmount) {
                throw new InvalidArgumentException(
                    "Received denomination total {$receivedTotal} does not match Exchange MMK amount {$mmkSettlementAmount}."
                );
            }
        } elseif ($rawReceivedDenominations !== []) {
            throw new InvalidArgumentException('received_denominations are only allowed for Teller MMK Exchange payments received in cash.');
        }

        $transaction = DB::transaction(function () use ($data, $creator, $account, $amount, $currency, $exchangeRate, $mmkSettlementAmount, $exchangePaymentMethod, $fees, $feePayment, $commission, $commissionResult, $fromCompanyId, $normalizedDenominations, $normalizedReceivedDenominations): Transaction {
            $applied = $this->accounts->incrementBalance($account->id, $mmkSettlementAmount);
            if ($applied === null) {
                throw new RuntimeException("Unable to credit exchange balance for active account #{$account->id}.");
            }
            $this->debitFeeFromSourceIfNeeded($account, $feePayment, $fees['customer_fee']);
            $this->creditAgentCommission($account->id, $commission);
            $exchangeBalanceChange = Money::normalize(
                (float) $mmkSettlementAmount
                - ($feePayment['method'] === 'account' ? (float) $fees['customer_fee'] : 0)
                + (float) $commission,
            );

            $txn = $this->transactions->create([
                'transaction_type' => 'exchange',
                'account_id' => $account->id,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'amount' => $amount,
                'customer_fee' => $fees['customer_fee'],
                'additional_fee_amount' => $fees['additional_fee'],
                'balance_change' => $exchangeBalanceChange,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'fee_account_id' => $feePayment['fee_account_id'],
                'fee_payment_method' => $feePayment['method'],
                'screenshot_path' => $data['screenshot_path'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $creator->id,
                'from_company_id' => $fromCompanyId,
                'status' => 'COMPLETED',
                'received_denominations' => $normalizedReceivedDenominations,
            ]);

            $this->recordAgentCommission($txn, $account, $mmkSettlementAmount, $commissionResult);

            if ($normalizedReceivedDenominations !== null) {
                $activeFloat = $this->floats->activeForEmployee($creator->id);
                if ($activeFloat === null) {
                    throw new RuntimeException('Employee float went inactive while receiving Exchange cash.');
                }
                $this->floats->addDenominations($activeFloat->id, $normalizedReceivedDenominations);
                $this->floats->incrementBalance($creator->id, $mmkSettlementAmount);

                $this->recordPhysicalCashMovement(
                    entryType: 'vault_in',
                    txnType: 'cash_in_received',
                    denominations: $normalizedReceivedDenominations,
                    performedBy: $creator->id,
                    floatId: $activeFloat->id,
                    transactionId: $txn->id,
                    movementType: 'customer_to_teller',
                    sourceType: 'customer',
                    sourceId: null,
                    destinationType: 'teller_float',
                    destinationId: $activeFloat->id,
                    affectsMainVault: false,
                    note: "Exchange MMK cash received txn #{$txn->id}",
                );
            }

            if ($normalizedDenominations !== null) {
                $activeFloat = $this->floats->activeForEmployee($creator->id);
                if ($activeFloat === null) {
                    throw new RuntimeException('Employee float went inactive during exchange.');
                }
                $this->floats->deductDenominations($activeFloat->id, $normalizedDenominations);
                $this->floats->deductBalance($creator->id, $mmkSettlementAmount);

                $this->recordPhysicalCashMovement(
                    entryType: 'vault_out',
                    txnType: 'cash_out',
                    denominations: $normalizedDenominations,
                    performedBy: $creator->id,
                    floatId: $activeFloat->id,
                    transactionId: $txn->id,
                    movementType: 'teller_to_customer',
                    sourceType: 'teller_float',
                    sourceId: $activeFloat->id,
                    destinationType: 'customer',
                    destinationId: null,
                    affectsMainVault: false,
                    note: "Exchange txn #{$txn->id}",
                );
            }

            $this->creditFeeAccount($feePayment['fee_account_id'], $fees['customer_fee']);

            $this->log($creator->id, 'transaction_created', $txn->id, [
                'type' => 'exchange',
                'account_id' => $account->id,
                'amount' => $amount,
                'mmk_settlement_amount' => $mmkSettlementAmount,
                'currency' => $currency,
                'exchange_payment_method' => $exchangePaymentMethod,
                'balance_delta' => $exchangeBalanceChange,
                'commission_amount' => $commission,
                'exchange_rate' => $exchangeRate,
                'denominations' => $normalizedDenominations,
                'received_denominations' => $normalizedReceivedDenominations,
            ]);

            return $txn;
        });

        $transaction->load(['agentCommissionEntries.account', 'agentCommissionEntries.company']);
        $this->broadcasts->transactionCreated($transaction);

        return $transaction;
    }

    public function confirmPendingCashIn(Transaction $transaction, User $cashier): Transaction
    {
        if ($cashier->role !== 'cashier') {
            throw new InvalidArgumentException('Only a cashier can confirm Cash In transactions.');
        }

        if ($transaction->transaction_type !== 'cash_in') {
            throw new InvalidArgumentException('Only Cash In transactions can be confirmed here.');
        }

        $updated = DB::transaction(function () use ($transaction, $cashier): Transaction {
            $updated = $this->transactions->confirmPendingCashIn($transaction, $cashier->id);
            if ($updated === null) {
                throw new RuntimeException("Transaction #{$transaction->id} is not pending cashier confirmation.");
            }

            $creator = User::query()->find($updated->created_by);
            $receivedDenominations = is_array($updated->received_denominations) ? $updated->received_denominations : [];
            $handoffDenominations = is_array($updated->handoff_denominations) ? $updated->handoff_denominations : [];
            $mainVaultDenominations = $creator?->role === 'teller'
                ? $handoffDenominations
                : $receivedDenominations;
            if ($mainVaultDenominations !== []) {
                $this->recordPhysicalCashMovement(
                    entryType: 'vault_in',
                    txnType: 'cash_in',
                    denominations: $mainVaultDenominations,
                    performedBy: $cashier->id,
                    floatId: null,
                    transactionId: $updated->id,
                    movementType: $creator?->role === 'teller'
                        ? 'cashier_accept_teller_handoff'
                        : 'customer_to_cashier',
                    sourceType: $creator?->role === 'teller' ? 'cashier_handoff' : 'customer',
                    sourceId: null,
                    destinationType: 'cashier_vault',
                    destinationId: $cashier->id,
                    affectsMainVault: true,
                    note: "Cash In confirmed txn #{$updated->id}",
                    verifiedBy: $cashier->id,
                );
            }

            $this->log($cashier->id, 'cash_in_confirmed', $updated->id, [
                'type' => 'cash_in',
                'account_id' => $updated->account_id,
                'amount' => Money::normalize($updated->amount),
                'status' => 'COMPLETED',
                'vault_impact' => 'main_vault_increase',
                'main_vault_denominations' => $mainVaultDenominations,
            ]);

            return $updated;
        });

        $this->broadcasts->balanceUpdated();

        return $updated;
    }

    public function cancelPendingCashIn(Transaction $transaction, User $cashier, ?string $note = null): Transaction
    {
        if ($cashier->role !== 'cashier') {
            throw new InvalidArgumentException('Only a cashier can cancel Cash In transactions.');
        }

        if ($transaction->transaction_type !== 'cash_in') {
            throw new InvalidArgumentException('Only Cash In transactions can be cancelled here.');
        }

        if ($transaction->status !== 'PENDING_CASHIER_CONFIRM') {
            throw new RuntimeException("Transaction #{$transaction->id} is not pending cashier confirmation.");
        }

        $updated = DB::transaction(function () use ($transaction, $cashier, $note): Transaction {
            $earnedCommission = $transaction->earnedAgentCommissionTotal();
            $reversal = Money::normalize(
                (float) ($transaction->amount ?? 0) - (float) $earnedCommission,
            );

            $creator = User::query()->find($transaction->created_by);
            $receivedDenominations = is_array($transaction->received_denominations) ? $transaction->received_denominations : [];
            $handoffDenominations = is_array($transaction->handoff_denominations) ? $transaction->handoff_denominations : [];
            $changeDenominations = is_array($transaction->change_denominations) ? $transaction->change_denominations : [];

            if ($creator?->role === 'teller' && $receivedDenominations !== []) {
                $activeFloat = $this->floats->activeForEmployee($creator->id);
                if ($activeFloat === null) {
                    throw new RuntimeException('Employee float is no longer active; Cash In cannot be reversed safely.');
                }

                if ($handoffDenominations !== []) {
                    $this->floats->addDenominations($activeFloat->id, $handoffDenominations);
                    $this->floats->incrementBalance($creator->id, Money::denominationTotal($handoffDenominations));
                    $this->recordPhysicalCashMovement(
                        entryType: 'vault_in',
                        txnType: 'cash_in_handoff',
                        denominations: $handoffDenominations,
                        performedBy: $cashier->id,
                        floatId: $activeFloat->id,
                        transactionId: $transaction->id,
                        movementType: 'cashier_to_teller_reversal',
                        sourceType: 'cashier_handoff',
                        sourceId: null,
                        destinationType: 'teller_float',
                        destinationId: $activeFloat->id,
                        affectsMainVault: false,
                        note: "Cancelled Cash In handoff restored txn #{$transaction->id}",
                        verifiedBy: $cashier->id,
                    );
                }
                if ($changeDenominations !== []) {
                    $this->floats->addDenominations($activeFloat->id, $changeDenominations);
                    $this->floats->incrementBalance($creator->id, Money::denominationTotal($changeDenominations));
                    $this->recordPhysicalCashMovement(
                        entryType: 'vault_in',
                        txnType: 'cash_in_change',
                        denominations: $changeDenominations,
                        performedBy: $cashier->id,
                        floatId: $activeFloat->id,
                        transactionId: $transaction->id,
                        movementType: 'customer_to_teller_reversal',
                        sourceType: 'customer',
                        sourceId: null,
                        destinationType: 'teller_float',
                        destinationId: $activeFloat->id,
                        affectsMainVault: false,
                        note: "Cancelled Cash In change restored txn #{$transaction->id}",
                        verifiedBy: $cashier->id,
                    );
                }
                if ($receivedDenominations !== []) {
                    $this->floats->deductDenominations($activeFloat->id, $receivedDenominations);
                    $this->floats->deductBalance($creator->id, Money::denominationTotal($receivedDenominations));
                    $this->recordPhysicalCashMovement(
                        entryType: 'vault_out',
                        txnType: 'cash_in_received',
                        denominations: $receivedDenominations,
                        performedBy: $cashier->id,
                        floatId: $activeFloat->id,
                        transactionId: $transaction->id,
                        movementType: 'teller_to_customer_reversal',
                        sourceType: 'teller_float',
                        sourceId: $activeFloat->id,
                        destinationType: 'customer',
                        destinationId: null,
                        affectsMainVault: false,
                        note: "Cancelled Cash In customer cash returned txn #{$transaction->id}",
                        verifiedBy: $cashier->id,
                    );
                }
            }

            $refunded = $this->accounts->incrementBalance($transaction->account_id, $reversal);
            if ($refunded === null) {
                throw new RuntimeException("Unable to reverse Cash In balance for active account #{$transaction->account_id}.");
            }

            if ($transaction->fee_payment_method === 'account' && $transaction->fee_account_id !== null) {
                $this->accounts->debitBalance((int) $transaction->fee_account_id, $transaction->customer_fee ?? 0);
            }

            $updated = $this->transactions->cancelPendingCashIn($transaction, $cashier->id, $note);
            if ($updated === null) {
                throw new RuntimeException("Transaction #{$transaction->id} is no longer pending confirmation.");
            }

            AgentCommissionEntry::query()
                ->where('transaction_id', $updated->id)
                ->where('status', 'EARNED')
                ->update([
                    'status' => 'REVERSED',
                    'reversed_at' => now(),
                    'reversed_by' => $cashier->id,
                ]);

            $updated->load(['agentCommissionEntries.account', 'agentCommissionEntries.company']);

            $this->log($cashier->id, 'cash_in_auto_reversed', $updated->id, [
                'type' => 'cash_in',
                'account_id' => $updated->account_id,
                'amount' => Money::normalize($updated->amount),
                'balance_delta' => $reversal,
                'commission_reversed' => $earnedCommission,
                'status' => 'CANCELLED',
                'received_denominations_reversed' => $receivedDenominations,
                'handoff_denominations_restored' => $handoffDenominations,
                'change_denominations_restored' => $changeDenominations,
            ]);

            return $updated;
        });

        $this->broadcasts->balanceUpdated();

        return $updated;
    }

    /**
     * Mirror one physical denomination movement into both cash ledgers under the
     * same batch id. For Teller/customer custody changes, `affectsMainVault` is
     * false so the reconciliation row cannot change Cashier vault stock.
     *
     * @param  array<int, int>  $denominations
     */
    private function recordPhysicalCashMovement(
        string $entryType,
        string $txnType,
        array $denominations,
        int $performedBy,
        ?int $floatId,
        ?int $transactionId,
        string $movementType,
        string $sourceType,
        ?int $sourceId,
        string $destinationType,
        ?int $destinationId,
        bool $affectsMainVault,
        ?string $note = null,
        ?int $verifiedBy = null,
    ): string {
        $batchId = (string) Str::uuid();

        $this->cashDenominations->recordBulk(
            entryType: $entryType,
            denominations: $denominations,
            createdBy: $performedBy,
            floatId: $floatId,
            transactionId: $transactionId,
            note: $note,
            batchId: $batchId,
            movementType: $movementType,
            sourceType: $sourceType,
            sourceId: $sourceId,
            destinationType: $destinationType,
            destinationId: $destinationId,
            affectsMainVault: $affectsMainVault,
        );

        $this->vaultTransactions->recordBulk(
            txnType: $txnType,
            denominations: $denominations,
            performedBy: $performedBy,
            floatId: $floatId,
            verifiedBy: $verifiedBy,
            transactionId: $transactionId,
            note: $note,
            batchId: $batchId,
            movementType: $movementType,
            sourceType: $sourceType,
            sourceId: $sourceId,
            destinationType: $destinationType,
            destinationId: $destinationId,
        );

        return $batchId;
    }

    private function guardAccountFeature(Account $account, AccountFeature $feature, string $operation): void
    {
        if (! $account->supportsFeature($feature)) {
            throw new InvalidArgumentException(
                "Account #{$account->id} is not enabled for {$operation}."
            );
        }
    }

    private function guardPositive(string $normalized): void
    {
        if ((float) $normalized <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }
    }

    private function companyId(Account $account): int
    {
        $companyId = $account->company_id;

        if ($companyId === null) {
            throw new InvalidArgumentException("Account #{$account->id} is not assigned to a company.");
        }

        return (int) $companyId;
    }

    /**
     * @return array{method:string,fee_account_id:int|null}
     */
    private function resolveFeePayment(array $data, Account $sourceAccount, string $fee): array
    {
        $feeAccountInput = $data['fee_account_id'] ?? null;
        $method = (string) ($data['fee_payment_method'] ?? ($feeAccountInput !== null ? 'account' : 'cash'));

        if (! in_array($method, ['cash', 'account'], true)) {
            throw new InvalidArgumentException('Fee payment method must be cash or account.');
        }

        if ($method === 'cash') {
            if ($feeAccountInput !== null) {
                throw new InvalidArgumentException('Fee account must be empty when the fee is paid in cash.');
            }

            return ['method' => 'cash', 'fee_account_id' => null];
        }

        if ($feeAccountInput === null || (int) $feeAccountInput <= 0) {
            throw new InvalidArgumentException('A fee account is required when the fee is paid from an account.');
        }

        $feeAccount = $this->accounts->find((int) $feeAccountInput);
        if ($feeAccount === null || ! $feeAccount->is_active || ! $feeAccount->is_fee_account) {
            throw new InvalidArgumentException('Selected fee account is not active or is not marked as a fee account.');
        }

        if ($feeAccount->id === $sourceAccount->id) {
            throw new InvalidArgumentException('Source account and fee account must be different.');
        }

        return ['method' => 'account', 'fee_account_id' => $feeAccount->id];
    }

    /**
     * Cash Out account-paid fees are credited into the same KPay/account-to-credit
     * selected for the transaction, so tellers do not choose a separate fee account.
     *
     * @return array{method:string,fee_account_id:int|null}
     */
    private function resolveCashOutFeePayment(array $data, Account $creditAccount, string $fee): array
    {
        $feeAccountInput = $data['fee_account_id'] ?? null;
        $method = (string) ($data['fee_payment_method'] ?? 'cash');

        if (! in_array($method, ['cash', 'account'], true)) {
            throw new InvalidArgumentException('Fee payment method must be cash or account.');
        }

        if ($method === 'cash') {
            if ($feeAccountInput !== null) {
                throw new InvalidArgumentException('Fee account must be empty when the fee is paid in cash.');
            }

            return ['method' => 'cash', 'fee_account_id' => null];
        }

        if ($feeAccountInput !== null && (int) $feeAccountInput !== $creditAccount->id) {
            throw new InvalidArgumentException('Cash Out account-paid fees are credited to the selected account only.');
        }

        return ['method' => 'account', 'fee_account_id' => $creditAccount->id];
    }

    /**
     * For a customer transfer, an account-paid fee arrives with the transfer
     * amount in the selected system receive account.
     *
     * @return array{method:string,fee_account_id:int|null}
     */
    private function resolveTransferFeePayment(array $data, Account $receiveAccount): array
    {
        $feeAccountInput = $data['fee_account_id'] ?? null;
        $method = (string) ($data['fee_payment_method'] ?? 'cash');

        if (! in_array($method, ['cash', 'account'], true)) {
            throw new InvalidArgumentException('Fee payment method must be cash or account.');
        }

        if ($method === 'cash') {
            if ($feeAccountInput !== null) {
                throw new InvalidArgumentException('Fee account must be empty when the fee is paid in cash.');
            }

            return ['method' => 'cash', 'fee_account_id' => null];
        }

        if ($feeAccountInput !== null && (int) $feeAccountInput !== $receiveAccount->id) {
            throw new InvalidArgumentException('Transfer account-paid fees are credited to the system receive account.');
        }

        return ['method' => 'account', 'fee_account_id' => $receiveAccount->id];
    }

    /**
     * Account-paid fees are taken from the transaction source and credited to
     * the configured fee account in the same database transaction.
     *
     * @param  array{method:string,fee_account_id:int|null}  $feePayment
     */
    private function debitFeeFromSourceIfNeeded(Account $sourceAccount, array $feePayment, string $fee): void
    {
        if ($feePayment['method'] !== 'account' || (float) $fee <= 0) {
            return;
        }

        $this->accounts->debitBalance($sourceAccount->id, $fee);
    }

    private function creditFeeAccount(?int $feeAccountId, string $fee): void
    {
        if ($feeAccountId === null || (float) $fee <= 0) {
            return;
        }

        $this->accounts->incrementBalance($feeAccountId, $fee);
    }


    /**
     * @param  array{amount:string,tier:?AgentCommissionTier,direction:?\App\Enums\AgentCommissionDirection,configured_value:string}  $result
     */
    private function recordAgentCommission(
        Transaction $transaction,
        Account $account,
        float|string $baseAmount,
        array $result,
    ): void {
        $tier = $result['tier'];
        $direction = $result['direction'];
        $commission = (float) $result['amount'];

        if (! $tier instanceof AgentCommissionTier || $direction === null || $commission <= 0) {
            return;
        }

        AgentCommissionEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'company_id' => $account->company_id,
            'agent_commission_tier_id' => $tier->id,
            'direction' => $direction->value,
            'base_amount' => Money::normalize($baseAmount),
            'calculation_type' => $tier->commission_type->value,
            'configured_value' => $result['configured_value'],
            'commission_amount' => Money::normalize($result['amount']),
            'status' => 'EARNED',
        ]);
    }

    private function creditAgentCommission(int $accountId, string $commission): void
    {
        if ((float) $commission <= 0) {
            return;
        }

        $this->accounts->incrementBalance($accountId, $commission);
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
