<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CommissionTierRequest;
use App\Http\Resources\CommissionTierResource;
use App\Models\CommissionTier;
use App\Models\ServiceType;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CommissionTierController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $serviceTypeId = $request->integer('service_type_id');

        abort_unless($serviceTypeId > 0, 422, 'service_type_id is required.');

        return CommissionTierResource::collection(
            CommissionTier::query()
                ->with('serviceType.company')
                ->where('service_type_id', $serviceTypeId)
                ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('is_active', true))
                ->orderBy('amount_from')
                ->orderBy('id')
                ->get(),
        );
    }

    public function lookup(Request $request): CommissionTierResource|JsonResponse
    {
        $data = $request->validate([
            'service_type_id' => ['required', 'integer', 'exists:service_types,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $tier = $this->coveringTier((int) $data['service_type_id'], (float) $data['amount']);

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

        return new CommissionTierResource($tier->load('serviceType.company'));
    }

    public function store(CommissionTierRequest $request): JsonResponse
    {
        $this->guardAdmin($request);
        $data = $this->normalise($request->validated());
        $this->assertRange($data);

        try {
            $tier = DB::transaction(fn () => CommissionTier::query()->create($data)->load('serviceType.company'));
        } catch (QueryException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return (new CommissionTierResource($tier))->response()->setStatusCode(201);
    }

    public function update(CommissionTierRequest $request, CommissionTier $commissionTier): CommissionTierResource|JsonResponse
    {
        $this->guardAdmin($request);
        $data = $this->normalise(array_merge($commissionTier->only([
            'service_type_id', 'amount_from', 'amount_to', 'fee_amount_type',
            'fee_amount_deposit', 'fee_amount_withdraw', 'comm_type', 'comm_deposit',
            'comm_withdraw', 'additional_fee_type', 'additional_fee_deposit_amount',
            'additional_fee_withdraw_amount', 'is_active',
        ]), $request->validated()));
        $this->assertRange($data, $commissionTier->id);

        $commissionTier->fill($data)->save();

        return new CommissionTierResource($commissionTier->refresh()->load('serviceType.company'));
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

    private function coveringTier(int $serviceTypeId, float $amount): ?CommissionTier
    {
        return CommissionTier::query()
            ->with('serviceType.company')
            ->where('service_type_id', $serviceTypeId)
            ->where('is_active', true)
            ->where('amount_from', '<=', $amount)
            ->where('amount_to', '>', $amount)
            ->orderByRaw('CASE WHEN amount_from = 1 AND amount_to >= 999999999999 THEN 1 ELSE 0 END')
            ->orderBy('amount_from')
            ->orderBy('id')
            ->first();
    }

    private function assertRange(array $data, ?int $ignoreId = null): void
    {
        if ((float) $data['amount_to'] <= (float) $data['amount_from']) {
            abort(422, 'amount_to must be greater than amount_from.');
        }

        $overlap = CommissionTier::query()
            ->where('service_type_id', $data['service_type_id'])
            ->where('is_active', true)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '<>', $ignoreId))
            ->where('amount_from', '<', $data['amount_to'])
            ->where('amount_to', '>', $data['amount_from'])
            ->exists();

        abort_if($overlap, 422, 'Commission tier range overlaps an existing tier.');
    }

    private function normalise(array $data): array
    {
        $data['fee_amount_deposit'] = $this->firstValue($data, 'fee_amount_deposit', 'fee_amount_cash_in');
        $data['fee_amount_withdraw'] = $this->firstValue($data, 'fee_amount_withdraw', 'fee_amount_cash_out');
        $data['comm_deposit'] = $this->firstValue($data, 'comm_deposit', 'comm_cash_in');
        $data['comm_withdraw'] = $this->firstValue($data, 'comm_withdraw', 'comm_cash_out');
        $data['additional_fee_deposit_amount'] = $this->firstValue($data, 'additional_fee_deposit_amount', 'additional_fee_cash_in_amount');
        $data['additional_fee_withdraw_amount'] = $this->firstValue($data, 'additional_fee_withdraw_amount', 'additional_fee_cash_out_amount');

        foreach (['fee_amount_deposit', 'fee_amount_withdraw', 'comm_deposit', 'comm_withdraw', 'additional_fee_deposit_amount', 'additional_fee_withdraw_amount'] as $field) {
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
}
