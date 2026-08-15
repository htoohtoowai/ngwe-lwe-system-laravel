<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ConfirmActionModal from '@/components/bank/ConfirmActionModal.vue';
import BankLayout from '@/layouts/BankLayout.vue';
import type {
    AgentCommissionTier,
    CalculationType,
    FeeKind,
    FeeManagementProps,
    ProviderTier,
    TransferTier,
} from './fee-management';

const props = defineProps<FeeManagementProps>();

const search = ref('');
const status = ref<'all' | 'active' | 'inactive'>('all');
const sampleAmount = ref(100_000);
const busy = ref(false);
const errors = ref<Record<string, string>>({});
const pendingDelete = ref<
    | { kind: 'provider'; tier: ProviderTier }
    | { kind: 'agent'; tier: AgentCommissionTier }
    | { kind: 'transfer'; tier: TransferTier }
    | null
>(null);

const firstCompany = props.companies.find((company) => company.is_active)?.id ?? 0;
const firstAgentCompany = props.companies.find((company) => company.is_active && company.category !== 'Bank')?.id ?? 0;
const agentCompanies = computed(() => props.companies.filter((company) => company.category !== 'Bank'));
const secondCompany = props.companies.filter((company) => company.is_active)[1]?.id ?? 0;
const firstFeature = props.features[0]?.value ?? 'cash_in';

const providerForm = ref({
    company_id: firstCompany,
    feature: firstFeature,
    amount_from: 1,
    amount_to: 999_999_999,
    fee_type: 'FIXED' as CalculationType,
    fee_value: 0,
    additional_fee_type: 'FIXED' as CalculationType,
    additional_fee_value: 0,
    is_active: true,
});

const agentForm = ref({
    company_id: firstAgentCompany,
    amount_from: 1,
    amount_to: 999_999_999,
    commission_type: 'FIXED' as CalculationType,
    out_commission_value: 0,
    in_commission_value: 0,
    is_active: true,
});

const transferForm = ref({
    company_from_id: firstCompany,
    company_to_id: secondCompany,
    amount_from: 1,
    amount_to: 999_999_999,
    fee_type: 'FIXED' as CalculationType,
    fee_value: 0,
    additional_fee_type: 'FIXED' as CalculationType,
    additional_fee_value: 0,
    is_active: true,
});

const activeKind = computed<FeeKind>(() => props.initialKind);
const isEditor = computed(() => props.mode === 'create' || props.mode === 'edit');
const currentProvider = computed(() => props.providerTiers.find((tier) => tier.id === props.resourceId));
const currentAgent = computed(() => props.agentCommissionTiers.find((tier) => tier.id === props.resourceId));
const currentTransfer = computed(() => props.transferTiers.find((tier) => tier.id === props.resourceId));

watch(
    () => [props.mode, props.editorKind, props.resourceId] as const,
    () => {
        errors.value = {};

        if (props.editorKind === 'provider' && currentProvider.value) {
            const tier = currentProvider.value;
            providerForm.value = {
                company_id: tier.company_id,
                feature: tier.feature,
                amount_from: Number(tier.amount_from),
                amount_to: Number(tier.amount_to),
                fee_type: tier.fee_type,
                fee_value: Number(tier.fee_value),
                additional_fee_type: tier.additional_fee_type,
                additional_fee_value: Number(tier.additional_fee_value),
                is_active: tier.is_active,
            };
        }

        if (props.editorKind === 'agent' && currentAgent.value) {
            const tier = currentAgent.value;
            agentForm.value = {
                company_id: tier.company_id,
                amount_from: Number(tier.amount_from),
                amount_to: Number(tier.amount_to),
                commission_type: tier.commission_type,
                out_commission_value: Number(tier.out_commission_value),
                in_commission_value: Number(tier.in_commission_value),
                is_active: tier.is_active,
            };
        }

        if (props.editorKind === 'transfer' && currentTransfer.value) {
            const tier = currentTransfer.value;
            transferForm.value = {
                company_from_id: tier.company_from_id,
                company_to_id: tier.company_to_id,
                amount_from: Number(tier.amount_from),
                amount_to: Number(tier.amount_to),
                fee_type: tier.fee_type,
                fee_value: Number(tier.fee_value),
                additional_fee_type: tier.additional_fee_type,
                additional_fee_value: Number(tier.additional_fee_value),
                is_active: tier.is_active,
            };
        }
    },
    { immediate: true },
);

