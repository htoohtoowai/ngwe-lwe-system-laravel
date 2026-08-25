<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Http\Requests\AccountRequest;
use App\Http\Requests\AdminVaultEntryRequest;
use App\Http\Requests\BalanceAdjustRequest;
use App\Http\Requests\CompanyRequest;
use App\Http\Requests\ExchangeRateRequest;
use App\Http\Requests\UserRequest;
use App\Models\Account;
use App\Models\AccountFeatureAssignment;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Repositories\AccountRepository;
use App\Repositories\CashDenominationRepository;
use App\Repositories\CompanyRepository;
use App\Repositories\ExchangeRateRepository;
use App\Repositories\UserRepository;
use App\Repositories\VaultTransactionRepository;
use App\Services\DailyReportService;
use App\Services\AuditLogService;
use App\Services\DatabaseBackupService;
use App\Services\RealtimeBroadcastService;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminOperationsActionController extends Controller
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly AccountRepository $accounts,
        private readonly UserRepository $users,
        private readonly ExchangeRateRepository $rates,
        private readonly DailyReportService $reports,
        private readonly RealtimeBroadcastService $broadcasts,
        private readonly DatabaseBackupService $backups,
        private readonly CashDenominationRepository $vault,
        private readonly VaultTransactionRepository $vaultTransactions,
        private readonly AuditLogService $audit,
    ) {}

    public function storeCompany(CompanyRequest $request): RedirectResponse
    {
        try {
            $company = $this->companies->create($request->safe()->except('logo'));
        } catch (QueryException) {
            throw ValidationException::withMessages(['name' => 'Company already exists.']);
        }

        $this->storeCompanyLogo($request, $company);

        return redirect()->route('admin.operations.detail', ['section' => 'companies', 'resourceId' => $company->id]);
    }

    public function updateCompany(CompanyRequest $request, Company $company): RedirectResponse
    {
        try {
            $this->companies->update($company, $request->safe()->except('logo'));
        } catch (QueryException) {
            throw ValidationException::withMessages(['name' => 'Company already exists.']);
        }

        $this->storeCompanyLogo($request, $company);

        return redirect()->route('admin.operations.detail', ['section' => 'companies', 'resourceId' => $company->id]);
    }

    public function toggleCompany(CompanyRequest $request, Company $company): RedirectResponse
    {
        $this->companies->update($company, $request->validated());

        return back()->with('success', 'Company status updated.');
    }

    public function destroyCompany(Company $company): RedirectResponse
    {
        $this->companies->deactivate($company);

        return redirect()->route('admin.operations.section', ['section' => 'companies'])
            ->with('success', 'Company deactivated.');
    }

    public function storeAccount(AccountRequest $request): RedirectResponse
    {
        $data = $this->normalizeAccountPayload($request->validated(), true);
        $features = $this->extractFeaturePayload($data);

        $account = DB::transaction(function () use ($data, $features): Account {
            $account = $this->accounts->create($data);
            $this->syncFeatures($account, $features);

            return $account->refresh();
        });

        return redirect()->route('admin.operations.detail', ['section' => 'accounts', 'resourceId' => $account->id]);
    }

    public function updateAccount(AccountRequest $request, Account $account): RedirectResponse
    {
        $data = $this->normalizeAccountPayload($request->validated(), false);
        $features = $this->extractFeaturePayload($data);

        DB::transaction(function () use ($account, $data, $features): void {
            $this->accounts->update($account, $data);
            $this->syncFeatures($account, $features);
        });

        return redirect()->route('admin.operations.detail', ['section' => 'accounts', 'resourceId' => $account->id]);
    }

    public function toggleAccount(AccountRequest $request, Account $account): RedirectResponse
    {
        $data = $this->normalizeAccountPayload($request->validated(), false);
        $features = $this->extractFeaturePayload($data);
        $this->accounts->update($account, $data);
        $this->syncFeatures($account, $features);

        return back()->with('success', 'Account status updated.');
    }

    public function destroyAccount(Account $account): RedirectResponse
    {
        $this->accounts->deactivate($account);

        return redirect()->route('admin.operations.section', ['section' => 'accounts'])
            ->with('success', 'Account deactivated.');
    }

    public function adjustAccount(BalanceAdjustRequest $request, Account $account): RedirectResponse
    {
        $data = $request->validated();
        $delta = Money::normalize($data['amount']);
        $oldBalance = Money::normalize($account->balance);

        DB::transaction(function () use ($request, $account, $delta, $oldBalance, $data): void {
            $updated = $this->accounts->incrementBalance($account->id, $delta);
            if ($updated === null) {
                throw ValidationException::withMessages(['amount' => 'Unable to adjust an inactive account.']);
            }

            ActivityLog::query()->create([
                'user_id' => $request->user()->id,
                'action' => 'balance_adjust',
                'entity_type' => 'account',
                'entity_id' => $account->id,
                'details' => [
                    'amount' => $delta,
                    'old_balance' => $oldBalance,
                    'new_balance' => Money::normalize($updated->balance),
                    'remark' => (string) ($data['remark'] ?? ''),
                ],
            ]);
        });

        $this->broadcasts->balanceUpdated();

        return back()->with('success', 'Account balance adjusted.');
    }

    public function recordCashierVaultEntry(AdminVaultEntryRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $denominations = collect($data['denominations'])
            ->mapWithKeys(fn ($quantity, $denomination): array => [(int) $denomination => (int) $quantity])
            ->all();
        $total = Money::denominationTotal($denominations);
        $cashier = User::query()
            ->where('role', 'cashier')
            ->where('is_active', true)
            ->first();

        if ($cashier === null) {
            throw ValidationException::withMessages([
                'form' => 'An active Cashier is required before the Owner can manage the Cashier vault.',
            ]);
        }

        $isDeposit = $data['entry_type'] === 'vault_in';
        $direction = $isDeposit ? 'deposit' : 'withdraw';
        $defaultNote = $isDeposit
            ? 'Owner deposited cash into the Cashier main vault.'
            : 'Owner withdrew cash from the Cashier main vault.';
        $note = trim((string) ($data['note'] ?? '')) ?: $defaultNote;
        $auditNote = sprintf(
            'Owner %s for Cashier %s. %s',
            $isDeposit ? 'deposit to Cashier vault' : 'withdrawal from Cashier vault',
            $cashier->full_name,
            $note,
        );

        try {
            DB::transaction(function () use (
                $request,
                $cashier,
                $data,
                $denominations,
                $total,
                $direction,
                $auditNote,
            ): void {
                $batchId = (string) Str::uuid();
                $movementType = $direction === 'deposit'
                    ? 'admin_to_cashier'
                    : 'cashier_to_admin';
                $sourceType = $direction === 'deposit' ? 'admin' : 'cashier_vault';
                $sourceId = $direction === 'deposit' ? $request->user()->id : $cashier->id;
                $destinationType = $direction === 'deposit' ? 'cashier_vault' : 'admin';
                $destinationId = $direction === 'deposit' ? $cashier->id : $request->user()->id;

                $this->vault->recordBulk(
                    entryType: $data['entry_type'],
                    denominations: $denominations,
                    createdBy: $request->user()->id,
                    note: $auditNote,
                    batchId: $batchId,
                    movementType: $movementType,
                    sourceType: $sourceType,
                    sourceId: $sourceId,
                    destinationType: $destinationType,
                    destinationId: $destinationId,
                    affectsMainVault: true,
                );

                // Keep the existing txn_type enum stable; movement_type carries
                // the exact Owner/Cashier direction for reconciliation.
                $this->vaultTransactions->recordBulk(
                    txnType: 'adjustment',
                    denominations: $denominations,
                    performedBy: $request->user()->id,
                    note: $auditNote,
                    batchId: $batchId,
                    movementType: $movementType,
                    sourceType: $sourceType,
                    sourceId: $sourceId,
                    destinationType: $destinationType,
                    destinationId: $destinationId,
                );

                ActivityLog::query()->create([
                    'user_id' => $request->user()->id,
                    'action' => 'cashier_vault_'.$direction,
                    'entity_type' => 'cashier_vault',
                    'entity_id' => $cashier->id,
                    'details' => [
                        'cashier_id' => $cashier->id,
                        'cashier_name' => $cashier->full_name,
                        'direction' => $direction,
                        'batch_id' => $batchId,
                        'amount' => $total,
                        'denominations' => $denominations,
                        'note' => $auditNote,
                    ],
                ]);
            });
        } catch (\RuntimeException|\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['denominations' => $exception->getMessage()]);
        }

        $this->broadcasts->balanceUpdated();

        return back()->with(
            'success',
            $isDeposit
                ? 'Cash deposited into the Cashier vault.'
                : 'Cash withdrawn from the Cashier vault.',
        );
    }

    public function storeUser(UserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->assertSingleCashier($data);

        try {
            $user = $this->users->create($data);
        } catch (QueryException) {
            throw ValidationException::withMessages(['username' => 'Username or email already exists.']);
        }

        return redirect()->route('admin.operations.detail', ['section' => 'users', 'resourceId' => $user->id]);
    }

    public function updateUser(UserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $this->assertSingleCashier($data, $user);
        if ($request->user()->is($user) && (($data['is_active'] ?? true) === false)) {
            throw ValidationException::withMessages(['is_active' => 'Admins cannot deactivate their own active session.']);
        }

        try {
            $this->users->update($user, $data);
        } catch (QueryException) {
            throw ValidationException::withMessages(['username' => 'Username or email already exists.']);
        }

        return redirect()->route('admin.operations.detail', ['section' => 'users', 'resourceId' => $user->id]);
    }

    public function toggleUser(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $this->assertSingleCashier($data, $user);
        if ($request->user()->is($user) && $data['is_active'] === false) {
            throw ValidationException::withMessages(['is_active' => 'Admins cannot deactivate their own active session.']);
        }

        $this->users->update($user, ['is_active' => $data['is_active']]);

        return back()->with('success', 'User status updated.');
    }

    public function resetUserPassword(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['new_password' => ['required', 'string', 'min:8', 'max:255']]);
        $this->users->update($user, ['password' => $data['new_password']]);

        return back()->with('success', 'User password reset.');
    }

    public function setUserPin(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits_between:4,8']]);
        $this->users->update($user, ['pin' => $data['pin']]);

        return back()->with('success', 'User PIN updated.');
    }

    public function storeRate(ExchangeRateRequest $request): RedirectResponse
    {
        $rate = $this->rates->create($this->normalizeRatePayload($request->validated()));

        return redirect()->route('admin.operations.detail', ['section' => 'exchange-rates', 'resourceId' => $rate->id]);
    }

    public function updateRate(ExchangeRateRequest $request, ExchangeRate $exchangeRate): RedirectResponse
    {
        $this->rates->update($exchangeRate, $this->normalizeRatePayload($request->validated()));

        return redirect()->route('admin.operations.detail', ['section' => 'exchange-rates', 'resourceId' => $exchangeRate->id]);
    }

    public function destroyRate(ExchangeRate $exchangeRate): RedirectResponse
    {
        $this->rates->delete($exchangeRate);

        return redirect()->route('admin.operations.section', ['section' => 'exchange-rates'])
            ->with('success', 'Exchange rate deleted.');
    }

    public function changePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'old_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        if (! Hash::check($data['old_password'], (string) $request->user()->password)) {
            $this->audit->record(
                action: 'password_verification_failed',
                category: 'authentication',
                module: 'authentication',
                entityType: 'user',
                entityId: $request->user()->id,
                description: 'Password verification failed',
                status: 'failed',
                failureReason: 'Old password incorrect',
            );

            throw ValidationException::withMessages(['old_password' => 'Old password incorrect.']);
        }

        $this->users->update($request->user(), ['password' => $data['new_password']]);

        return back()->with('success', 'Password changed.');
    }

    public function closeDay(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $date = $data['date'] ?? now()->toDateString();
        $this->reports->close(
            $request->user(),
            $date,
            $data['notes'] ?? null,
        );

        $this->audit->record(
            action: 'close_day',
            category: 'system',
            module: 'daily_closing',
            entityType: 'daily_summary',
            description: 'Closed business day',
            details: ['date' => $date, 'notes' => $data['notes'] ?? null],
        );

        return back()->with('success', 'Day closed.');
    }

    public function backup(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Admin only.');
        $path = $this->backups->create();

        $this->audit->record(
            action: 'backup_created',
            category: 'system',
            module: 'backup',
            entityType: 'database_backup',
            description: 'Created database backup',
            details: ['path' => $path],
        );

        return back()->with('success', "Backup created: {$path}");
    }

    public function broadcastTest(Request $request): RedirectResponse
    {
        $this->broadcasts->ping($request->user());

        $this->audit->record(
            action: 'broadcast_test',
            category: 'system',
            module: 'realtime',
            entityType: 'broadcast',
            description: 'Sent realtime broadcast test',
        );

        return back()->with('success', 'Broadcast test sent.');
    }

    /** @param array<string, mixed> $data */
    private function assertSingleCashier(array $data, ?User $target = null): void
    {
        $role = (string) ($data['role'] ?? $target?->role ?? '');

        if ($role !== 'cashier') {
            return;
        }

        $otherCashierExists = User::query()
            ->where('role', 'cashier')
            ->when($target !== null, fn ($query) => $query->whereKeyNot($target->id))
            ->exists();

        if ($otherCashierExists) {
            throw ValidationException::withMessages([
                'role' => 'Only one Cashier account is allowed. Update the existing Cashier instead of creating or assigning another one.',
            ]);
        }
    }

    private function storeCompanyLogo(Request $request, Company $company): void
    {
        if (! $request->hasFile('logo')) {
            return;
        }

        $request->validate(['logo' => ['file', 'image', 'max:2048']]);
        /** @var UploadedFile $logo */
        $logo = $request->file('logo');
        $path = $logo->store('company-logos', 'public');

        if ($company->logo_path !== null && Storage::disk('public')->exists($company->logo_path)) {
            Storage::disk('public')->delete($company->logo_path);
        }

        $company->update(['logo_path' => $path]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizeAccountPayload(array $data, bool $withDefaults): array
    {
        if (array_key_exists('balance', $data)) {
            $data['balance'] = Money::normalize($data['balance']);
        } elseif ($withDefaults) {
            $data['balance'] = Money::normalize(0);
        }

        if (($data['account_type'] ?? null) === AccountType::Bank->value) {
            $data['is_agent'] = false;
        }

        return $data;
    }

    /** @param array<string, mixed> $data @return list<string>|null */
    private function extractFeaturePayload(array &$data): ?array
    {
        if (! array_key_exists('features', $data)) {
            return null;
        }

        $features = array_values(array_unique($data['features']));
        unset($data['features']);

        return $features;
    }

    /** @param list<string>|null $features */
    private function syncFeatures(Account $account, ?array $features): void
    {
        if ($features === null) {
            return;
        }

        AccountFeatureAssignment::query()
            ->where('account_id', $account->id)
            ->whereNotIn('feature', $features)
            ->delete();

        foreach ($features as $feature) {
            AccountFeatureAssignment::query()->firstOrCreate([
                'account_id' => $account->id,
                'feature' => $feature,
            ]);
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizeRatePayload(array $data): array
    {
        foreach (['base_amount', 'buy_rate', 'sell_rate'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = Money::normalize($data[$field], $field === 'base_amount' ? 2 : 4);
            }
        }

        return $data;
    }
}
