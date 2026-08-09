<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BankLayout from '@/layouts/BankLayout.vue';
import { readStoredToken } from '@/lib/auth-token';

type FeeKind = 'provider' | 'transfer';
type FeeType = 'FIXED' | 'PERCENTAGE';
type PageMode = 'list' | 'create' | 'edit' | 'detail';
type Company = {
    id: number;
    name: string;
    logo_url: string | null;
    is_active: boolean;
};
type Feature = { value: string; label: string };
type ProviderTier = {
    id: number;
    company_id: number;
    company_name: string;
    feature: string;
    amount_from: string;
    amount_to: string;
    fee_type: FeeType;
    fee_amount: string;
    additional_fee_type: FeeType;
    additional_fee_amount: string;
    comm_type: FeeType;
    comm_amount: string;
    is_active: boolean;
};
type TransferTier = {
    id: number;
    company_from_id: number;
    company_from_name: string;
    company_to_id: number;
    company_to_name: string;
    amount_from: string;
    amount_to: string;
    fee_type: FeeType;
    fee_amount: string;
    additional_fee_type: FeeType;
    additional_fee_amount: string;
    is_active: boolean;
};

const props = defineProps<{
    role: 'admin';
    announcement?: string | null;
    notificationCount?: number;
    mode: PageMode;
    editorKind: FeeKind;
    initialKind: FeeKind;
    resourceId?: number | null;
    companies: Company[];
    features: Feature[];
    providerTiers: ProviderTier[];
    transferTiers: TransferTier[];
}>();

const activeKind = ref<FeeKind>(props.initialKind);
const search = ref('');
const status = ref<'all' | 'active' | 'inactive'>('all');
const page = ref(1);
const pageSize = 10;
const sampleAmount = ref(100_000);
const busy = ref(false);
const errors = ref<Record<string, string>>({});
const failedLogos = ref(new Set<number>());

const providerForm = ref({
    company_id: props.companies.find((company) => company.is_active)?.id ?? 0,
    feature: props.features[0]?.value ?? 'cash_in',
    amount_from: 1,
    amount_to: 999_999_999,
    fee_type: 'FIXED' as FeeType,
    fee_amount: 0,
    additional_fee_type: 'FIXED' as FeeType,
    additional_fee_amount: 0,
    comm_type: 'FIXED' as FeeType,
    comm_amount: 0,
    is_active: true,
});
const transferForm = ref({
    company_from_id:
        props.companies.find((company) => company.is_active)?.id ?? 0,
    company_to_id:
        props.companies.filter((company) => company.is_active)[1]?.id ?? 0,
    amount_from: 1,
    amount_to: 999_999_999,
    fee_type: 'FIXED' as FeeType,
    fee_amount: 0,
    additional_fee_type: 'FIXED' as FeeType,
    additional_fee_amount: 0,
    is_active: true,
});

const isEditor = computed(() => ['create', 'edit'].includes(props.mode));
const currentProvider = computed(() =>
    props.providerTiers.find((tier) => tier.id === props.resourceId),
);
const currentTransfer = computed(() =>
    props.transferTiers.find((tier) => tier.id === props.resourceId),
);
const selectedProviderCompany = computed(() =>
    props.companies.find(
        (company) => company.id === providerForm.value.company_id,
    ),
);

watch(
    () => [props.mode, props.editorKind, props.resourceId] as const,
    () => {
        activeKind.value = props.initialKind;
        errors.value = {};

        if (props.editorKind === 'provider' && currentProvider.value) {
            const tier = currentProvider.value;
            providerForm.value = {
                company_id: tier.company_id,
                feature: tier.feature,
                amount_from: Number(tier.amount_from),
                amount_to: Number(tier.amount_to),
                fee_type: tier.fee_type,
                fee_amount: Number(tier.fee_amount),
                additional_fee_type: tier.additional_fee_type,
                additional_fee_amount: Number(tier.additional_fee_amount),
                comm_type: tier.comm_type,
                comm_amount: Number(tier.comm_amount),
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
                fee_amount: Number(tier.fee_amount),
                additional_fee_type: tier.additional_fee_type,
                additional_fee_amount: Number(tier.additional_fee_amount),
                is_active: tier.is_active,
            };
        }
    },
    { immediate: true },
);