function featureLabel(value: string): string {
    return props.features.find((feature) => feature.value === value)?.label ?? value;
}

function money(value: string | number): string {
    return Number(value ?? 0).toLocaleString('en-US', { maximumFractionDigits: 4 });
}

function applyType(amount: number, value: number, type: CalculationType): number {
    return type === 'PERCENTAGE' ? amount * (value / 100) : value;
}

function roundMmkFee(value: number): number {
    if (value <= 0) return 0;
    const base = Math.floor(value / 100) * 100;
    const rounded = value - base <= 20 ? base : base + 100;
    return Math.max(rounded, 100);
}

function unit(type: CalculationType): string {
    return type === 'PERCENTAGE' ? '%' : 'MMK';
}

const providerPreview = computed(() => {
    const base = applyType(sampleAmount.value, providerForm.value.fee_value, providerForm.value.fee_type);
    const additional = applyType(
        sampleAmount.value,
        providerForm.value.additional_fee_value,
        providerForm.value.additional_fee_type,
    );
    return roundMmkFee(base + additional);
});

const agentOutPreview = computed(() =>
    applyType(sampleAmount.value, agentForm.value.out_commission_value, agentForm.value.commission_type),
);
const agentInPreview = computed(() =>
    applyType(sampleAmount.value, agentForm.value.in_commission_value, agentForm.value.commission_type),
);

const transferPreview = computed(() => {
    const base = applyType(sampleAmount.value, transferForm.value.fee_value, transferForm.value.fee_type);
    const additional = applyType(
        sampleAmount.value,
        transferForm.value.additional_fee_value,
        transferForm.value.additional_fee_type,
    );
    return roundMmkFee(base + additional);
});

const filteredProviderTiers = computed(() => filterRows(props.providerTiers, (tier) => [
    tier.company_name,
    featureLabel(tier.feature),
    tier.amount_from,
    tier.amount_to,
]));

const filteredAgentTiers = computed(() => filterRows(props.agentCommissionTiers, (tier) => [
    tier.company_name,
    tier.amount_from,
    tier.amount_to,
]));

const filteredTransferTiers = computed(() => filterRows(props.transferTiers, (tier) => [
    tier.company_from_name,
    tier.company_to_name,
    tier.amount_from,
    tier.amount_to,
]));

function filterRows<T extends { is_active: boolean }>(rows: T[], searchable: (row: T) => unknown[]): T[] {
    const query = search.value.trim().toLowerCase();
    return rows.filter((row) => {
        const statusMatches = status.value === 'all' || (status.value === 'active' ? row.is_active : !row.is_active);
        const searchMatches = query === '' || searchable(row).some((value) => String(value ?? '').toLowerCase().includes(query));
        return statusMatches && searchMatches;
    });
}

function submit(kind: FeeKind): void {
    busy.value = true;
    errors.value = {};

    const config = kind === 'provider'
        ? { form: providerForm.value, base: '/admin/fees/provider' }
        : kind === 'agent'
          ? { form: agentForm.value, base: '/admin/fees/agent' }
          : { form: transferForm.value, base: '/admin/fees/transfer' };

    const url = props.mode === 'edit' && props.resourceId ? `${config.base}/${props.resourceId}` : config.base;
    const method = props.mode === 'edit' ? 'put' : 'post';

    router[method](url, config.form, {
        preserveScroll: true,
        onError: (bag) => {
            errors.value = Object.fromEntries(Object.entries(bag).map(([key, value]) => [key, String(value)]));
        },
        onFinish: () => (busy.value = false),
    });
}

function requestDelete(kind: FeeKind, tier: ProviderTier | AgentCommissionTier | TransferTier): void {
    if (kind === 'provider') {
        pendingDelete.value = { kind, tier: tier as ProviderTier };
        return;
    }

    if (kind === 'agent') {
        pendingDelete.value = { kind, tier: tier as AgentCommissionTier };
        return;
    }

    pendingDelete.value = { kind, tier: tier as TransferTier };
}

