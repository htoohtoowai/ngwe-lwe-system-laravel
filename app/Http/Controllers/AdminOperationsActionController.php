<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\CommissionTierController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\RealtimeBroadcastController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ServiceTypeController;
use App\Http\Controllers\Api\SystemCompatibilityController;
use App\Http\Controllers\Api\UserController;
use App\Http\Requests\AccountRequest;
use App\Http\Requests\BalanceAdjustRequest;
use App\Http\Requests\CommissionTierRequest;
use App\Http\Requests\CompanyRequest;
use App\Http\Requests\ExchangeRateRequest;
use App\Http\Requests\ServiceTypeRequest;
use App\Http\Requests\UserRequest;
use App\Models\Account;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\DatabaseBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;

class AdminOperationsActionController extends Controller
{
    public function __construct(
        private readonly CompanyController $companies,
        private readonly ServiceTypeController $serviceTypes,
        private readonly AccountController $accounts,
        private readonly CommissionTierController $tiers,
        private readonly UserController $users,
        private readonly ExchangeRateController $rates,
        private readonly ReportController $reports,
        private readonly SystemCompatibilityController $system,
        private readonly RealtimeBroadcastController $broadcasts,
    ) {}

    public function storeCompany(CompanyRequest $request): RedirectResponse
    {
        $payload = $this->ensureSuccess($this->companies->store($request));
        $company = Company::query()->findOrFail($this->resourceId($payload));

        if ($request->hasFile('logo')) {
            $this->ensureSuccess($this->companies->uploadLogo($request, $company));
        }

        return redirect()->route('admin.operations.detail', ['section' => 'companies', 'resourceId' => $company->id]);
    }

    public function updateCompany(CompanyRequest $request, Company $company): RedirectResponse
    {
        $this->ensureSuccess($this->companies->update($request, $company));
        if ($request->hasFile('logo')) {
            $this->ensureSuccess($this->companies->uploadLogo($request, $company));
        }

        return redirect()->route('admin.operations.detail', ['section' => 'companies', 'resourceId' => $company->id]);
    }

    public function toggleCompany(CompanyRequest $request, Company $company): RedirectResponse
    {
        $this->ensureSuccess($this->companies->update($request, $company));
        return back()->with('success', 'Company status updated.');
    }

    public function destroyCompany(Company $company): RedirectResponse
    {
        $this->ensureSuccess($this->companies->destroy($company));
        return redirect()->route('admin.operations.section', ['section' => 'companies'])->with('success', 'Company deactivated.');
    }

    public function storeServiceType(ServiceTypeRequest $request): RedirectResponse
    {
        $payload = $this->ensureSuccess($this->serviceTypes->store($request));
        return redirect()->route('admin.operations.detail', ['section' => 'service-types', 'resourceId' => $this->resourceId($payload)]);
    }

    public function updateServiceType(ServiceTypeRequest $request, ServiceType $serviceType): RedirectResponse
    {
        $this->ensureSuccess($this->serviceTypes->update($request, $serviceType));
        return redirect()->route('admin.operations.detail', ['section' => 'service-types', 'resourceId' => $serviceType->id]);
    }

    public function toggleServiceType(ServiceTypeRequest $request, ServiceType $serviceType): RedirectResponse
    {
        $this->ensureSuccess($this->serviceTypes->update($request, $serviceType));
        return back()->with('success', 'Service type status updated.');
    }

    public function destroyServiceType(ServiceType $serviceType): RedirectResponse
    {
        $this->ensureSuccess($this->serviceTypes->destroy($serviceType));
        return redirect()->route('admin.operations.section', ['section' => 'service-types'])->with('success', 'Service type deactivated.');
    }

    public function storeAccount(AccountRequest $request): RedirectResponse
    {
        $payload = $this->ensureSuccess($this->accounts->store($request));
        return redirect()->route('admin.operations.detail', ['section' => 'accounts', 'resourceId' => $this->resourceId($payload)]);
    }

    public function updateAccount(AccountRequest $request, Account $account): RedirectResponse
    {
        $this->ensureSuccess($this->accounts->update($request, $account));
        return redirect()->route('admin.operations.detail', ['section' => 'accounts', 'resourceId' => $account->id]);
    }

    public function toggleAccount(AccountRequest $request, Account $account): RedirectResponse
    {
        $this->ensureSuccess($this->accounts->update($request, $account));
        return back()->with('success', 'Account status updated.');
    }

    public function destroyAccount(Account $account): RedirectResponse
    {
        $this->ensureSuccess($this->accounts->destroy($account));
        return redirect()->route('admin.operations.section', ['section' => 'accounts'])->with('success', 'Account deactivated.');
    }

