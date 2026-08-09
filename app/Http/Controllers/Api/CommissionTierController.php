<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Enums\AccountFeature;
use App\Http\Requests\CommissionTierRequest;
use App\Http\Resources\CommissionTierResource;
use App\Models\CommissionTier;
use App\Models\ServiceType;
use App\Repositories\CommissionTierRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class CommissionTierController extends Controller
{
    public function __construct(private readonly CommissionTierRepository $tiers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $serviceTypeId = $request->integer('service_type_id');
        $companyId = $request->integer('company_id') ?: null;
        $feature = $request->query('feature');

        if (is_string($feature)) {
            $feature = strtolower(trim($feature));
        }

        return CommissionTierResource::collection(
            CommissionTier::query()
                ->with(['company', 'serviceType.company'])
                ->when($serviceTypeId > 0, fn ($query) => $query->where('service_type_id', $serviceTypeId))
                ->when($serviceTypeId <= 0 && $companyId !== null, fn ($query) => $query->where('company_id', $companyId))
                ->when($serviceTypeId <= 0 && is_string($feature) && $feature !== '', fn ($query) => $query->where('feature', $feature))
                ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('is_active', true))
                ->orderBy('amount_from')
                ->orderBy('id')
                ->get(),
        );
    }

    public function lookup(Request $request): CommissionTierResource|JsonResponse
    {
        $data = $request->validate([
            'service_type_id' => ['sometimes', 'integer', 'exists:service_types,id'],
            'company_id' => ['sometimes', 'integer', 'exists:companies,id'],
            'feature' => ['sometimes', 'string', Rule::in(AccountFeature::values())],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        abort_unless(
            isset($data['service_type_id']) || (isset($data['company_id']) && isset($data['feature'])),
            422,
            'service_type_id or company_id and feature are required.'
        );

        $tier = isset($data['service_type_id'])
            ? $this->tiers->findForAmount((int) $data['service_type_id'], (float) $data['amount'])
            : $this->tiers->findForCompanyFeature((int) ($data['company_id'] ?? 0), (string) ($data['feature'] ?? ''), (float) $data['amount']);

        if ($tier === null) {
            return response()->json([
                'data' => [
                    'fee_amount_deposit' => 0,
                    'fee_amount_withdraw' => 0,
                    'comm_deposit' => 0,
                    'comm_withdraw' => 0,
                    'additional_fee_deposit_amount' => 0,
                    'additional_fee_withdraw_amount' => 0,
                ],
            ]);
        }

        return new CommissionTierResource($tier->load(['company', 'serviceType.company']));
    }

    public function store(CommissionTierRequest $request): JsonResponse
    {
        $this->guardAdmin($request);
        $data = $this->normalise($request->validated());
        $this->assertRange($data);

        try {
            $tier = DB::transaction(fn () => CommissionTier::query()->create($data)->load(['company', 'serviceType.company']));
        } catch (QueryException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return (new CommissionTierResource($tier))->response()->setStatusCode(201);
    }

    public function update(CommissionTierRequest $request, CommissionTier $commissionTier): CommissionTierResource|JsonResponse
    {
        $this->guardAdmin($request);
        $data = $this->normalise(array_merge($commissionTier->only([
            'company_id', 'feature', 'service_type_id', 'amount_from', 'amount_to', 'fee_type',
            'fee_amount', 'fee_amount_type',
            'fee_amount_deposit', 'fee_amount_withdraw', 'comm_type', 'comm_deposit',
            'comm_amount', 'comm_withdraw', 'additional_fee_type', 'additional_fee_amount', 'additional_fee_deposit_amount',
            'additional_fee_withdraw_amount', 'is_active',
        ]), $request->validated()));
        $this->assertRange($data, $commissionTier->id);

        $commissionTier->fill($data)->save();

        return new CommissionTierResource($commissionTier->refresh()->load(['company', 'serviceType.company']));
    }

    public function destroy(Request $request, CommissionTier $commissionTier): JsonResponse
    {
        $this->guardAdmin($request);
        $commissionTier->delete();

        return response()->json(['message' => 'Tier deleted.']);
    }

    private function guardAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Admin only.');
    }

    private function assertRange(array $data, ?int $ignoreId = null): void
    {
        if ((float) $data['amount_to'] <= (float) $data['amount_from']) {
            abort(422, 'amount_to must be greater than amount_from.');
        }

        $overlap = CommissionTier::query()
            ->when(
                ! empty($data['service_type_id']),
                fn ($query) => $query->where('service_type_id', $data['service_type_id']),
                fn ($query) => $query->where('company_id', $data['company_id'] ?? null)->where('feature', $data['feature'] ?? null)
            )
            ->where('is_active', true)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '<>', $ignoreId))
            ->where('amount_from', '<', $data['amount_to'])
            ->where('amount_to', '>', $data['amount_from'])
            ->exists();

        abort_if($overlap, 422, 'Commission tier range overlaps an existing tier.');
    }

    private function normalise(array $data): array
    {
        if ((! isset($data['company_id']) || ! isset($data['feature'])) && ! empty($data['service_type_id'])) {
            $serviceType = ServiceType::query()->find($data['service_type_id']);
            $legacyFeature = AccountFeature::fromLegacy($serviceType?->operation, $serviceType?->name)[0] ?? null;

            $data['company_id'] = $data['company_id'] ?? $serviceType?->company_id;
            $data['feature'] = $data['feature'] ?? $legacyFeature?->value;
        }

        $data['fee_amount_deposit'] = $this->firstValue($data, 'fee_amount_deposit', 'fee_amount_cash_in');
        $data['fee_amount_withdraw'] = $this->firstValue($data, 'fee_amount_withdraw', 'fee_amount_cash_out');
        $data['comm_deposit'] = $this->firstValue($data, 'comm_deposit', 'comm_cash_in');
        $data['comm_withdraw'] = $this->firstValue($data, 'comm_withdraw', 'comm_cash_out');
        $data['additional_fee_deposit_amount'] = $this->firstValue($data, 'additional_fee_deposit_amount', 'additional_fee_cash_in_amount');
        $data['additional_fee_withdraw_amount'] = $this->firstValue($data, 'additional_fee_withdraw_amount', 'additional_fee_cash_out_amount');
        $data['fee_type'] = $data['fee_type'] ?? ($data['fee_amount_type'] ?? 'FIXED');
        $data['fee_amount_type'] = $data['fee_amount_type'] ?? $data['fee_type'];
        $data['fee_amount'] = $data['fee_amount'] ?? $this->featureSideValue($data, 'fee_amount_deposit', 'fee_amount_withdraw');
        $data['comm_amount'] = $data['comm_amount'] ?? $this->featureSideValue($data, 'comm_deposit', 'comm_withdraw');
        $data['additional_fee_amount'] = $data['additional_fee_amount'] ?? $this->featureSideValue($data, 'additional_fee_deposit_amount', 'additional_fee_withdraw_amount');

        foreach (['fee_amount', 'fee_amount_deposit', 'fee_amount_withdraw', 'comm_amount', 'comm_deposit', 'comm_withdraw', 'additional_fee_amount', 'additional_fee_deposit_amount', 'additional_fee_withdraw_amount'] as $field) {
            $data[$field] = (float) ($data[$field] ?? 0);
        }

        unset($data['fee_amount_cash_in'], $data['fee_amount_cash_out'], $data['comm_cash_in'], $data['comm_cash_out'], $data['additional_fee_cash_in_amount'], $data['additional_fee_cash_out_amount']);

        return $data;
    }

    private function firstValue(array $data, string $preferred, string $legacy): mixed
    {
        return array_key_exists($preferred, $data) && $data[$preferred] !== null
            ? $data[$preferred]
            : ($data[$legacy] ?? 0);
    }

    private function featureSideValue(array $data, string $depositField, string $withdrawField): mixed
    {
        return in_array($data['feature'] ?? null, [AccountFeature::CashOut->value, AccountFeature::ReceiveMoney->value], true)
            ? ($data[$withdrawField] ?? 0)
            : ($data[$depositField] ?? 0);
    }
}