function confirmDelete(): void {
    const pending = pendingDelete.value;
    if (!pending) return;
    const base = pending.kind === 'provider'
        ? '/admin/fees/provider'
        : pending.kind === 'agent'
          ? '/admin/fees/agent'
          : '/admin/fees/transfer';

    busy.value = true;
    router.delete(`${base}/${pending.tier.id}`, {
        preserveScroll: true,
        onSuccess: () => (pendingDelete.value = null),
        onFinish: () => (busy.value = false),
    });
}

const heading = computed(() => activeKind.value === 'provider'
    ? 'Provider customer fees'
    : activeKind.value === 'agent'
      ? 'Agent commissions'
      : 'Transfer fees');

const description = computed(() => activeKind.value === 'provider'
    ? 'Customer fee rules by provider, feature and amount range.'
    : activeKind.value === 'agent'
      ? 'Agent earning rules by provider and amount range. OUT / Send and IN / Receive values share one calculation type.'
      : 'Customer fee rules by source provider, destination provider and amount range.');

const createHref = computed(() => `/admin/fees/${activeKind.value}/create`);
const listHref = computed(() => `/admin/fees/${activeKind.value}`);

const deleteTitle = computed(() => activeKind.value === 'agent' ? 'Delete agent commission tier?' : 'Delete fee tier?');
const deleteDescription = computed(() => 'This tier will be permanently deleted. Existing transaction snapshots remain unchanged.');
</script>

