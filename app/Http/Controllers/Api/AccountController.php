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
        $companyId = $request->integer('company_id') ?: null;
        $feeOnly = $request->boolean('fee_only');

        return AccountResource::collection(
            $this->accounts->all($companyId, $feeOnly, $request->boolean('include_inactive'))
        );
    }

    public function store(AccountRequest $request): JsonResponse
    {
        $data = $this->normalizeAccountPayload($request->validated(), true);
        $features = $this->extractFeaturePayload($data);

        $account = DB::transaction(function () use ($data, $features): Account {
            $account = $this->accounts->create($data);
            $this->syncFeatures($account, $features);

            return $account->refresh()->load(['company', 'featureAssignments']);
        });

        return (new AccountResource($account))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Account $account): AccountResource
    {
        return new AccountResource($account->load(['company', 'featureAssignments']));
    }

    public function update(AccountRequest $request, Account $account): AccountResource|JsonResponse
    {
        $data = $request->validated();

        if ($data === []) {
            return response()->json(['message' => 'No fields to update.'], 400);
        }

        $data = $this->normalizeAccountPayload($data, false);
        $features = $this->extractFeaturePayload($data);

        $account = DB::transaction(function () use ($account, $data, $features): Account {
            $account = $this->accounts->update($account, $data);
            $this->syncFeatures($account, $features);

            return $account->refresh()->load(['company', 'featureAssignments']);
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
        foreach (['balance'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = Money::normalize($data[$field]);
            }
        }

        if ($withDefaults && ! array_key_exists('balance', $data)) {
            $data['balance'] = Money::normalize(0);
        }


        return $data;
    }

    private function normalizeAccountPayload(array $data, bool $withDefaults): array
    {
        return $this->normalizeMoneyFields($data, $withDefaults);
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
}
