<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActivateCashFloatRequest;
use App\Http\Requests\ConfirmFloatReturnRequest;
use App\Http\Requests\InitiateFloatReturnRequest;
use App\Http\Requests\IssueCashFloatRequest;
use App\Http\Resources\CashFloatResource;
use App\Models\CashFloatAssignment;
use App\Repositories\CashFloatRepository;
use App\Services\CashFloatService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use InvalidArgumentException;
use RuntimeException;

class CashFloatController extends Controller
{
    public function __construct(
        private readonly CashFloatService $service,
        private readonly CashFloatRepository $repository,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $employeeId = $request->integer('employee_id') ?: null;
        $status = $request->string('status')->trim()->value() ?: null;

        if ($user->role === 'teller') {
            $employeeId = $user->id;
        }

        return CashFloatResource::collection(
            $this->repository->list($employeeId, $status)
        );
    }

    public function myPending(Request $request): CashFloatResource|JsonResponse
    {
        $float = $this->repository->pendingForEmployee($request->user()->id);

        if ($float === null) {
            return response()->json(['data' => null]);
        }

        return new CashFloatResource($float->load(['denominations', 'employee', 'issuer']));
    }

    public function show(Request $request, CashFloatAssignment $float): CashFloatResource|JsonResponse
    {
        $user = $request->user();

        if ($user->role === 'teller' && $float->employee_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return new CashFloatResource($float->load(['denominations', 'employee', 'issuer']));
    }

    public function denominations(Request $request, CashFloatAssignment $float): JsonResponse
    {
        if ($request->user()->role === 'teller' && $float->employee_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $balance = $this->repository->getDenominationBalance($float->id);

        return response()->json([
            'data' => [
                'float_id' => $float->id,
                'denominations' => collect(Money::supportedDenominations())
                    ->mapWithKeys(fn (int $denomination): array => [(string) $denomination => (int) ($balance[$denomination] ?? 0)])
                    ->all(),
                'total' => Money::denominationTotal($balance),
            ],
        ]);
    }

    public function store(IssueCashFloatRequest $request): JsonResponse
    {
        try {
            $float = $this->service->issue(
                $request->user(),
                (int) $request->validated()['employee_id'],
                $request->validated()['denominations'],
                $request->input('note'),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return (new CashFloatResource($float))->response()->setStatusCode(201);
    }

    public function activate(ActivateCashFloatRequest $request, CashFloatAssignment $float): CashFloatResource|JsonResponse
    {
        if ($float->employee_id !== $request->user()->id) {
            return response()->json(['message' => "Float #{$float->id} does not belong to this Teller."], 403);
        }

        try {
            $activated = $this->service->activate(
                $request->user(),
                $float,
                $request->validated()['pin'],
                $request->validated()['verified_denominations'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return new CashFloatResource($activated);
    }

    public function initiateReturn(InitiateFloatReturnRequest $request, CashFloatAssignment $float): CashFloatResource|JsonResponse
    {
        try {
            $updated = $this->service->initiateReturn(
                $request->user(),
                $float,
                $request->validated()['return_denominations'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return new CashFloatResource($updated);
    }

    public function confirmReturn(ConfirmFloatReturnRequest $request, CashFloatAssignment $float): CashFloatResource|JsonResponse
    {
        try {
            $closed = $this->service->confirmReturn(
                $request->user(),
                $float,
                $request->validated()['closing_total'],
                $request->validated()['pin'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return new CashFloatResource($closed);
    }
}
