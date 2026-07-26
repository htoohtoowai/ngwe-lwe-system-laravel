<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\InsufficientFloatDenominationException;
use App\Exceptions\InsufficientFloatException;
use App\Exceptions\InsufficientVaultDenominationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelCashInRequest;
use App\Http\Requests\CashInRequest;
use App\Http\Requests\CashOutRequest;
use App\Http\Requests\ExchangeRequest;
use App\Http\Requests\TransferRequest;
use App\Http\Resources\TransactionResource;
use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Repositories\CashDenominationRepository;
use App\Repositories\TransactionRepository;
use App\Services\PinVerifier;
use App\Services\TransactionService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly TransactionRepository $repository,
        private readonly PinVerifier $pinVerifier,
        private readonly CashDenominationRepository $cashDenominations,
    ) {}

    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $user = $request->user();

        if ($user->role === 'teller') {
            return TransactionResource::collection(
                $this->repository->recentForUser($user, $request->integer('limit') ?: 200)
            );
        }

        if ($user->role !== 'admin') {
            return response()->json(['message' => 'Admin only'], 403);
        }

        return TransactionResource::collection(
            $this->repository->filter(
                $request->string('date_from')->trim()->value() ?: null,
                $request->string('date_to')->trim()->value() ?: null,
                $request->string('type')->trim()->value() ?: null,
                $request->integer('account_id') ?: null,
                $request->integer('limit') ?: 200,
            )
        );
    }

    public function recent(Request $request): AnonymousResourceCollection
    {
        $limit = min($request->integer('limit') ?: 20, 1000);
        $user = $request->user();

        $transactions = $user->role === 'teller'
            ? $this->repository->recentForUser($user, $limit)
            : $this->repository->recent($limit);

        return TransactionResource::collection($transactions);
    }

    public function byDate(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $date = $request->validate(['date' => ['required', 'date']])['date'];
        $user = $request->user();

        if ($user->role === 'teller') {
            return TransactionResource::collection(
                $this->repository->filter($date, $date, null, null, min($request->integer('limit') ?: 200, 1000))
                    ->where('created_by', $user->id),
            );
        }

        return TransactionResource::collection(
            $this->repository->filter($date, $date, null, null, min($request->integer('limit') ?: 200, 1000)),
        );
    }

    public function show(Transaction $transaction): TransactionResource
    {
        return new TransactionResource($transaction);
    }

    public function cashIn(CashInRequest $request): JsonResponse
    {
        return $this->guardCreator($request, function () use ($request): JsonResponse {
            try {
                $txn = $this->transactions->createCashIn($request->validated(), $request->user());
            } catch (InsufficientFloatDenominationException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            } catch (InsufficientFloatException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            } catch (InsufficientBalanceException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            } catch (InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return (new TransactionResource($txn))->response()->setStatusCode(201);
        });
    }

    public function cashOut(CashOutRequest $request): JsonResponse
    {
        return $this->guardCreator($request, function () use ($request): JsonResponse {
            try {
                $txn = $this->transactions->createCashOut($request->validated(), $request->user());
            } catch (InsufficientVaultDenominationException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            } catch (InsufficientFloatDenominationException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            } catch (InsufficientFloatException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            } catch (InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return (new TransactionResource($txn))->response()->setStatusCode(201);
        });
    }

    public function transfer(TransferRequest $request): JsonResponse
    {
        return $this->guardCreator($request, function () use ($request): JsonResponse {
            try {
                $txn = $this->transactions->createTransfer($request->validated(), $request->user());
            } catch (InsufficientFloatDenominationException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            } catch (InsufficientFloatException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            } catch (InsufficientBalanceException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            } catch (InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return (new TransactionResource($txn))->response()->setStatusCode(201);
        });
    }

    public function exchange(ExchangeRequest $request): JsonResponse
    {
        return $this->guardCreator($request, function () use ($request): JsonResponse {
            try {
                $txn = $this->transactions->createExchange($request->validated(), $request->user());
            } catch (InsufficientFloatDenominationException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            } catch (InsufficientFloatException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            } catch (InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return (new TransactionResource($txn))->response()->setStatusCode(201);
        });
    }

    public function confirmCashIn(Request $request, Transaction $transaction): TransactionResource|JsonResponse
    {
        try {
            if ($request->filled('pin')) {
                $this->pinVerifier->verify($request->user(), $request->input('pin'));
            }
            $updated = $this->transactions->confirmPendingCashIn($transaction, $request->user());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return new TransactionResource($updated);
    }

    public function cancelCashIn(CancelCashInRequest $request, Transaction $transaction): TransactionResource|JsonResponse
    {
        try {
            if ($request->filled('pin')) {
                $this->pinVerifier->verify($request->user(), $request->validated()['pin']);
            }
            $updated = $this->transactions->cancelPendingCashIn(
                $transaction,
                $request->user(),
                $request->input('note'),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return new TransactionResource($updated);
    }

    public function approve(Request $request, Transaction $transaction): TransactionResource|JsonResponse
    {
        if ($request->user()?->role !== 'cashier') {
            return response()->json(['message' => 'Only cashiers can approve cash for transactions'], 403);
        }

        if ($transaction->transaction_type === 'cash_in' && $transaction->status === 'PENDING_CASHIER_CONFIRM') {
            return response()->json([
                'message' => 'Pending Cash In transactions must be completed through the cashier PIN flow.',
            ], 409);
        }

        if ($transaction->transaction_type === 'transfer') {
            return response()->json(['message' => 'Transfers are not cash-approval transactions.'], 400);
        }

        if (
            $transaction->creator?->role === 'teller'
            && in_array($transaction->transaction_type, ['cash_out', 'exchange'], true)
        ) {
            return response()->json([
                'message' => 'Teller cash transactions are already deducted from the teller float.',
            ], 409);
        }

        $data = $request->validate([
            'gives' => ['sometimes', 'nullable', 'array'],
            'gives.*' => ['integer', 'min:0'],
            'receives' => ['sometimes', 'nullable', 'array'],
            'receives.*' => ['integer', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        try {
            $gives = $this->normalizeDenominations($data['gives'] ?? []);
            $receives = $this->normalizeDenominations($data['receives'] ?? []);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if ($gives === [] && $receives === []) {
            return response()->json(['message' => 'At least one of gives or receives must have denominations.'], 422);
        }

        try {
            $updated = DB::transaction(function () use ($request, $transaction, $data, $gives, $receives): ?Transaction {
                $approved = $this->repository->approveIfUnapproved($transaction, $request->user()->id);
                if ($approved === null) {
                    return null;
                }

                $note = $data['note'] ?? "Txn #{$transaction->id} ({$transaction->transaction_type})";
                if ($gives !== []) {
                    $this->cashDenominations->recordBulk('vault_out', $gives, $request->user()->id, transactionId: $transaction->id, note: $note);
                }
                if ($receives !== []) {
                    $this->cashDenominations->recordBulk('vault_in', $receives, $request->user()->id, transactionId: $transaction->id, note: $note);
                }

                ActivityLog::query()->create([
                    'user_id' => $request->user()->id,
                    'action' => 'transaction_cash_approved',
                    'entity_type' => 'transaction',
                    'entity_id' => $transaction->id,
                    'details' => [
                        'txn_type' => $transaction->transaction_type,
                        'amount' => Money::normalize($transaction->amount ?? 0),
                        'gives' => $gives,
                        'receives' => $receives,
                    ],
                ]);

                return $approved;
            });
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if ($updated === null) {
            return response()->json(['message' => 'Transaction already approved.'], 409);
        }

        return new TransactionResource($updated);
    }

    public function recordPayment(Request $request, Transaction $transaction): JsonResponse
    {
        if ($request->user()?->role !== 'cashier') {
            return response()->json(['message' => 'Only cashiers can record transaction payments'], 403);
        }

        $data = $request->validate([
            'fee_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'received_denominations' => ['sometimes', 'nullable', 'array'],
            'received_denominations.*' => ['integer', 'min:0'],
            'change_denominations' => ['sometimes', 'nullable', 'array'],
            'change_denominations.*' => ['integer', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        try {
            $received = $this->normalizeDenominations($data['received_denominations'] ?? []);
            $change = $this->normalizeDenominations($data['change_denominations'] ?? []);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        $feeAmount = Money::normalize($data['fee_amount'] ?? $transaction->customer_fee ?? 0);

        if ($received === [] && $change === []) {
            return response()->json(['message' => 'Payment denominations are required.'], 422);
        }

        try {
            DB::transaction(function () use ($request, $transaction, $data, $received, $change, $feeAmount): void {
                $note = $data['note'] ?? "Transaction payment #{$transaction->id}";
                if ($received !== []) {
                    $this->cashDenominations->recordBulk('vault_in', $received, $request->user()->id, transactionId: $transaction->id, note: $note);
                }
                if ($change !== []) {
                    $this->cashDenominations->recordBulk('vault_out', $change, $request->user()->id, transactionId: $transaction->id, note: $note);
                }

                foreach (Money::supportedDenominations() as $denomination) {
                    $paid = (int) ($received[$denomination] ?? 0);
                    $returned = (int) ($change[$denomination] ?? 0);
                    if ($paid === 0 && $returned === 0) {
                        continue;
                    }

                    DB::table('transaction_payment_denominations')->insert([
                        'transaction_id' => $transaction->id,
                        'denomination_id' => $denomination,
                        'quantity_paid' => $paid,
                        'quantity_returned' => $returned,
                        'created_at' => now(),
                    ]);
                }

                ActivityLog::query()->create([
                    'user_id' => $request->user()->id,
                    'action' => 'transaction_payment_recorded',
                    'entity_type' => 'transaction',
                    'entity_id' => $transaction->id,
                    'details' => [
                        'fee_amount' => $feeAmount,
                        'received_denominations' => $received,
                        'change_denominations' => $change,
                    ],
                ]);
            });
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'data' => [
                'transaction_id' => $transaction->id,
                'fee_amount' => $feeAmount,
                'received_denominations' => $received,
                'change_denominations' => $change,
            ],
        ], 201);
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        return response()->json([
            'message' => 'Transaction hard delete is disabled because it can corrupt balances. Use a reversal/void workflow instead.',
        ], 409);
    }

    private function guardCreator(Request $request, \Closure $handler): JsonResponse
    {
        if ($request->user()->role === 'cashier') {
            return response()->json(['message' => 'Cashiers cannot record transactions'], 403);
        }

        return $handler();
    }

    /**
     * @param  array<int|string, int|string>  $raw
     * @return array<int, int>
     */
    private function normalizeDenominations(array $raw): array
    {
        $supported = Money::supportedDenominations();
        $normalized = [];

        foreach ($raw as $denomination => $quantity) {
            $denomination = (int) $denomination;
            $quantity = (int) $quantity;
            if ($quantity <= 0) {
                continue;
            }
            if (! in_array($denomination, $supported, true)) {
                throw new InvalidArgumentException("Unsupported denomination: {$denomination}");
            }
            $normalized[$denomination] = ($normalized[$denomination] ?? 0) + $quantity;
        }

        ksort($normalized);

        return $normalized;
    }
}