const filteredProviderTiers = computed(() => {
    const query = search.value.trim().toLowerCase();

    return props.providerTiers.filter((tier) => {
        const matchesStatus =
            status.value === 'all' ||
            (status.value === 'active' ? tier.is_active : !tier.is_active);
        const feature = featureLabel(tier.feature).toLowerCase();
        const matchesSearch =
            query === '' ||
            [tier.company_name, feature, tier.amount_from, tier.amount_to].some(
                (value) =>
                    String(value ?? '')
                        .toLowerCase()
                        .includes(query),
            );

        return matchesStatus && matchesSearch;
    });
});

const filteredTransferTiers = computed(() => {
    const query = search.value.trim().toLowerCase();

    return props.transferTiers.filter((tier) => {
        const matchesStatus =
            status.value === 'all' ||
            (status.value === 'active' ? tier.is_active : !tier.is_active);
        const matchesSearch =
            query === '' ||
            [
                tier.company_from_name,
                tier.company_to_name,
                tier.amount_from,
                tier.amount_to,
            ].some((value) =>
                String(value ?? '')
                    .toLowerCase()
                    .includes(query),
            );

        return matchesStatus && matchesSearch;
    });
});

const activeTotal = computed(() =>
    activeKind.value === 'provider'
        ? filteredProviderTiers.value.length
        : filteredTransferTiers.value.length,
);
const pageCount = computed(() =>
    Math.max(1, Math.ceil(activeTotal.value / pageSize)),
);
const paginatedProviderTiers = computed(() => {
    const start = (page.value - 1) * pageSize;

    return filteredProviderTiers.value.slice(start, start + pageSize);
});
const paginatedTransferTiers = computed(() => {
    const start = (page.value - 1) * pageSize;

    return filteredTransferTiers.value.slice(start, start + pageSize);
});

watch([activeKind, search, status], () => (page.value = 1));
watch(pageCount, (count) => {
    if (page.value > count) page.value = count;
});

const providerPreview = computed(() => {
    const fee = applyType(
        sampleAmount.value,
        providerForm.value.fee_amount,
        providerForm.value.fee_type,
    );
    const additional = applyType(
        sampleAmount.value,
        providerForm.value.additional_fee_amount,
        providerForm.value.additional_fee_type,
    );
    const commission = applyType(
        sampleAmount.value,
        providerForm.value.comm_amount,
        providerForm.value.comm_type,
    );

    return { customerFee: roundMmkFee(fee + additional), commission };
});

const transferPreview = computed(() => {
    const fee = applyType(
        sampleAmount.value,
        transferForm.value.fee_amount,
        transferForm.value.fee_type,
    );
    const additional = applyType(
        sampleAmount.value,
        transferForm.value.additional_fee_amount,
        transferForm.value.additional_fee_type,
    );

    return roundMmkFee(fee + additional);
});

function authHeaders(): Record<string, string> {
    const token = readStoredToken();

    return token ? { Authorization: `Bearer ${token}` } : {};
}

function featureLabel(value: string): string {
    return (
        props.features.find((feature) => feature.value === value)?.label ??
        value
    );
}

function money(value: string | number): string {
    return Number(value ?? 0).toLocaleString('en-US', {
        maximumFractionDigits: 4,
    });
}

function applyType(amount: number, value: number, type: FeeType): number {
    return type === 'PERCENTAGE' ? amount * (value / 100) : value;
}

function roundMmkFee(value: number): number {
    if (value <= 0) return 0;
    const base = Math.floor(value / 100) * 100;
    const fee = value - base <= 20 ? base : base + 100;

    return Math.max(fee, 100);
}

function chooseKind(kind: FeeKind): void {
    if (isEditor.value || props.mode === 'detail') {
        router.visit(`/admin/fees?kind=${kind}`, { headers: authHeaders() });
        return;
    }

    activeKind.value = kind;
    search.value = '';
    status.value = 'all';
    page.value = 1;
}

function submitProvider(): void {
    busy.value = true;
    errors.value = {};
    const url =
        props.mode === 'edit' && props.resourceId
            ? `/admin/fees/provider/${props.resourceId}`
            : '/admin/fees/provider';
    const method = props.mode === 'edit' ? 'put' : 'post';

    router[method](url, providerForm.value, {
        headers: authHeaders(),
        preserveScroll: true,
        onError: (errorBag) => {
            errors.value = Object.fromEntries(
                Object.entries(errorBag).map(([key, value]) => [
                    key,
                    String(value),
                ]),
            );
        },
        onFinish: () => (busy.value = false),
    });
}

