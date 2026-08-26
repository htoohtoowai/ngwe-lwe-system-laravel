<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelCashInRequest;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ConfirmFloatReturnRequest;
use App\Http\Requests\ConfirmPendingCashInRequest;
use App\Http\Requests\IssueCashFloatRequest;
use App\Http\Requests\SetPinRequest;
use App\Models\CashFloatAssignment;
use App\Models\Transaction;
use App\Models\TransactionNotificationRead;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;
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
        private readonly AuditLogService $audit,
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
                $data['pin'],
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->fail($exception, 'pin');
        }

        return back()->with('success', 'Teller float return confirmed.');
    }

    public function markNotificationRead(Request $request, Transaction $transaction): RedirectResponse
    {
        $invalidMessage = $transaction->transaction_type === 'send_money'
            ? 'Only pending Send Money notifications can be marked as read.'
            : 'Only pending Cash In notifications can be marked as read.';

        abort_unless(
            in_array($transaction->transaction_type, ['cash_in', 'send_money'], true)
                && $transaction->status === 'PENDING_CASHIER_CONFIRM',
            422,
            $invalidMessage,
        );

        TransactionNotificationRead::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'transaction_id' => $transaction->id],
            ['read_at' => now()],
        );

        return back();
    }

    public function confirmCashIn(ConfirmPendingCashInRequest $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->pinVerifier->verify($request->user(), $data['pin']);
            $this->transactions->confirmPendingCashIn(
                $transaction,
                $request->user(),
                $data['received_denominations'],
                $data['change_denominations'] ?? [],
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->fail($exception, 'form');
        }

        return back()->with('success', 'Cash In confirmed and posted to the main vault.');
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

    public function confirmSendMoney(ConfirmPendingCashInRequest $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->pinVerifier->verify($request->user(), $data['pin']);
            $this->transactions->confirmPendingSendMoney(
                $transaction,
                $request->user(),
                $data['received_denominations'],
                $data['change_denominations'] ?? [],
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->fail($exception, 'form');
        }

        return back()->with('success', 'Send Money cash confirmed and posted to the main vault.');
    }

    public function cancelSendMoney(CancelCashInRequest $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->pinVerifier->verify($request->user(), $data['pin']);
            $this->transactions->cancelPendingSendMoney(
                $transaction,
                $request->user(),
                $data['note'] ?? null,
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->fail($exception, 'pin');
        }

        return back()->with('success', 'Send Money cancelled.');
    }

    public function updatePassword(ChangePasswordRequest $request): RedirectResponse
    {
        $data = $request->validated();
        if (! Hash::check($data['current_password'], (string) $request->user()->password)) {
            $this->audit->record(
                action: 'password_verification_failed',
                category: 'authentication',
                module: 'authentication',
                entityType: 'user',
                entityId: $request->user()->id,
                description: 'Password verification failed',
                status: 'failed',
                failureReason: 'Current password is incorrect',
            );

            throw ValidationException::withMessages([
                'current_password' => 'Current password is incorrect.',
            ]);
        }

        $user = $request->user();
        $this->users->update($user, ['password' => $data['password']]);

        $this->audit->record(
            action: 'logout',
            category: 'authentication',
            module: 'authentication',
            entityType: 'user',
            entityId: $user->id,
            description: 'User signed out after password change',
            details: ['reason' => 'password_change'],
            actor: $user,
        );

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
