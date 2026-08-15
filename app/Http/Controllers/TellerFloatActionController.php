<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActivateCashFloatRequest;
use App\Http\Requests\InitiateFloatReturnRequest;
use App\Http\Requests\RejectCashFloatRequest;
use App\Models\CashFloatAssignment;
use App\Services\CashFloatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class TellerFloatActionController extends Controller
{
    public function __construct(private readonly CashFloatService $service) {}

    public function activate(ActivateCashFloatRequest $request, CashFloatAssignment $float): RedirectResponse
    {
        $this->ensureOwner($request->user()->id, $float);

        try {
            $this->service->activate(
                $request->user(),
                $float,
                $request->validated()['pin'],
                $request->validated()['verified_denominations'],
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->fail($exception);
        }

        return back()->with('success', 'Teller float received.');
    }

    public function reject(RejectCashFloatRequest $request, CashFloatAssignment $float): RedirectResponse
    {
        $this->ensureOwner($request->user()->id, $float);

        try {
            $this->service->rejectReceipt(
                $request->user(),
                $float,
                $request->validated()['pin'],
                $request->validated()['note'] ?? null,
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->fail($exception);
        }

        return back()->with('success', 'Teller float rejected.');
    }

    public function initiateReturn(InitiateFloatReturnRequest $request, CashFloatAssignment $float): RedirectResponse
    {
        $this->ensureOwner($request->user()->id, $float);

        try {
            $this->service->initiateReturn(
                $request->user(),
                $float,
                $request->validated()['return_denominations'],
                $request->validated()['pin'],
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->fail($exception);
        }

        return back()->with('success', 'Teller float return submitted.');
    }

    private function ensureOwner(int $userId, CashFloatAssignment $float): void
    {
        abort_unless($float->employee_id === $userId, 403, "Float #{$float->id} does not belong to this Teller.");
    }

    private function fail(Throwable $exception): never
    {
        throw ValidationException::withMessages(['pin' => $exception->getMessage()]);
    }
}