function submitTransfer(): void {
    busy.value = true;
    errors.value = {};
    const url =
        props.mode === 'edit' && props.resourceId
            ? `/admin/fees/transfer/${props.resourceId}`
            : '/admin/fees/transfer';
    const method = props.mode === 'edit' ? 'put' : 'post';

    router[method](url, transferForm.value, {
        headers: authHeaders(),
        preserveScroll: true,
        onError: (errorBag) => {
            errors.value = Object.fromEntries(
                Object.entries(errorBag).map(([key, value]) => [
                    key,
                    String(value),
                ]),
            );
        },
        onFinish: () => (busy.value = false),
    });
}

function deleteProvider(tier: ProviderTier): void {
    if (
        !window.confirm(
            `Delete ${tier.company_name} ${featureLabel(tier.feature)} tier?`,
        )
    )
        return;
    router.delete(`/admin/fees/provider/${tier.id}`, {
        headers: authHeaders(),
        preserveScroll: true,
    });
}

function deleteTransfer(tier: TransferTier): void {
    if (
        !window.confirm(
            `Delete ${tier.company_from_name} to ${tier.company_to_name} tier?`,
        )
    )
        return;
    router.delete(`/admin/fees/transfer/${tier.id}`, {
        headers: authHeaders(),
        preserveScroll: true,
    });
}

function markLogoFailed(companyId: number): void {
    failedLogos.value = new Set([...failedLogos.value, companyId]);
}
</script>

