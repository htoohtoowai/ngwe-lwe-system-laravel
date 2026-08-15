<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Http\Requests\AccountRequest;
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
use App\Repositories\CompanyRepository;
use App\Repositories\ExchangeRateRepository;
use App\Repositories\UserRepository;
use App\Services\DailyReportService;
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

    public function storeUser(UserRequest $request): RedirectResponse
    {
        try {
            $user = $this->users->create($request->validated());
        } catch (QueryException) {
            throw ValidationException::withMessages(['username' => 'Username or email already exists.']);
        }

        return redirect()->route('admin.operations.detail', ['section' => 'users', 'resourceId' => $user->id]);
    }

    public function updateUser(UserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
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

        $this->reports->close(
            $request->user(),
            $data['date'] ?? now()->toDateString(),
            $data['notes'] ?? null,
        );

        return back()->with('success', 'Day closed.');
    }

    public function backup(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Admin only.');
        $path = $this->backups->create();

        return back()->with('success', "Backup created: {$path}");
    }

    public function broadcastTest(Request $request): RedirectResponse
    {
        $this->broadcasts->ping($request->user());

        return back()->with('success', 'Broadcast test sent.');
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
