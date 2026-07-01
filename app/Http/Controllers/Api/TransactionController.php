<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\InsufficientFloatDenominationException;
use App\Exceptions\InsufficientFloatException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelCashInRequest;
use App\Http\Requests\CashInRequest;
use App\Http\Requests\CashOutRequest;
use App\Http\Requests\ExchangeRequest;
use App\Http\Requests\TransferRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Repositories\TransactionRepository;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use InvalidArgumentException;

class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly TransactionRepository $repository,
    ) {}

    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $user = $request->user();

        if ($user->role === 'employee') {
            return TransactionResource::collection(
                $this->repository->recentForUser($user, $request->integer('limit') ?: 200)
            );
        }

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Owner only'], 403);
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

        $transactions = $user->role === 'employee'
            ? $this->repository->recentForUser($user, $limit)
            : $this->repository->recent($limit);

        return TransactionResource::collection($transactions);
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
}