<template>
    <BankLayout
        :role="role"
        :announcement="announcement"
        :notification-count="notificationCount"
    >
        <header
            class="flex flex-col gap-3 border-b border-line pb-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div>
                <p class="text-xs font-black text-brand uppercase">
                    Owner Console / Fees
                </p>
                <h1 class="mt-1 text-2xl font-black text-ink sm:text-3xl">
                    Fee setup
                </h1>
                <p class="mt-1 text-sm text-slate">
                    Provider fees, agent commissions and transfer route fees.
                </p>
            </div>
            <Link
                v-if="!isEditor && mode !== 'detail'"
                :href="
                    activeKind === 'provider'
                        ? '/admin/fees/provider/create'
                        : '/admin/fees/transfer/create'
                "
                :headers="authHeaders()"
                class="bank-button bank-button-primary w-full sm:w-auto"
            >
                Add tier
            </Link>
        </header>

        <div
            class="mt-4 grid min-h-12 grid-cols-2 rounded-field border border-line bg-mist p-1"
            role="tablist"
        >
            <button
                type="button"
                role="tab"
                :aria-selected="activeKind === 'provider'"
                class="rounded-[10px] px-3 py-2 text-sm font-black transition"
                :class="
                    activeKind === 'provider'
                        ? 'bg-card text-ink shadow-sm'
                        : 'text-slate'
                "
                @click="chooseKind('provider')"
            >
                Provider fees
            </button>
            <button
                type="button"
                role="tab"
                :aria-selected="activeKind === 'transfer'"
                class="rounded-[10px] px-3 py-2 text-sm font-black transition"
                :class="
                    activeKind === 'transfer'
                        ? 'bg-card text-ink shadow-sm'
                        : 'text-slate'
                "
                @click="chooseKind('transfer')"
            >
                Transfer routes
            </button>
        </div>

        <form
            v-if="isEditor && activeKind === 'provider'"
            class="bank-form-shell mt-4 max-w-5xl"
            @submit.prevent="submitProvider"
        >
            <div
                class="flex items-start justify-between gap-4 border-b border-line pb-4"
            >
                <div>
                    <h2 class="text-lg font-black">
                        {{
                            mode === 'edit'
                                ? 'Edit provider tier'
                                : 'New provider tier'
                        }}
                    </h2>
                    <p class="mt-1 text-xs text-slate">
                        Fee and commission are selected by provider, feature and
                        amount.
                    </p>
                </div>
                <Link
                    href="/admin/fees?kind=provider"
                    :headers="authHeaders()"
                    class="bank-button bank-button-secondary px-4 py-2"
                    >Cancel</Link
                >
            </div>

            <div
                v-if="Object.keys(errors).length"
                class="mt-4 rounded-field border border-brand/20 bg-brand-soft px-3 py-2 text-sm font-bold text-brand"
                role="alert"
            >
                {{ Object.values(errors)[0] }}
            </div>

            <div
                class="mt-4 grid items-start gap-3 md:grid-cols-2 xl:grid-cols-4"
            >
                <label>
                    <span class="bank-label bank-required">Provider</span>
                    <select
                        v-model.number="providerForm.company_id"
                        class="bank-input min-h-13"
                        required
                    >
                        <option
                            v-for="company in companies"
                            :key="company.id"
                            :value="company.id"
                            :disabled="!company.is_active"
                        >
                            {{ company.name
                            }}{{ company.is_active ? '' : ' (Inactive)' }}
                        </option>
                    </select>
                </label>
                <label>
                    <span class="bank-label bank-required">Feature</span>
                    <select
                        v-model="providerForm.feature"
                        class="bank-input min-h-13"
                        required
                    >
                        <option
                            v-for="feature in features"
                            :key="feature.value"
                            :value="feature.value"
                        >
                            {{ feature.label }}
                        </option>
                    </select>
                </label>
                <label>
                    <span class="bank-label bank-required">Amount from</span>
                    <input
                        v-model.number="providerForm.amount_from"
                        type="number"
                        min="0"
                        step="0.01"
                        class="bank-input money min-h-13"
                        required
                    />
                </label>
                <label>
                    <span class="bank-label bank-required">Amount to</span>
                    <input
                        v-model.number="providerForm.amount_to"
                        type="number"
                        min="0.01"
                        step="0.01"
                        class="bank-input money min-h-13"
                        required
                    />
                </label>
            </div>

            <div
                v-if="selectedProviderCompany"
                class="mt-3 flex min-h-12 items-center gap-3 border-y border-line py-2"
            >
                <img
                    v-if="
                        selectedProviderCompany.logo_url &&
                        !failedLogos.has(selectedProviderCompany.id)
                    "
                    :src="selectedProviderCompany.logo_url"
                    :alt="`${selectedProviderCompany.name} logo`"
                    class="size-9 rounded-lg border border-line object-contain p-0.5"
                    @error="markLogoFailed(selectedProviderCompany.id)"
                />
                <span class="text-sm font-black">{{
                    selectedProviderCompany.name
                }}</span>
                <span class="text-xs font-bold text-slate">{{
                    featureLabel(providerForm.feature)
                }}</span>
            </div>

            <div class="mt-4 grid items-start gap-3 md:grid-cols-3">
                <fieldset class="min-w-0">
                    <legend class="bank-label bank-required">
                        Customer fee
                    </legend>
                    <div class="grid grid-cols-[7.5rem_minmax(0,1fr)] gap-2">
                        <select
                            v-model="providerForm.fee_type"
                            class="bank-input min-h-13 px-3"
                        >
                            <option>FIXED</option>
                            <option>PERCENTAGE</option>
                        </select>
                        <input
                            v-model.number="providerForm.fee_amount"
                            type="number"
                            min="0"
                            step="0.0001"
                            class="bank-input money min-h-13"
                            required
                        />
                    </div>
                </fieldset>
                <fieldset class="min-w-0">
                    <legend class="bank-label bank-required">
                        Additional fee
                    </legend>
                    <div class="grid grid-cols-[7.5rem_minmax(0,1fr)] gap-2">
                        <select
                            v-model="providerForm.additional_fee_type"
                            class="bank-input min-h-13 px-3"
                        >
                            <option>FIXED</option>
                            <option>PERCENTAGE</option>
                        </select>
                        <input
                            v-model.number="providerForm.additional_fee_amount"
                            type="number"
                            min="0"
                            step="0.0001"
                            class="bank-input money min-h-13"
                            required
                        />
                    </div>
                </fieldset>
                <fieldset class="min-w-0">
                    <legend class="bank-label bank-required">
                        Agent commission
                    </legend>
                    <div class="grid grid-cols-[7.5rem_minmax(0,1fr)] gap-2">
                        <select
                            v-model="providerForm.comm_type"
                            class="bank-input min-h-13 px-3"
                        >
                            <option>FIXED</option>
                            <option>PERCENTAGE</option>
                        </select>
                        <input
                            v-model.number="providerForm.comm_amount"
                            type="number"
                            min="0"
                            step="0.0001"
                            class="bank-input money min-h-13"
                            required
                        />
                    </div>
                </fieldset>
            </div>

            <div
                class="mt-5 grid gap-3 border-t border-line pt-4 md:grid-cols-[minmax(12rem,1fr)_minmax(0,2fr)_auto] md:items-end"
            >
                <label>
                    <span class="bank-label">Preview amount</span>
                    <input
                        v-model.number="sampleAmount"
                        type="number"
                        min="1"
                        step="1"
                        class="bank-input money min-h-13"
                    />
                </label>
                <div
                    class="flex min-h-13 items-center justify-between gap-4 rounded-field bg-mist px-4 py-2"
                >
                    <span class="text-xs font-bold text-slate"
                        >Rounded customer fee
                        <strong class="money ml-1 text-base text-ink"
                            >{{
                                money(providerPreview.customerFee)
                            }}
                            MMK</strong
                        ></span
                    >
                    <span class="text-xs font-bold text-slate"
                        >Agent
                        <strong class="money ml-1 text-base text-balance"
                            >+{{
                                money(providerPreview.commission)
                            }}
                            MMK</strong
                        ></span
                    >
                </div>
                <label
                    class="flex min-h-13 items-center gap-2 rounded-field border border-line px-4 text-sm font-bold"
                >
                    <input
                        v-model="providerForm.is_active"
                        type="checkbox"
                        class="size-4 accent-brand"
                    />
                    Active
                </label>
            </div>

            <div class="mt-5 flex justify-end">
                <button
                    type="submit"
                    :disabled="busy"
                    class="bank-button bank-button-primary w-full sm:w-auto"
                >
                    {{
                        busy
                            ? 'Saving...'
                            : mode === 'edit'
                              ? 'Update tier'
                              : 'Save tier'
                    }}
                </button>
            </div>
        </form>

        <form
            v-else-if="isEditor && activeKind === 'transfer'"
            class="bank-form-shell mt-4 max-w-5xl"
            @submit.prevent="submitTransfer"
        >
            <div
                class="flex items-start justify-between gap-4 border-b border-line pb-4"
            >
                <div>
                    <h2 class="text-lg font-black">
                        {{
                            mode === 'edit'
                                ? 'Edit transfer route'
                                : 'New transfer route'
                        }}
                    </h2>
                    <p class="mt-1 text-xs text-slate">
                        Customer fee is selected by provider route and amount.
                    </p>
                </div>
                <Link
                    href="/admin/fees?kind=transfer"
                    :headers="authHeaders()"
                    class="bank-button bank-button-secondary px-4 py-2"
                    >Cancel</Link
                >
            </div>
            <div
                v-if="Object.keys(errors).length"
                class="mt-4 rounded-field border border-brand/20 bg-brand-soft px-3 py-2 text-sm font-bold text-brand"
                role="alert"
            >
                {{ Object.values(errors)[0] }}
            </div>
            <div
                class="mt-4 grid items-start gap-3 md:grid-cols-2 xl:grid-cols-4"
            >
                <label
                    ><span class="bank-label bank-required">From provider</span
                    ><select
                        v-model.number="transferForm.company_from_id"
                        class="bank-input min-h-13"
                        required
                    >
                        <option
                            v-for="company in companies"
                            :key="company.id"
                            :value="company.id"
                            :disabled="!company.is_active"
                        >
                            {{ company.name
                            }}{{ company.is_active ? '' : ' (Inactive)' }}
                        </option>
                    </select></label
                >
                <label
                    ><span class="bank-label bank-required">To provider</span
                    ><select
                        v-model.number="transferForm.company_to_id"
                        class="bank-input min-h-13"
                        required
                    >
                        <option
                            v-for="company in companies"
                            :key="company.id"
                            :value="company.id"
                            :disabled="
                                !company.is_active ||
                                company.id === transferForm.company_from_id
                            "
                        >
                            {{ company.name
                            }}{{ company.is_active ? '' : ' (Inactive)' }}
                        </option>
                    </select></label
                >
                <label
                    ><span class="bank-label bank-required">Amount from</span
                    ><input
                        v-model.number="transferForm.amount_from"
                        type="number"
                        min="0"
                        step="0.01"
                        class="bank-input money min-h-13"
                        required
                /></label>
                <label
                    ><span class="bank-label bank-required">Amount to</span
                    ><input
                        v-model.number="transferForm.amount_to"
                        type="number"
                        min="0.01"
                        step="0.01"
                        class="bank-input money min-h-13"
                        required
                /></label>
            </div>
            <div class="mt-4 grid items-start gap-3 md:grid-cols-2">
                <fieldset>
                    <legend class="bank-label bank-required">
                        Transfer fee
                    </legend>
                    <div class="grid grid-cols-[7.5rem_minmax(0,1fr)] gap-2">
                        <select
                            v-model="transferForm.fee_type"
                            class="bank-input min-h-13 px-3"
                        >
                            <option>FIXED</option>
                            <option>PERCENTAGE</option></select
                        ><input
                            v-model.number="transferForm.fee_amount"
                            type="number"
                            min="0"
                            step="0.0001"
                            class="bank-input money min-h-13"
                            required
                        />
                    </div>
                </fieldset>
                <fieldset>
                    <legend class="bank-label bank-required">
                        Additional fee
                    </legend>
                    <div class="grid grid-cols-[7.5rem_minmax(0,1fr)] gap-2">
                        <select
                            v-model="transferForm.additional_fee_type"
                            class="bank-input min-h-13 px-3"
                        >
                            <option>FIXED</option>
                            <option>PERCENTAGE</option></select
                        ><input
                            v-model.number="transferForm.additional_fee_amount"
                            type="number"
                            min="0"
                            step="0.0001"
                            class="bank-input money min-h-13"
                            required
                        />
                    </div>
                </fieldset>
            </div>
            <div
                class="mt-5 grid gap-3 border-t border-line pt-4 md:grid-cols-[minmax(12rem,1fr)_minmax(0,2fr)_auto] md:items-end"
            >
                <label
                    ><span class="bank-label">Preview amount</span
                    ><input
                        v-model.number="sampleAmount"
                        type="number"
                        min="1"
                        step="1"
                        class="bank-input money min-h-13"
                /></label>
                <div
                    class="flex min-h-13 items-center justify-between rounded-field bg-mist px-4 py-2"
                >
                    <span class="text-xs font-bold text-slate"
                        >Rounded customer fee</span
                    ><strong class="money text-lg text-ink"
                        >{{ money(transferPreview) }} MMK</strong
                    >
                </div>
                <label
                    class="flex min-h-13 items-center gap-2 rounded-field border border-line px-4 text-sm font-bold"
                    ><input
                        v-model="transferForm.is_active"
                        type="checkbox"
                        class="size-4 accent-brand"
                    />
                    Active</label
                >
            </div>
            <div class="mt-5 flex justify-end">
                <button
                    type="submit"
                    :disabled="busy"
                    class="bank-button bank-button-primary w-full sm:w-auto"
                >
                    {{
                        busy
                            ? 'Saving...'
                            : mode === 'edit'
                              ? 'Update route'
                              : 'Save route'
                    }}
                </button>
            </div>
        </form>

        <section
            v-else-if="mode === 'detail' && currentProvider"
            class="bank-form-shell mt-4 max-w-4xl"
        >
            <div
                class="flex items-start justify-between gap-3 border-b border-line pb-4"
            >
                <div>
                    <p class="text-xs font-black text-brand uppercase">
                        Provider tier
                    </p>
                    <h2 class="mt-1 text-xl font-black">
                        {{ currentProvider.company_name }} /
                        {{ featureLabel(currentProvider.feature) }}
                    </h2>
                </div>
                <Link
                    :href="`/admin/fees/provider/${currentProvider.id}/edit`"
                    :headers="authHeaders()"
                    class="bank-button bank-button-primary"
                    >Edit</Link
                >
            </div>
            <dl class="mt-4 grid gap-x-6 md:grid-cols-2">
                <div
                    class="flex justify-between border-b border-line py-3 text-sm"
                >
                    <dt class="text-slate">Amount range</dt>
                    <dd class="money font-bold">
                        {{ money(currentProvider.amount_from) }} -
                        {{ money(currentProvider.amount_to) }}
                    </dd>
                </div>
                <div
                    class="flex justify-between border-b border-line py-3 text-sm"
                >
                    <dt class="text-slate">Customer fee</dt>
                    <dd class="money font-bold">
                        {{ currentProvider.fee_type }} /
                        {{ money(currentProvider.fee_amount) }}
                    </dd>
                </div>
                <div
                    class="flex justify-between border-b border-line py-3 text-sm"
                >
                    <dt class="text-slate">Additional fee</dt>
                    <dd class="money font-bold">
                        {{ currentProvider.additional_fee_type }} /
                        {{ money(currentProvider.additional_fee_amount) }}
                    </dd>
                </div>
                <div
                    class="flex justify-between border-b border-line py-3 text-sm"
                >
                    <dt class="text-slate">Agent commission</dt>
                    <dd class="money font-bold">
                        {{ currentProvider.comm_type }} /
                        {{ money(currentProvider.comm_amount) }}
                    </dd>
                </div>
            </dl>
        </section>

        <section v-else class="mt-4">
            <div class="grid gap-2 md:grid-cols-[minmax(0,1fr)_12rem]">
                <input
                    v-model="search"
                    type="search"
                    class="bank-input min-h-12"
                    :placeholder="
                        activeKind === 'provider'
                            ? 'Search provider, feature or amount'
                            : 'Search route or amount'
                    "
                />
                <select v-model="status" class="bank-input min-h-12 py-2">
                    <option value="all">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div v-if="activeKind === 'provider'" class="mt-3">
                <div
                    class="hidden overflow-x-auto rounded-field border border-line bg-card md:block"
                >
                    <table class="w-full min-w-[920px] text-left text-sm">
                        <thead class="bg-mist text-xs text-slate uppercase">
                            <tr>
                                <th class="px-4 py-3">Provider / Feature</th>
                                <th class="px-4 py-3">Amount range</th>
                                <th class="px-4 py-3">Customer fee</th>
                                <th class="px-4 py-3">Additional</th>
                                <th class="px-4 py-3">Agent commission</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr
                                v-for="tier in paginatedProviderTiers"
                                :key="tier.id"
                            >
                                <td class="px-4 py-3">
                                    <p class="font-black">
                                        {{ tier.company_name }}
                                    </p>
                                    <p class="text-xs font-bold text-slate">
                                        {{ featureLabel(tier.feature) }} ·
                                        {{
                                            tier.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}
                                    </p>
                                </td>
                                <td class="money px-4 py-3 font-bold">
                                    {{ money(tier.amount_from) }} -
                                    {{ money(tier.amount_to) }}
                                </td>
                                <td class="money px-4 py-3">
                                    {{ tier.fee_type }} /
                                    {{ money(tier.fee_amount) }}
                                </td>
                                <td class="money px-4 py-3">
                                    {{ tier.additional_fee_type }} /
                                    {{ money(tier.additional_fee_amount) }}
                                </td>
                                <td class="money px-4 py-3">
                                    {{ tier.comm_type }} /
                                    {{ money(tier.comm_amount) }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <Link
                                            :href="`/admin/fees/provider/${tier.id}/edit`"
                                            :headers="authHeaders()"
                                            class="bank-button bank-button-secondary min-h-9 px-3 py-1.5"
                                            >Edit</Link
                                        ><button
                                            type="button"
                                            class="bank-button bank-button-danger min-h-9 px-3 py-1.5"
                                            @click="deleteProvider(tier)"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="filteredProviderTiers.length === 0">
                                <td
                                    colspan="6"
                                    class="px-4 py-8 text-center font-bold text-slate"
                                >
                                    No provider fee tiers found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="grid gap-2 md:hidden">
                    <article
                        v-for="tier in paginatedProviderTiers"
                        :key="tier.id"
                        class="rounded-field border border-line bg-card p-3"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-black">
                                    {{ tier.company_name }}
                                </h3>
                                <p class="text-xs font-bold text-slate">
                                    {{ featureLabel(tier.feature) }}
                                </p>
                            </div>
                            <span
                                class="text-xs font-black"
                                :class="
                                    tier.is_active
                                        ? 'text-balance'
                                        : 'text-slate'
                                "
                                >{{
                                    tier.is_active ? 'Active' : 'Inactive'
                                }}</span
                            >
                        </div>
                        <p class="money mt-3 text-sm font-bold">
                            {{ money(tier.amount_from) }} -
                            {{ money(tier.amount_to) }} MMK
                        </p>
                        <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
                            <div>
                                <span class="text-slate">Fee</span
                                ><strong class="money mt-1 block">{{
                                    money(tier.fee_amount)
                                }}</strong>
                            </div>
                            <div>
                                <span class="text-slate">Additional</span
                                ><strong class="money mt-1 block">{{
                                    money(tier.additional_fee_amount)
                                }}</strong>
                            </div>
                            <div>
                                <span class="text-slate">Agent</span
                                ><strong class="money mt-1 block">{{
                                    money(tier.comm_amount)
                                }}</strong>
                            </div>
                        </div>
                        <div class="mt-3 flex gap-2 border-t border-line pt-3">
                            <Link
                                :href="`/admin/fees/provider/${tier.id}/edit`"
                                :headers="authHeaders()"
                                class="bank-button bank-button-secondary min-h-10 flex-1 py-2"
                                >Edit</Link
                            ><button
                                type="button"
                                class="bank-button bank-button-danger min-h-10 flex-1 py-2"
                                @click="deleteProvider(tier)"
                            >
                                Delete
                            </button>
                        </div>
                    </article>
                </div>
            </div>

            <div v-else class="mt-3">
                <div
                    class="hidden overflow-x-auto rounded-field border border-line bg-card md:block"
                >
                    <table class="w-full min-w-[820px] text-left text-sm">
                        <thead class="bg-mist text-xs text-slate uppercase">
                            <tr>
                                <th class="px-4 py-3">Provider route</th>
                                <th class="px-4 py-3">Amount range</th>
                                <th class="px-4 py-3">Transfer fee</th>
                                <th class="px-4 py-3">Additional</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr
                                v-for="tier in paginatedTransferTiers"
                                :key="tier.id"
                            >
                                <td class="px-4 py-3">
                                    <p class="font-black">
                                        {{ tier.company_from_name }} →
                                        {{ tier.company_to_name }}
                                    </p>
                                    <p
                                        class="text-xs font-bold"
                                        :class="
                                            tier.is_active
                                                ? 'text-balance'
                                                : 'text-slate'
                                        "
                                    >
                                        {{
                                            tier.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}
                                    </p>
                                </td>
                                <td class="money px-4 py-3 font-bold">
                                    {{ money(tier.amount_from) }} -
                                    {{ money(tier.amount_to) }}
                                </td>
                                <td class="money px-4 py-3">
                                    {{ tier.fee_type }} /
                                    {{ money(tier.fee_amount) }}
                                </td>
                                <td class="money px-4 py-3">
                                    {{ tier.additional_fee_type }} /
                                    {{ money(tier.additional_fee_amount) }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <Link
                                            :href="`/admin/fees/transfer/${tier.id}/edit`"
                                            :headers="authHeaders()"
                                            class="bank-button bank-button-secondary min-h-9 px-3 py-1.5"
                                            >Edit</Link
                                        ><button
                                            type="button"
                                            class="bank-button bank-button-danger min-h-9 px-3 py-1.5"
                                            @click="deleteTransfer(tier)"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="filteredTransferTiers.length === 0">
                                <td
                                    colspan="5"
                                    class="px-4 py-8 text-center font-bold text-slate"
                                >
                                    No transfer fee routes found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="grid gap-2 md:hidden">
                    <article
                        v-for="tier in paginatedTransferTiers"
                        :key="tier.id"
                        class="rounded-field border border-line bg-card p-3"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <h3 class="font-black">
                                {{ tier.company_from_name }} →
                                {{ tier.company_to_name }}
                            </h3>
                            <span
                                class="text-xs font-black"
                                :class="
                                    tier.is_active
                                        ? 'text-balance'
                                        : 'text-slate'
                                "
                                >{{
                                    tier.is_active ? 'Active' : 'Inactive'
                                }}</span
                            >
                        </div>
                        <p class="money mt-3 text-sm font-bold">
                            {{ money(tier.amount_from) }} -
                            {{ money(tier.amount_to) }} MMK
                        </p>
                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <span class="text-slate">Transfer fee</span
                                ><strong class="money mt-1 block"
                                    >{{ tier.fee_type }} /
                                    {{ money(tier.fee_amount) }}</strong
                                >
                            </div>
                            <div>
                                <span class="text-slate">Additional</span
                                ><strong class="money mt-1 block"
                                    >{{ tier.additional_fee_type }} /
                                    {{
                                        money(tier.additional_fee_amount)
                                    }}</strong
                                >
                            </div>
                        </div>
                        <div class="mt-3 flex gap-2 border-t border-line pt-3">
                            <Link
                                :href="`/admin/fees/transfer/${tier.id}/edit`"
                                :headers="authHeaders()"
                                class="bank-button bank-button-secondary min-h-10 flex-1 py-2"
                                >Edit</Link
                            ><button
                                type="button"
                                class="bank-button bank-button-danger min-h-10 flex-1 py-2"
                                @click="deleteTransfer(tier)"
                            >
                                Delete
                            </button>
                        </div>
                    </article>
                </div>
            </div>

            <footer
                v-if="activeTotal > 0"
                class="mt-3 flex flex-col gap-2 border-t border-line pt-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-xs font-bold text-slate">
                    Showing {{ (page - 1) * pageSize + 1 }}-{{
                        Math.min(page * pageSize, activeTotal)
                    }}
                    of {{ activeTotal }} tiers
                </p>
                <div class="flex gap-2">
                    <button
                        type="button"
                        :disabled="page <= 1"
                        class="bank-button bank-button-secondary min-h-10 flex-1 px-4 py-2 sm:flex-none"
                        @click="page--"
                    >
                        Previous
                    </button>
                    <span
                        class="flex min-h-10 min-w-20 items-center justify-center rounded-pill bg-mist px-3 text-xs font-black"
                    >
                        {{ page }} / {{ pageCount }}
                    </span>
                    <button
                        type="button"
                        :disabled="page >= pageCount"
                        class="bank-button bank-button-secondary min-h-10 flex-1 px-4 py-2 sm:flex-none"
                        @click="page++"
                    >
                        Next
                    </button>
                </div>
            </footer>
        </section>
    </BankLayout>
</template>
