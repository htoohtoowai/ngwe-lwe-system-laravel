<?php

namespace App\Http\Controllers;

use App\Enums\AccountFeature;
use App\Enums\CalculationType;
use App\Http\Requests\AgentCommissionTierRequest;
use App\Http\Requests\ProviderFeeTierRequest;
use App\Http\Requests\TransferFeeTierRequest;
use App\Models\AgentCommissionTier;
use App\Models\Company;
use App\Models\ProviderFeeTier;
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
        return $this->render($request, editorKind: 'provider');
    }

    public function provider(Request $request): Response
    {
        return $this->render($request, editorKind: 'provider');
    }

    public function agent(Request $request): Response
    {
        return $this->render($request, editorKind: 'agent');
    }

    public function transfer(Request $request): Response
    {
        return $this->render($request, editorKind: 'transfer');
    }

    public function createProvider(Request $request): Response
    {
        return $this->render($request, 'create', 'provider');
    }

    public function showProvider(Request $request, ProviderFeeTier $providerFeeTier): Response
    {
        return $this->render($request, 'detail', 'provider', $providerFeeTier->id);
    }

    public function editProvider(Request $request, ProviderFeeTier $providerFeeTier): Response
    {
        return $this->render($request, 'edit', 'provider', $providerFeeTier->id);
    }

    public function createAgent(Request $request): Response
    {
        return $this->render($request, 'create', 'agent');
    }

    public function showAgent(Request $request, AgentCommissionTier $agentCommissionTier): Response
    {
        return $this->render($request, 'detail', 'agent', $agentCommissionTier->id);
    }

    public function editAgent(Request $request, AgentCommissionTier $agentCommissionTier): Response
    {
        return $this->render($request, 'edit', 'agent', $agentCommissionTier->id);
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
        ProviderFeeTier::query()->create($data);

        return redirect('/admin/fees/provider');
    }

    public function updateProvider(ProviderFeeTierRequest $request, ProviderFeeTier $providerFeeTier): RedirectResponse
    {
        $data = $request->validated();
        $this->assertProviderRangeAvailable($data, $providerFeeTier->id);
        $providerFeeTier->fill($data)->save();

        return redirect('/admin/fees/provider');
    }

    public function destroyProvider(ProviderFeeTier $providerFeeTier): RedirectResponse
    {
        $providerFeeTier->delete();

        return redirect('/admin/fees/provider');
    }

    public function storeAgent(AgentCommissionTierRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->assertAgentRangeAvailable($data);
        AgentCommissionTier::query()->create($data);

        return redirect('/admin/fees/agent');
    }

    public function updateAgent(AgentCommissionTierRequest $request, AgentCommissionTier $agentCommissionTier): RedirectResponse
    {
        $data = $request->validated();
        $this->assertAgentRangeAvailable($data, $agentCommissionTier->id);
        $agentCommissionTier->fill($data)->save();

        return redirect('/admin/fees/agent');
    }

    public function destroyAgent(AgentCommissionTier $agentCommissionTier): RedirectResponse
    {
        $agentCommissionTier->delete();

        return redirect('/admin/fees/agent');
    }

    public function storeTransfer(TransferFeeTierRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->assertTransferRangeAvailable($data);
        TransferFeeTier::query()->create($data);

        return redirect('/admin/fees/transfer');
    }

    public function updateTransfer(TransferFeeTierRequest $request, TransferFeeTier $transferFeeTier): RedirectResponse
    {
        $data = $request->validated();
        $this->assertTransferRangeAvailable($data, $transferFeeTier->id);
        $transferFeeTier->fill($data)->save();

        return redirect('/admin/fees/transfer');
    }

    public function destroyTransfer(TransferFeeTier $transferFeeTier): RedirectResponse
    {
        $transferFeeTier->delete();

        return redirect('/admin/fees/transfer');
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
            'announcement' => 'Configure provider customer fees, agent commissions and transfer route fees independently.',
            'notificationCount' => Transaction::query()
                ->whereIn('transaction_type', ['cash_in', 'send_money'])
                ->where('status', 'PENDING_CASHIER_CONFIRM')
                ->count(),
            'mode' => $mode,
            'editorKind' => $editorKind,
            'resourceId' => $resourceId,
            'initialKind' => $editorKind,
            'calculationTypes' => collect(CalculationType::cases())->map(fn (CalculationType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ])->all(),
            'features' => collect($features)->map(fn (AccountFeature $feature): array => [
                'value' => $feature->value,
                'label' => $feature->label(),
            ])->all(),
            'companies' => Company::query()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name', 'logo_path', 'category', 'is_active'])
                ->map(fn (Company $company): array => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'logo_url' => $company->logo_path ? route('companies.logo', $company) : null,
                    'category' => $company->category,
                    'is_active' => (bool) $company->is_active,
                ])->all(),
            'providerTiers' => ProviderFeeTier::query()
                ->with('company:id,name')
                ->whereIn('feature', collect($features)->map->value->all())
                ->orderBy('company_id')
                ->orderBy('amount_from')
                ->get()
                ->map(fn (ProviderFeeTier $tier): array => $this->providerTierData($tier))
                ->all(),
            'agentCommissionTiers' => AgentCommissionTier::query()
                ->with('company:id,name')
                ->orderBy('company_id')
                ->orderBy('amount_from')
                ->get()
                ->map(fn (AgentCommissionTier $tier): array => $this->agentTierData($tier))
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

    private function providerTierData(ProviderFeeTier $tier): array
    {
        return [
            'id' => $tier->id,
            'company_id' => $tier->company_id,
            'company_name' => $tier->company?->name,
            'feature' => $tier->feature,
            'amount_from' => $tier->amount_from,
            'amount_to' => $tier->amount_to,
            'fee_type' => $tier->fee_type->value,
            'fee_value' => $tier->fee_value,
            'additional_fee_type' => $tier->additional_fee_type->value,
            'additional_fee_value' => $tier->additional_fee_value,
            'is_active' => (bool) $tier->is_active,
        ];
    }

    private function agentTierData(AgentCommissionTier $tier): array
    {
        return [
            'id' => $tier->id,
            'company_id' => $tier->company_id,
            'company_name' => $tier->company?->name,
            'amount_from' => $tier->amount_from,
            'amount_to' => $tier->amount_to,
            'commission_type' => $tier->commission_type->value,
            'out_commission_value' => $tier->out_commission_value,
            'in_commission_value' => $tier->in_commission_value,
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
            'fee_type' => $tier->fee_type->value,
            'fee_value' => $tier->fee_value,
            'additional_fee_type' => $tier->additional_fee_type->value,
            'additional_fee_value' => $tier->additional_fee_value,
            'is_active' => (bool) $tier->is_active,
        ];
    }

    private function assertProviderRangeAvailable(array $data, ?int $ignoreId = null): void
    {
        $this->assertRangeAvailable(
            ProviderFeeTier::query(),
            $data,
            $ignoreId,
            'This provider and feature already has an active customer fee tier overlapping the amount range.',
        );
    }

    private function assertAgentRangeAvailable(array $data, ?int $ignoreId = null): void
    {
        if (! $data['is_active']) {
            return;
        }

        $overlap = AgentCommissionTier::query()
            ->where('company_id', $data['company_id'])
            ->where('is_active', true)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('amount_from', '<=', $data['amount_to'])
            ->where('amount_to', '>=', $data['amount_from'])
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'amount_from' => 'This provider already has an active agent commission tier overlapping the amount range.',
            ]);
        }
    }

    private function assertRangeAvailable($query, array $data, ?int $ignoreId, string $message): void
    {
        if (! $data['is_active']) {
            return;
        }

        $overlap = $query
            ->where('company_id', $data['company_id'])
            ->where('feature', $data['feature'])
            ->where('is_active', true)
            ->when($ignoreId, fn ($builder) => $builder->where('id', '!=', $ignoreId))
            ->where('amount_from', '<=', $data['amount_to'])
            ->where('amount_to', '>=', $data['amount_from'])
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['amount_from' => $message]);
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
                'amount_from' => 'This provider route already has an active transfer fee tier overlapping the amount range.',
            ]);
        }
    }
}
