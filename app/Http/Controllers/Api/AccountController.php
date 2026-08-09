<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountFeature;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccountRequest;
use App\Http\Requests\BalanceAdjustRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Models\AccountFeatureAssignment;
use App\Models\ActivityLog;
use App\Models\ServiceType;
use App\Repositories\AccountRepository;
use App\Services\RealtimeBroadcastService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly RealtimeBroadcastService $broadcasts,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $serviceTypeId = $request->integer('service_type_id') ?: null;
        $companyId = $request->integer('company_id') ?: null;
        $feeOnly = $request->boolean('fee_only');

        return AccountResource::collection(
            $this->accounts->all($serviceTypeId, $companyId, $feeOnly, $request->boolean('include_inactive'))
        );
    }

    public function store(AccountRequest $request): JsonResponse
    {
        $data = $this->normalizeAccountPayload($request->validated(), null, true);
        $features = $this->extractFeaturePayload($data);

        $account = DB::transaction(function () use ($data, $features): Account {
            $account = $this->accounts->create($data);
            $this->syncFeatures($account, $features);

            return $account->refresh()->load(['company', 'serviceType.company', 'featureAssignments']);
        });

        return (new AccountResource($account))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Account $account): AccountResource
    {
        return new AccountResource($account->load(['company', 'serviceType.company', 'featureAssignments']));
    }

    public function update(AccountRequest $request, Account $account): AccountResource|JsonResponse
    {
        $data = $request->validated();

        if ($data === []) {
            return response()->json(['message' => 'No fields to update.'], 400);
        }

        $data = $this->normalizeAccountPayload($data, $account, false);
        $features = $this->extractFeaturePayload($data);

        $account = DB::transaction(function () use ($account, $data, $features): Account {
            $account = $this->accounts->update($account, $data);
            $this->syncFeatures($account, $features);

            return $account->refresh()->load(['company', 'serviceType.company', 'featureAssignments']);
        });

        return new AccountResource($account);
    }

    public function destroy(Account $account): AccountResource
    {
        return new AccountResource($this->accounts->deactivate($account));
    }

    public function adjustBalance(BalanceAdjustRequest $request, Account $account): JsonResponse
    {
        $delta = Money::normalize($request->validated()['amount']);
        $remark = (string) ($request->validated()['remark'] ?? '');
        $oldBalance = Money::normalize($account->balance);

        $updated = DB::transaction(function () use ($account, $delta, $remark, $oldBalance, $request): Account {
            $applied = $this->accounts->incrementBalance($account->id, $delta);

            if ($applied === null) {
                throw new \RuntimeException("Unable to adjust inactive account #{$account->id}.");
            }

            $newBalance = Money::normalize($applied->balance);

            ActivityLog::query()->create([
                'user_id' => $request->user()->id,
                'action' => 'balance_adjust',
                'entity_type' => 'account',
                'entity_id' => $account->id,
                'details' => [
                    'amount' => $delta,
                    'old_balance' => $oldBalance,
                    'new_balance' => $newBalance,
                    'remark' => $remark,
                ],
            ]);

            return $applied;
        });

        $this->broadcasts->balanceUpdated();

        return response()->json([
            'message' => 'Balance adjusted',
            'data' => [
                'account_id' => $updated->id,
                'old_balance' => $oldBalance,
                'new_balance' => Money::normalize($updated->balance),
                'delta' => $delta,
            ],
        ]);
    }

    private function normalizeMoneyFields(array $data, bool $withDefaults = true): array
    {
        foreach (['balance', 'commission_rate'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = Money::normalize($data[$field], $field === 'commission_rate' ? 4 : 2);
            }
        }

        if ($withDefaults && ! array_key_exists('balance', $data)) {
            $data['balance'] = Money::normalize(0);
        }

        if ($withDefaults && ! array_key_exists('commission_rate', $data)) {
            $data['commission_rate'] = Money::normalize(0, 4);
        }

        return $data;
    }

    private function normalizeAccountPayload(array $data, ?Account $account = null, bool $withDefaults = true): array
    {
        $data = $this->normalizeMoneyFields($data, $withDefaults);

        if (! array_key_exists('company_id', $data)) {
            $serviceTypeId = $data['service_type_id'] ?? $account?->service_type_id;
            $companyId = $serviceTypeId !== null
                ? ServiceType::query()->whereKey($serviceTypeId)->value('company_id')
                : null;

            if ($companyId !== null) {
                $data['company_id'] = $companyId;
            }
        }

        return $data;
    }

    /**
     * @return list<string>|null
     */
    private function extractFeaturePayload(array &$data): ?array
    {
        if (! array_key_exists('features', $data)) {
            return null;
        }

        $features = array_values(array_unique($data['features']));
        unset($data['features']);

        return $features;
    }

    /**
     * @param  list<string>|null  $features
     */
    private function syncFeatures(Account $account, ?array $features): void
    {
        if ($features === null) {
            $serviceType = $account->service_type_id !== null
                ? ServiceType::query()->find($account->service_type_id)
                : null;
            $features = array_map(
                fn (AccountFeature $feature): string => $feature->value,
                AccountFeature::fromLegacy($serviceType?->operation, $serviceType?->name)
            );
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
}