    public function adjustAccount(BalanceAdjustRequest $request, Account $account): RedirectResponse
    {
        $this->ensureSuccess($this->accounts->adjustBalance($request, $account));
        return back()->with('success', 'Account balance adjusted.');
    }

    public function storeTier(CommissionTierRequest $request): RedirectResponse
    {
        $this->ensureSuccess($this->tiers->store($request));
        return redirect()->route('admin.operations.fees')->with('success', 'Commission tier saved.');
    }

    public function updateTier(CommissionTierRequest $request, CommissionTier $commissionTier): RedirectResponse
    {
        $this->ensureSuccess($this->tiers->update($request, $commissionTier));
        return redirect()->route('admin.operations.fees')->with('success', 'Commission tier updated.');
    }

    public function destroyTier(Request $request, CommissionTier $commissionTier): RedirectResponse
    {
        $this->ensureSuccess($this->tiers->destroy($request, $commissionTier));
        return back()->with('success', 'Commission tier deleted.');
    }

    public function storeUser(UserRequest $request): RedirectResponse
    {
        $payload = $this->ensureSuccess($this->users->store($request));
        return redirect()->route('admin.operations.detail', ['section' => 'users', 'resourceId' => $this->resourceId($payload)]);
    }

    public function updateUser(UserRequest $request, User $user): RedirectResponse
    {
        $this->ensureSuccess($this->users->update($request, $user));
        return redirect()->route('admin.operations.detail', ['section' => 'users', 'resourceId' => $user->id]);
    }

    public function toggleUser(Request $request, User $user): RedirectResponse
    {
        $this->ensureSuccess($this->users->toggleActive($request, $user));
        return back()->with('success', 'User status updated.');
    }

    public function resetUserPassword(Request $request, User $user): RedirectResponse
    {
        $this->ensureSuccess($this->users->resetPassword($request, $user));
        return back()->with('success', 'User password reset.');
    }

    public function setUserPin(Request $request, User $user): RedirectResponse
    {
        $this->ensureSuccess($this->users->setUserPin($request, $user));
        return back()->with('success', 'User PIN updated.');
    }

    public function storeRate(ExchangeRateRequest $request): RedirectResponse
    {
        $payload = $this->ensureSuccess($this->rates->store($request));
        return redirect()->route('admin.operations.detail', ['section' => 'exchange-rates', 'resourceId' => $this->resourceId($payload)]);
    }

    public function updateRate(ExchangeRateRequest $request, ExchangeRate $exchangeRate): RedirectResponse
    {
        $this->ensureSuccess($this->rates->update($request, $exchangeRate));
        return redirect()->route('admin.operations.detail', ['section' => 'exchange-rates', 'resourceId' => $exchangeRate->id]);
    }

    public function destroyRate(ExchangeRate $exchangeRate): RedirectResponse
    {
        $this->ensureSuccess($this->rates->destroy($exchangeRate));
        return redirect()->route('admin.operations.section', ['section' => 'exchange-rates'])->with('success', 'Exchange rate deleted.');
    }

    public function changePassword(Request $request): RedirectResponse
    {
        $this->ensureSuccess($this->users->changePasswordCompat($request));
        return back()->with('success', 'Password changed.');
    }

    public function closeDay(Request $request): RedirectResponse
    {
        $this->ensureSuccess($this->reports->closeDay($request));
        return back()->with('success', 'Day closed.');
    }

    public function backup(Request $request, DatabaseBackupService $backups): RedirectResponse
    {
        $payload = $this->ensureSuccess($this->system->backup($request, $backups));
        return back()->with('success', $payload['path'] ?? 'Backup created.');
    }

    public function broadcastTest(Request $request): RedirectResponse
    {
        $this->ensureSuccess($this->broadcasts->test($request));
        return back()->with('success', 'Broadcast test sent.');
    }

    /** @return array<string, mixed> */
    private function ensureSuccess(JsonResponse|JsonResource $response): array
    {
        $json = $response instanceof JsonResource ? $response->response() : $response;
        $payload = $json->getData(true);

        if ($json->getStatusCode() >= 400) {
            throw ValidationException::withMessages([
                'form' => $payload['message'] ?? 'The operation could not be completed.',
            ]);
        }

        return is_array($payload) ? $payload : [];
    }

    /** @param array<string, mixed> $payload */
    private function resourceId(array $payload): int
    {
        $id = $payload['data']['id'] ?? $payload['id'] ?? null;
        abort_unless(is_numeric($id), 500, 'Created resource identifier is missing.');

        return (int) $id;
    }
}
