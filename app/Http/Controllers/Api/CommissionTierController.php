<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountFeature;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommissionTierRequest;
use App\Http\Resources\CommissionTierResource;
use App\Models\CommissionTier;
use App\Repositories\CommissionTierRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommissionTierController extends Controller
{
    public function __construct(private readonly CommissionTierRepository $tiers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = $request->integer('company_id') ?: null;
        $feature = strtolower(trim((string) $request->query('feature', '')));

        return CommissionTierResource::collection(
            CommissionTier::query()
                ->with('company')
                ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
                ->when($feature !== '', fn ($query) => $query->where('feature', $feature))
                ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('is_active', true))
                ->orderBy('amount_from')
                ->orderBy('id')
                ->get(),
        );
    }

    public function lookup(Request $request): CommissionTierResource|JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'feature' => ['required', Rule::in(AccountFeature::values())],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $tier = $this->tiers->findForCompanyFeature(
            (int) $data['company_id'],
            (string) $data['feature'],
            (float) $data['amount'],
        );

        return $tier === null
            ? response()->json(['data' => [
                'fee_amount' => 0,
                'comm_amount' => 0,
                'additional_fee_amount' => 0,
            ]])
            : new CommissionTierResource($tier->load('company'));
    }

    public function store(CommissionTierRequest $request): JsonResponse
    {
        $this->guardAdmin($request);
        $data = $this->normalize($request->validated());
        $this->assertRange($data);

        try {
            $tier = DB::transaction(
                fn () => CommissionTier::query()->create($data)->load('company'),
            );
        } catch (QueryException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return (new CommissionTierResource($tier))->response()->setStatusCode(201);
    }

    public function update(
        CommissionTierRequest $request,
        CommissionTier $commissionTier,
    ): CommissionTierResource {
        $this->guardAdmin($request);
        $data = $this->normalize(array_merge(
            $commissionTier->only([
                'company_id',
                'feature',
                'amount_from',
                'amount_to',
                'fee_type',
                'fee_amount',
                'comm_type',
                'comm_amount',
                'additional_fee_type',
                'additional_fee_amount',
                'is_active',
            ]),
            $request->validated(),
        ));
        $this->assertRange($data, $commissionTier->id);

        $commissionTier->fill($data)->save();

        return new CommissionTierResource($commissionTier->refresh()->load('company'));
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
        $overlap = CommissionTier::query()
            ->where('company_id', $data['company_id'])
            ->where('feature', $data['feature'])
            ->where('is_active', true)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '<>', $ignoreId))
            ->where('amount_from', '<=', $data['amount_to'])
            ->where('amount_to', '>=', $data['amount_from'])
            ->exists();

        abort_if($overlap, 422, 'Commission tier range overlaps an existing tier.');
    }

    private function normalize(array $data): array
    {
        foreach (['fee_amount', 'comm_amount', 'additional_fee_amount'] as $field) {
            $data[$field] = (float) $data[$field];
        }

        return $data;
    }
}
