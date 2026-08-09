<?php

namespace App\Http\Controllers;

use App\Enums\AccountFeature;
use App\Http\Requests\ProviderFeeTierRequest;
use App\Http\Requests\TransferFeeTierRequest;
use App\Models\CommissionTier;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\TransferFeeTier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminFeeController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->render($request);
    }

    public function createProvider(Request $request): Response
    {
        return $this->render($request, 'create', 'provider');
    }

    public function showProvider(Request $request, CommissionTier $commissionTier): Response
    {
        return $this->render($request, 'detail', 'provider', $commissionTier->id);
    }

    public function editProvider(Request $request, CommissionTier $commissionTier): Response
    {
        return $this->render($request, 'edit', 'provider', $commissionTier->id);
    }

    public function createTransfer(Request $request): Response
    {
        return $this->render($request, 'create', 'transfer');
    }

    public function editTransfer(Request $request, TransferFeeTier $transferFeeTier): Response
    {
        return $this->render($request, 'edit', 'transfer', $transferFeeTier->id);
    }

    public function storeProvider(ProviderFeeTierRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->assertProviderRangeAvailable($data);

        CommissionTier::query()->create([
            ...$data,
            'service_type_id' => null,
            'fee_amount_type' => $data['fee_type'],
        ]);

        return redirect('/admin/fees?kind=provider');
    }

    public function updateProvider(
        ProviderFeeTierRequest $request,
        CommissionTier $commissionTier,
    ): RedirectResponse {
        $data = $request->validated();
        $this->assertProviderRangeAvailable($data, $commissionTier->id);
        $commissionTier->fill([
            ...$data,
            'service_type_id' => null,
            'fee_amount_type' => $data['fee_type'],
        ])->save();

        return redirect('/admin/fees?kind=provider');
    }

    public function destroyProvider(CommissionTier $commissionTier): RedirectResponse
    {
        $commissionTier->delete();

        return redirect('/admin/fees?kind=provider');
    }

    public function storeTransfer(TransferFeeTierRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->assertTransferRangeAvailable($data);
        TransferFeeTier::query()->create($data);

        return redirect('/admin/fees?kind=transfer');
    }

    public function updateTransfer(
        TransferFeeTierRequest $request,
        TransferFeeTier $transferFeeTier,
    ): RedirectResponse {
        $data = $request->validated();
        $this->assertTransferRangeAvailable($data, $transferFeeTier->id);
        $transferFeeTier->fill($data)->save();

        return redirect('/admin/fees?kind=transfer');
    }

    public function destroyTransfer(TransferFeeTier $transferFeeTier): RedirectResponse
    {
        $transferFeeTier->delete();

        return redirect('/admin/fees?kind=transfer');
    }

    private function render(
        Request $request,
        string $mode = 'list',
        string $editorKind = 'provider',
        ?int $resourceId = null,
    ): Response {
        $features = [
            AccountFeature::CashIn,
            AccountFeature::CashOut,
            AccountFeature::SendMoney,
            AccountFeature::ReceiveMoney,
        ];

        return Inertia::render('admin/Fees', [
            'role' => 'admin',
            'section' => 'fees',
            'announcement' => 'Configure provider fees, agent commissions and transfer route fees.',
            'notificationCount' => Transaction::query()
                ->where('transaction_type', 'cash_in')
                ->where('status', 'PENDING_CASHIER_CONFIRM')
                ->count(),
            'mode' => $mode,
            'editorKind' => $editorKind,
            'resourceId' => $resourceId,
            'initialKind' => $request->query('kind') === 'transfer' ? 'transfer' : $editorKind,
            'features' => collect($features)->map(fn (AccountFeature $feature): array => [
                'value' => $feature->value,
                'label' => $feature->label(),
            ])->all(),
            'companies' => Company::query()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name', 'logo_path', 'is_active'])
                ->map(fn (Company $company): array => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'logo_url' => $company->logo_path ? route('companies.logo', $company) : null,
                    'is_active' => (bool) $company->is_active,
                ])->all(),
            'providerTiers' => CommissionTier::query()
                ->with('company:id,name')
                ->whereNotNull('company_id')
                ->whereIn('feature', collect($features)->map->value->all())
                ->orderBy('company_id')
                ->orderBy('feature')
                ->orderBy('amount_from')
                ->get()
                ->map(fn (CommissionTier $tier): array => $this->providerTierData($tier))
                ->all(),
            'transferTiers' => TransferFeeTier::query()
                ->with(['fromCompany:id,name', 'toCompany:id,name'])
                ->orderBy('company_from_id')
                ->orderBy('company_to_id')
                ->orderBy('amount_from')
                ->get()
                ->map(fn (TransferFeeTier $tier): array => $this->transferTierData($tier))
                ->all(),
        ]);
    }

    private function providerTierData(CommissionTier $tier): array
    {
        return [
            'id' => $tier->id,
            'company_id' => $tier->company_id,
            'company_name' => $tier->company?->name,
            'feature' => $tier->feature,
            'amount_from' => $tier->amount_from,
            'amount_to' => $tier->amount_to,
            'fee_type' => $tier->fee_type,
            'fee_amount' => $tier->fee_amount,
            'additional_fee_type' => $tier->additional_fee_type,
            'additional_fee_amount' => $tier->additional_fee_amount,
            'comm_type' => $tier->comm_type,
            'comm_amount' => $tier->comm_amount,
            'is_active' => (bool) $tier->is_active,
        ];
    }

    private function transferTierData(TransferFeeTier $tier): array
    {
        return [
            'id' => $tier->id,
            'company_from_id' => $tier->company_from_id,
            'company_from_name' => $tier->fromCompany?->name,
            'company_to_id' => $tier->company_to_id,
            'company_to_name' => $tier->toCompany?->name,
            'amount_from' => $tier->amount_from,
            'amount_to' => $tier->amount_to,
            'fee_type' => $tier->fee_type,
            'fee_amount' => $tier->fee_amount,
            'additional_fee_type' => $tier->additional_fee_type,
            'additional_fee_amount' => $tier->additional_fee_amount,
            'is_active' => (bool) $tier->is_active,
        ];
    }

    private function assertProviderRangeAvailable(array $data, ?int $ignoreId = null): void
    {
        if (! $data['is_active']) {
            return;
        }

        $overlap = CommissionTier::query()
            ->where('company_id', $data['company_id'])
            ->where('feature', $data['feature'])
            ->where('is_active', true)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('amount_from', '<=', $data['amount_to'])
            ->where('amount_to', '>=', $data['amount_from'])
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'amount_from' => 'This provider and feature already has an active tier overlapping the amount range.',
            ]);
        }
    }

    private function assertTransferRangeAvailable(array $data, ?int $ignoreId = null): void
    {
        if (! $data['is_active']) {
            return;
        }

        $overlap = TransferFeeTier::query()
            ->where('company_from_id', $data['company_from_id'])
            ->where('company_to_id', $data['company_to_id'])
            ->where('is_active', true)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('amount_from', '<=', $data['amount_to'])
            ->where('amount_to', '>=', $data['amount_from'])
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'amount_from' => 'This provider route already has an active tier overlapping the amount range.',
            ]);
        }
    }
}