<template>
    <BankLayout :role="role" :announcement="announcement" :notification-count="notificationCount">
        <header class="flex flex-col gap-3 border-b border-line pb-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-black text-brand uppercase">Owner Console / Pricing Rules</p>
                <h1 class="mt-1 text-2xl font-black text-ink sm:text-3xl">{{ heading }}</h1>
                <p class="mt-1 text-sm text-slate">{{ description }}</p>
            </div>
            <Link v-if="!isEditor && mode !== 'detail'" :href="createHref" class="bank-button bank-button-primary w-full sm:w-auto">
                Add tier
            </Link>
        </header>

        <nav class="mt-4 flex flex-wrap gap-2">
            <Link href="/admin/fees/provider" class="bank-button" :class="activeKind === 'provider' ? 'bank-button-primary' : 'bank-button-secondary'">Provider Fees</Link>
            <Link href="/admin/fees/agent" class="bank-button" :class="activeKind === 'agent' ? 'bank-button-primary' : 'bank-button-secondary'">Agent Commissions</Link>
            <Link href="/admin/fees/transfer" class="bank-button" :class="activeKind === 'transfer' ? 'bank-button-primary' : 'bank-button-secondary'">Transfer Fees</Link>
        </nav>

        <div v-if="Object.keys(errors).length" class="mt-4 rounded-field border border-brand/20 bg-brand-soft px-3 py-2 text-sm font-bold text-brand" role="alert">
            {{ Object.values(errors)[0] }}
        </div>

        <form v-if="isEditor && activeKind === 'provider'" class="bank-form-shell mt-4 max-w-5xl" @submit.prevent="submit('provider')">
            <div class="flex items-start justify-between gap-4 border-b border-line pb-4">
                <div>
                    <h2 class="text-lg font-black">{{ mode === 'edit' ? 'Edit provider fee tier' : 'New provider fee tier' }}</h2>
                    <p class="mt-1 text-xs text-slate">Cash In, Cash Out, Send Money and Receive Money fees are separate feature rows.</p>
                </div>
                <Link :href="listHref" class="bank-button bank-button-secondary px-4 py-2">Cancel</Link>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <label><span class="bank-label bank-required">Provider</span><select v-model.number="providerForm.company_id" class="bank-input min-h-13" required><option v-for="company in companies" :key="company.id" :value="company.id" :disabled="!company.is_active">{{ company.name }}{{ company.is_active ? '' : ' (Inactive)' }}</option></select></label>
                <label><span class="bank-label bank-required">Feature</span><select v-model="providerForm.feature" class="bank-input min-h-13" required><option v-for="feature in features" :key="feature.value" :value="feature.value">{{ feature.label }}</option></select></label>
                <label><span class="bank-label bank-required">Amount from</span><input v-model.number="providerForm.amount_from" type="number" min="0" step="0.01" class="bank-input money min-h-13" required /></label>
                <label><span class="bank-label bank-required">Amount to</span><input v-model.number="providerForm.amount_to" type="number" min="0.01" step="0.01" class="bank-input money min-h-13" required /></label>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <fieldset>
                    <legend class="bank-label bank-required">Customer fee</legend>
                    <div class="grid grid-cols-[8rem_minmax(0,1fr)_auto] items-center gap-2">
                        <select v-model="providerForm.fee_type" class="bank-input min-h-13"><option v-for="type in calculationTypes" :key="type.value" :value="type.value">{{ type.value }}</option></select>
                        <input v-model.number="providerForm.fee_value" type="number" min="0" step="0.0001" class="bank-input money min-h-13" required />
                        <span class="text-xs font-black text-slate">{{ unit(providerForm.fee_type) }}</span>
                    </div>
                </fieldset>
                <fieldset>
                    <legend class="bank-label bank-required">Additional fee</legend>
                    <div class="grid grid-cols-[8rem_minmax(0,1fr)_auto] items-center gap-2">
                        <select v-model="providerForm.additional_fee_type" class="bank-input min-h-13"><option v-for="type in calculationTypes" :key="type.value" :value="type.value">{{ type.value }}</option></select>
                        <input v-model.number="providerForm.additional_fee_value" type="number" min="0" step="0.0001" class="bank-input money min-h-13" required />
                        <span class="text-xs font-black text-slate">{{ unit(providerForm.additional_fee_type) }}</span>
                    </div>
                </fieldset>
            </div>

            <div class="mt-5 grid gap-3 border-t border-line pt-4 md:grid-cols-[minmax(12rem,1fr)_minmax(0,2fr)_auto] md:items-end">
                <label><span class="bank-label">Preview amount</span><input v-model.number="sampleAmount" type="number" min="1" step="1" class="bank-input money min-h-13" /></label>
                <div class="flex min-h-13 items-center justify-between rounded-field bg-mist px-4 py-2"><span class="text-xs font-bold text-slate">Rounded customer fee</span><strong class="money text-lg text-ink">{{ money(providerPreview) }} MMK</strong></div>
                <label class="flex min-h-13 items-center gap-2 rounded-field border border-line px-4 text-sm font-bold"><input v-model="providerForm.is_active" type="checkbox" class="size-4 accent-brand" /> Active</label>
            </div>
            <div class="mt-5 flex justify-end"><button type="submit" :disabled="busy" class="bank-button bank-button-primary">{{ busy ? 'Saving...' : 'Save fee tier' }}</button></div>
        </form>

        <form v-else-if="isEditor && activeKind === 'agent'" class="bank-form-shell mt-4 max-w-5xl" @submit.prevent="submit('agent')">
            <div class="flex items-start justify-between gap-4 border-b border-line pb-4">
                <div>
                    <h2 class="text-lg font-black">{{ mode === 'edit' ? 'Edit agent commission tier' : 'New agent commission tier' }}</h2>
                    <p class="mt-1 text-xs text-slate">Configure PAY agent earnings by account money movement. OUT / Send and IN / Receive share one calculation type for each amount range.</p>
                </div>
                <Link :href="listHref" class="bank-button bank-button-secondary px-4 py-2">Cancel</Link>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <label><span class="bank-label bank-required">Provider</span><select v-model.number="agentForm.company_id" class="bank-input min-h-13" required><option v-for="company in agentCompanies" :key="company.id" :value="company.id" :disabled="!company.is_active">{{ company.name }}{{ company.is_active ? '' : ' (Inactive)' }}</option></select></label>
                <label><span class="bank-label bank-required">Amount from</span><input v-model.number="agentForm.amount_from" type="number" min="0" step="0.01" class="bank-input money min-h-13" required /></label>
                <label><span class="bank-label bank-required">Amount to</span><input v-model.number="agentForm.amount_to" type="number" min="0.01" step="0.01" class="bank-input money min-h-13" required /></label>
            </div>

            <fieldset class="mt-4">
                <legend class="bank-label bank-required">Agent commission</legend>
                <div class="grid gap-3 md:grid-cols-[10rem_minmax(0,1fr)_minmax(0,1fr)]">
                    <label><span class="bank-label bank-required">Type</span><select v-model="agentForm.commission_type" class="bank-input min-h-13"><option v-for="type in calculationTypes" :key="type.value" :value="type.value">{{ type.value }}</option></select></label>
                    <label><span class="bank-label bank-required">OUT / Send</span><div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2"><input v-model.number="agentForm.out_commission_value" type="number" min="0" step="0.0001" class="bank-input money min-h-13" required /><span class="text-xs font-black text-slate">{{ unit(agentForm.commission_type) }}</span></div></label>
                    <label><span class="bank-label bank-required">IN / Receive</span><div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2"><input v-model.number="agentForm.in_commission_value" type="number" min="0" step="0.0001" class="bank-input money min-h-13" required /><span class="text-xs font-black text-slate">{{ unit(agentForm.commission_type) }}</span></div></label>
                </div>
            </fieldset>

            <div class="mt-5 grid gap-3 border-t border-line pt-4 md:grid-cols-[minmax(12rem,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
                <label><span class="bank-label">Preview amount</span><input v-model.number="sampleAmount" type="number" min="1" step="1" class="bank-input money min-h-13" /></label>
                <div class="flex min-h-13 items-center justify-between rounded-field bg-mist px-4 py-2"><span class="text-xs font-bold text-slate">OUT earns</span><strong class="money text-lg text-balance">+{{ money(agentOutPreview) }} MMK</strong></div>
                <div class="flex min-h-13 items-center justify-between rounded-field bg-mist px-4 py-2"><span class="text-xs font-bold text-slate">IN earns</span><strong class="money text-lg text-balance">+{{ money(agentInPreview) }} MMK</strong></div>
                <label class="flex min-h-13 items-center gap-2 rounded-field border border-line px-4 text-sm font-bold"><input v-model="agentForm.is_active" type="checkbox" class="size-4 accent-brand" /> Active</label>
            </div>
            <div class="mt-5 flex justify-end"><button type="submit" :disabled="busy" class="bank-button bank-button-primary">{{ busy ? 'Saving...' : 'Save commission tier' }}</button></div>
        </form>

        <form v-else-if="isEditor && activeKind === 'transfer'" class="bank-form-shell mt-4 max-w-5xl" @submit.prevent="submit('transfer')">
            <div class="flex items-start justify-between gap-4 border-b border-line pb-4">
                <div><h2 class="text-lg font-black">{{ mode === 'edit' ? 'Edit transfer fee tier' : 'New transfer fee tier' }}</h2><p class="mt-1 text-xs text-slate">Transfer customer fee is route-based. Agent commissions are configured separately under Agent Commissions.</p></div>
                <Link :href="listHref" class="bank-button bank-button-secondary px-4 py-2">Cancel</Link>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <label><span class="bank-label bank-required">From provider</span><select v-model.number="transferForm.company_from_id" class="bank-input min-h-13" required><option v-for="company in companies" :key="company.id" :value="company.id" :disabled="!company.is_active">{{ company.name }}</option></select></label>
                <label><span class="bank-label bank-required">To provider</span><select v-model.number="transferForm.company_to_id" class="bank-input min-h-13" required><option v-for="company in companies" :key="company.id" :value="company.id" :disabled="!company.is_active || company.id === transferForm.company_from_id">{{ company.name }}</option></select></label>
                <label><span class="bank-label bank-required">Amount from</span><input v-model.number="transferForm.amount_from" type="number" min="0" step="0.01" class="bank-input money min-h-13" required /></label>
                <label><span class="bank-label bank-required">Amount to</span><input v-model.number="transferForm.amount_to" type="number" min="0.01" step="0.01" class="bank-input money min-h-13" required /></label>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <fieldset><legend class="bank-label bank-required">Transfer fee</legend><div class="grid grid-cols-[8rem_minmax(0,1fr)_auto] items-center gap-2"><select v-model="transferForm.fee_type" class="bank-input min-h-13"><option v-for="type in calculationTypes" :key="type.value" :value="type.value">{{ type.value }}</option></select><input v-model.number="transferForm.fee_value" type="number" min="0" step="0.0001" class="bank-input money min-h-13" required /><span class="text-xs font-black text-slate">{{ unit(transferForm.fee_type) }}</span></div></fieldset>
                <fieldset><legend class="bank-label bank-required">Additional fee</legend><div class="grid grid-cols-[8rem_minmax(0,1fr)_auto] items-center gap-2"><select v-model="transferForm.additional_fee_type" class="bank-input min-h-13"><option v-for="type in calculationTypes" :key="type.value" :value="type.value">{{ type.value }}</option></select><input v-model.number="transferForm.additional_fee_value" type="number" min="0" step="0.0001" class="bank-input money min-h-13" required /><span class="text-xs font-black text-slate">{{ unit(transferForm.additional_fee_type) }}</span></div></fieldset>
            </div>

            <div class="mt-5 grid gap-3 border-t border-line pt-4 md:grid-cols-[minmax(12rem,1fr)_minmax(0,2fr)_auto] md:items-end">
                <label><span class="bank-label">Preview amount</span><input v-model.number="sampleAmount" type="number" min="1" step="1" class="bank-input money min-h-13" /></label>
                <div class="flex min-h-13 items-center justify-between rounded-field bg-mist px-4 py-2"><span class="text-xs font-bold text-slate">Rounded customer fee</span><strong class="money text-lg text-ink">{{ money(transferPreview) }} MMK</strong></div>
                <label class="flex min-h-13 items-center gap-2 rounded-field border border-line px-4 text-sm font-bold"><input v-model="transferForm.is_active" type="checkbox" class="size-4 accent-brand" /> Active</label>
            </div>
            <div class="mt-5 flex justify-end"><button type="submit" :disabled="busy" class="bank-button bank-button-primary">{{ busy ? 'Saving...' : 'Save transfer tier' }}</button></div>
        </form>

        <section v-else-if="mode === 'detail'" class="bank-form-shell mt-4 max-w-4xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-black text-brand uppercase">Tier detail</p>
                    <h2 class="mt-1 text-xl font-black">
                        <template v-if="activeKind === 'provider' && currentProvider">{{ currentProvider.company_name }} / {{ featureLabel(currentProvider.feature) }}</template>
                        <template v-else-if="activeKind === 'agent' && currentAgent">{{ currentAgent.company_name }}</template>
                        <template v-else-if="currentTransfer">{{ currentTransfer.company_from_name }} → {{ currentTransfer.company_to_name }}</template>
                    </h2>
                </div>
                <Link :href="`${listHref}/${resourceId}/edit`" class="bank-button bank-button-primary">Edit</Link>
            </div>
        </section>

        <section v-else class="mt-4">
            <div class="grid gap-2 md:grid-cols-[minmax(0,1fr)_12rem]">
                <input v-model="search" type="search" class="bank-input min-h-12" placeholder="Search provider, feature, route or amount" />
                <select v-model="status" class="bank-input min-h-12 py-2"><option value="all">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
            </div>

            <div v-if="activeKind === 'provider'" class="mt-3 overflow-x-auto rounded-field border border-line bg-card">
                <table class="w-full min-w-[900px] text-left text-sm"><thead class="bg-mist text-xs text-slate uppercase"><tr><th class="px-4 py-3">Provider / Feature</th><th class="px-4 py-3">Amount range</th><th class="px-4 py-3">Customer fee</th><th class="px-4 py-3">Additional fee</th><th class="px-4 py-3 text-right">Actions</th></tr></thead><tbody class="divide-y divide-line"><tr v-for="tier in filteredProviderTiers" :key="tier.id"><td class="px-4 py-3"><p class="font-black">{{ tier.company_name }}</p><p class="text-xs font-bold text-slate">{{ featureLabel(tier.feature) }} · {{ tier.is_active ? 'Active' : 'Inactive' }}</p></td><td class="money px-4 py-3">{{ money(tier.amount_from) }} - {{ money(tier.amount_to) }}</td><td class="money px-4 py-3">{{ tier.fee_type }} · {{ money(tier.fee_value) }} {{ unit(tier.fee_type) }}</td><td class="money px-4 py-3">{{ tier.additional_fee_type }} · {{ money(tier.additional_fee_value) }} {{ unit(tier.additional_fee_type) }}</td><td class="px-4 py-3"><div class="flex justify-end gap-2"><Link :href="`/admin/fees/provider/${tier.id}/edit`" class="bank-button bank-button-secondary min-h-9 px-3 py-1.5">Edit</Link><button type="button" class="bank-button bank-button-danger min-h-9 px-3 py-1.5" @click="requestDelete('provider', tier)">Delete</button></div></td></tr></tbody></table>
            </div>

            <div v-else-if="activeKind === 'agent'" class="mt-3 overflow-x-auto rounded-field border border-line bg-card">
                <table class="w-full min-w-[900px] text-left text-sm"><thead class="bg-mist text-xs text-slate uppercase"><tr><th class="px-4 py-3">Provider</th><th class="px-4 py-3">Amount range</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">OUT / Send</th><th class="px-4 py-3">IN / Receive</th><th class="px-4 py-3 text-right">Actions</th></tr></thead><tbody class="divide-y divide-line"><tr v-for="tier in filteredAgentTiers" :key="tier.id"><td class="px-4 py-3"><p class="font-black">{{ tier.company_name }}</p><p class="text-xs font-bold text-slate">{{ tier.is_active ? 'Active' : 'Inactive' }}</p></td><td class="money px-4 py-3">{{ money(tier.amount_from) }} - {{ money(tier.amount_to) }}</td><td class="px-4 py-3 font-bold">{{ tier.commission_type }}</td><td class="money px-4 py-3">{{ money(tier.out_commission_value) }} {{ unit(tier.commission_type) }}</td><td class="money px-4 py-3">{{ money(tier.in_commission_value) }} {{ unit(tier.commission_type) }}</td><td class="px-4 py-3"><div class="flex justify-end gap-2"><Link :href="`/admin/fees/agent/${tier.id}/edit`" class="bank-button bank-button-secondary min-h-9 px-3 py-1.5">Edit</Link><button type="button" class="bank-button bank-button-danger min-h-9 px-3 py-1.5" @click="requestDelete('agent', tier)">Delete</button></div></td></tr></tbody></table>
            </div>

            <div v-else class="mt-3 overflow-x-auto rounded-field border border-line bg-card">
                <table class="w-full min-w-[900px] text-left text-sm"><thead class="bg-mist text-xs text-slate uppercase"><tr><th class="px-4 py-3">Route</th><th class="px-4 py-3">Amount range</th><th class="px-4 py-3">Transfer fee</th><th class="px-4 py-3">Additional fee</th><th class="px-4 py-3 text-right">Actions</th></tr></thead><tbody class="divide-y divide-line"><tr v-for="tier in filteredTransferTiers" :key="tier.id"><td class="px-4 py-3"><p class="font-black">{{ tier.company_from_name }} → {{ tier.company_to_name }}</p><p class="text-xs font-bold text-slate">{{ tier.is_active ? 'Active' : 'Inactive' }}</p></td><td class="money px-4 py-3">{{ money(tier.amount_from) }} - {{ money(tier.amount_to) }}</td><td class="money px-4 py-3">{{ tier.fee_type }} · {{ money(tier.fee_value) }} {{ unit(tier.fee_type) }}</td><td class="money px-4 py-3">{{ tier.additional_fee_type }} · {{ money(tier.additional_fee_value) }} {{ unit(tier.additional_fee_type) }}</td><td class="px-4 py-3"><div class="flex justify-end gap-2"><Link :href="`/admin/fees/transfer/${tier.id}/edit`" class="bank-button bank-button-secondary min-h-9 px-3 py-1.5">Edit</Link><button type="button" class="bank-button bank-button-danger min-h-9 px-3 py-1.5" @click="requestDelete('transfer', tier)">Delete</button></div></td></tr></tbody></table>
            </div>
        </section>

        <ConfirmActionModal :open="pendingDelete !== null" :title="deleteTitle" :description="deleteDescription" :busy="busy" @cancel="pendingDelete = null" @confirm="confirmDelete" />
    </BankLayout>
</template>
