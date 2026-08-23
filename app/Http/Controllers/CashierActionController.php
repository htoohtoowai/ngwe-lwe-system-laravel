<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelCashInRequest;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ConfirmFloatReturnRequest;
use App\Http\Requests\IssueCashFloatRequest;
use App\Http\Requests\SetPinRequest;
use App\Models\CashFloatAssignment;
use App\Models\Transaction;
use App\Models\TransactionNotificationRead;
use App\Repositories\UserRepository;
use App\Services\CashFloatService;
use App\Services\PinVerifier;
use App\Services\TransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class CashierActionController extends Controller
{
    public function __construct(
        private readonly CashFloatService $floats,
        private readonly TransactionService $transactions,
        private readonly PinVerifier $pinVerifier,
        private readonly UserRepository $users,
    ) {}

    public function issueFloat(IssueCashFloatRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->floats->issue(
                $request->user(),
                (int) $data['employee_id'],
                $data['denominations'],
                $data['note'] ?? null,
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->fail($exception);
        }

        return back()->with('success', 'Teller float issued.');
    }

    public function confirmFloatReturn(
        ConfirmFloatReturnRequest $request,
        CashFloatAssignment $float,
    ): RedirectResponse {
        $data = $request->validated();

        try {
            $this->floats->confirmReturn(
                $request->user(),
                $float,
                $data['closing_total'],
                $data['pin'],
                $data['return_denominations'] ?? null,
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->fail($exception, 'pin');
        }

        return back()->with('success', 'Teller float return confirmed.');
    }

    public function markNotificationRead(Request $request, Transaction $transaction): RedirectResponse
    {
        abort_unless(
            $transaction->transaction_type === 'cash_in'
                && $transaction->status === 'PENDING_CASHIER_CONFIRM',
            422,
            'Only pending Cash In notifications can be marked as read.',
        );

        TransactionNotificationRead::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'transaction_id' => $transaction->id],
            ['read_at' => now()],
        );

        return back();
    }

    public function confirmCashIn(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate(
            ['pin' => ['required', 'string', 'regex:/^[0-9]{4,8}$/']],
            ['pin.regex' => 'PIN must be 4-8 digits.'],
        );

        try {
            $this->pinVerifier->verify($request->user(), $data['pin']);
            $this->transactions->confirmPendingCashIn($transaction, $request->user());
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->fail($exception, 'pin');
        }

        return back()->with('success', 'Cash In confirmed.');
    }

    public function cancelCashIn(CancelCashInRequest $request, Transaction $transaction): RedirectResponse
    {
        try {
            $this->pinVerifier->verify($request->user(), $request->validated()['pin']);
            $this->transactions->cancelPendingCashIn(
                $transaction,
                $request->user(),
                $request->validated()['note'] ?? null,
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->fail($exception, 'pin');
        }

        return back()->with('success', 'Cash In cancelled.');
    }

    public function updatePassword(ChangePasswordRequest $request): RedirectResponse
    {
        $data = $request->validated();
        if (! Hash::check($data['current_password'], (string) $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Current password is incorrect.',
            ]);
        }

        $this->users->update($request->user(), ['password' => $data['password']]);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function updatePin(SetPinRequest $request): RedirectResponse
    {
        $this->users->update($request->user(), ['pin' => $request->validated()['pin']]);

        return back()->with('success', 'Cashier PIN updated successfully.');
    }

    private function fail(Throwable $exception, string $field = 'form'): never
    {
        throw ValidationException::withMessages([
            $field => $exception->getMessage(),
        ]);
    }
}
