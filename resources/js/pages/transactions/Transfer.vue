<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AccountTile from '@/components/bank/AccountTile.vue';
import BigAmountInput from '@/components/bank/BigAmountInput.vue';
import DenomDrawer from '@/components/bank/DenomDrawer.vue';
import FeePaymentSelector from '@/components/bank/FeePaymentSelector.vue';
import type { FeePaymentMethod } from '@/components/bank/FeePaymentSelector.vue';
import TransactionHistoryTable from '@/components/teller/TransactionHistoryTable.vue';
import BankLayout from '@/layouts/BankLayout.vue';
import { readStoredToken } from '@/lib/auth-token';
import { useLocale } from '@/lib/i18n';
import type { TransactionHistoryRow } from '@/types/domain';

/**
 * Transfer — reference flow, three steps inside one card:
 *   Enter Details → Review → Receipt
 * Cash In / Cash Out / Exchange copy this file: swap the tiles and the POST
 * endpoint, keep everything else (tabs, amount, drawer, review, receipt).
 */
const props = withDefaults(
    defineProps<{
        role: 'admin' | 'cashier' | 'teller';
        view?: 'entry' | 'history';
        announcement?: string | null;
        notificationCount?: number;
        float: { status: string } | null;
        notes: number[];
        floatStock: Record<number, number>;
        accounts: {
            id: number;
            company: string;
            company_id?: number | null;
            company_category?: string | null;
            company_logo_url?: string | null;
            name: string;
            number?: string;
            balance: string;
        }[];
        feeAccounts: {
            id: number;
            company: string;
            name: string;
            number?: string;
            balance: string;
        }[];
        serviceTypes: {
            id: number;
            company_id?: number | null;
            company: string;
            company_category?: string | null;
            company_logo_url?: string | null;
            name: string;
            operation: string;
        }[];
        fee: string;
        receiveCommission: string;
        payoutCommission: string;
        /** true when the creator must settle notes from a float (employees) */
        requiresDenominations: boolean;
        completed?: {
            id: number;
            amount: string;
            fee_amount: string;
            status: string;
            created_at: string;
            from_label: string;
            to_label: string;
            system_receive_label?: string | null;
            system_payout_label?: string | null;
            receive_commission_amount?: string;
            payout_commission_amount?: string;
            destination_customer_name?: string | null;
            customer_name?: string | null;
            customer_phone?: string | null;
        } | null;
        history?: TransactionHistoryRow[];
    }>(),
    {
        view: 'entry',
        history: () => [],
    },
);

const step = ref<'form' | 'review'>('form');
const fromId = ref<number | null>(null);
const toId = ref<number | null>(null);
const selectedPayoutCompany = ref('');
const payoutAccountType = ref<'pay' | 'bank'>('bank');
const sourceAccountType = ref<'pay' | 'bank'>('pay');
const selectedSourceCompany = ref('');
const sourceAccountNumber = ref('');
const destinationCustomerName = ref('');
const destinationAccountNumber = ref('');
const amount = ref(0);
const customerName = ref('');
const description = ref('');
const feeDenoms = ref<Record<number, number>>({});
const submitting = ref(false);
const errors = ref<Record<string, string>>({});
const feePaymentMethod = ref<FeePaymentMethod>('cash');
const feeAccountId = ref<number | null>(null);
const historySearch = ref('');
const historyStatus = ref('all');
const historyDateFrom = ref('');
const historyDateTo = ref('');
const { t } = useLocale();

const feeNum = computed(() => Number(props.fee ?? 0));
const historyStatuses = computed(() =>
    [...new Set(props.history.map((row) => row.status))].sort(),
);
const filteredHistory = computed(() => {
    const query = historySearch.value.trim().toLowerCase();

    return props.history.filter((row) => {
        const reference = String(row.id).padStart(6, '0');
        const matchesSearch =
            query === '' ||
            [
                reference,
                `#${reference}`,
                row.customer_name,
                row.customer_phone,
                row.account_label,
                row.to_account_label,
                row.note,
                row.amount,
                row.status,
            ].some((value) => String(value ?? '').toLowerCase().includes(query));
        const matchesStatus =
            historyStatus.value === 'all' ||
            row.status === historyStatus.value;
        const transactionDate = row.created_at?.slice(0, 10) ?? '';
        const matchesDateFrom =
            historyDateFrom.value === '' ||
            transactionDate >= historyDateFrom.value;
        const matchesDateTo =
            historyDateTo.value === '' ||
            transactionDate <= historyDateTo.value;

        return (
            matchesSearch &&
            matchesStatus &&
            matchesDateFrom &&
            matchesDateTo
        );
    });
});
const receiveCommissionNum = computed(() =>
    Number(props.receiveCommission ?? 0),
);
const payoutCommissionNum = computed(() => Number(props.payoutCommission ?? 0));
const from = computed(() => props.accounts.find((a) => a.id === fromId.value));
const to = computed(() => props.accounts.find((a) => a.id === toId.value));
const sourceServiceTypes = computed(() =>
    props.serviceTypes.filter(
        (serviceType) =>
            serviceType.operation === 'CashIn' ||
            serviceType.operation === 'Transfer' ||
            serviceType.operation === 'All' ||
            ['WST', 'Pay_To_Pay', 'P2P', 'Bank Transfer'].includes(
                serviceType.name,
            ),
    ),
);
const sourceCompanies = computed(() => {
    const selectedCategory = sourceAccountType.value;
    const unique = new Map<
        string,
        { id: number | null; name: string; logoUrl: string | null }
    >();

    for (const serviceType of sourceServiceTypes.value) {
        if (!serviceType.company || unique.has(serviceType.company)) {
            continue;
        }

        const category = (serviceType.company_category ?? 'Both')
            .trim()
            .toLowerCase();

        if (category !== selectedCategory && category !== 'both') {
            continue;
        }

        unique.set(serviceType.company, {
            id: serviceType.company_id ?? null,
            name: serviceType.company,
            logoUrl: serviceType.company_logo_url ?? null,
        });
    }

    return Array.from(unique.values());
});
const systemCompanies = computed(() => {
    const unique = new Map<
        string,
        {
            id: number | null;
            name: string;
            category: 'pay' | 'bank' | 'both';
            logoUrl: string | null;
        }
    >();

    for (const account of props.accounts) {
        if (!account.company || unique.has(account.company)) {
            continue;
        }

        unique.set(account.company, {
            id: account.company_id ?? null,
            name: account.company,
            category: (account.company_category ?? 'Both')
                .trim()
                .toLowerCase() as 'pay' | 'bank' | 'both',
            logoUrl: account.company_logo_url ?? null,
        });
    }

    return Array.from(unique.values());
});
const receiveAccounts = computed(() =>
    props.accounts.filter(
        (account) =>
            account.company === selectedSourceCompany.value &&
            ['both', sourceAccountType.value].includes(
                (account.company_category ?? 'Both').trim().toLowerCase(),
            ),
    ),
);
const payoutCompanies = computed(() =>
    systemCompanies.value.filter((company) =>
        ['both', payoutAccountType.value].includes(company.category),
    ),
);
const payoutAccounts = computed(() =>
    props.accounts.filter(
        (account) =>
            !selectedPayoutCompany.value ||
            account.company === selectedPayoutCompany.value,
    ),
);
const receiveCredit = computed(
    () =>
        amount.value +
        receiveCommissionNum.value +
        (feePaymentMethod.value === 'account' ? feeNum.value : 0),
);
const customerTotalDue = computed(() => amount.value + feeNum.value);
const payoutBalanceChange = computed(
    () => -amount.value + payoutCommissionNum.value,
);
const feeDenomTotal = computed(() =>
    props.notes.reduce((s, n) => s + n * (feeDenoms.value[n] ?? 0), 0),
);

const needsCashFeeDenoms = computed(
    () =>
        props.role === 'teller' &&
        feePaymentMethod.value === 'cash' &&
        feeNum.value > 0,
);
const floatLocked = computed(
    () => needsCashFeeDenoms.value && props.float?.status !== 'ACTIVE',
);
const cashierLocked = computed(() => props.role === 'cashier');
const ready = computed(
    () =>
        toId.value !== null &&
        fromId.value !== null &&
        fromId.value !== toId.value &&
        selectedSourceCompany.value.trim().length > 0 &&
        sourceAccountNumber.value.trim().length > 0 &&
        destinationCustomerName.value.trim().length > 0 &&
        destinationAccountNumber.value.trim().length > 0 &&
        amount.value > 0 &&
        customerName.value.trim().length > 0 &&
        (!needsCashFeeDenoms.value || feeDenomTotal.value === feeNum.value) &&
        !floatLocked.value &&
        !cashierLocked.value,
);

/** Fee is server truth — debounce-reload it when the slip changes. */
watch(
    sourceCompanies,
    (values) => {
        if (
            !values.some(
                (company) => company.name === selectedSourceCompany.value,
            )
        ) {
            selectedSourceCompany.value = values[0]?.name ?? '';
        }
    },
    { immediate: true },
);

watch([selectedSourceCompany, sourceAccountType, receiveAccounts], () => {
    if (!receiveAccounts.value.some((account) => account.id === toId.value)) {
        toId.value = null;
    }
});
watch(
    payoutCompanies,
    (values) => {
        if (
            !values.some(
                (company) => company.name === selectedPayoutCompany.value,
            )
        ) {
            selectedPayoutCompany.value = values[0]?.name ?? '';
        }
    },
    { immediate: true },
);
watch(selectedPayoutCompany, () => {
    if (!payoutAccounts.value.some((account) => account.id === fromId.value)) {
        fromId.value = null;
    }
});

let feeTimer: ReturnType<typeof setTimeout>;
watch([amount, toId, fromId], ([a, receiveId, payoutId]) => {
    clearTimeout(feeTimer);

    if (a > 0 && receiveId && payoutId) {
        feeTimer = setTimeout(
            () =>
                router.reload({
                    only: ['fee', 'receiveCommission', 'payoutCommission'],
                    data: {
                        amount: a,
                        account_id: receiveId,
                        receive_account_id: receiveId,
                        payout_account_id: payoutId,
                    },
                    headers: authHeaders(),
                }),
            350,
        );
    }
});

const mmk = (v: string | number) => Number(v).toLocaleString();
function authHeaders(): Record<string, string> {
    const token = readStoredToken();

    return token ? { Authorization: `Bearer ${token}` } : {};
}

function clearHistoryFilters(): void {
    historySearch.value = '';
    historyStatus.value = 'all';
    historyDateFrom.value = '';
    historyDateTo.value = '';
}

function submit() {
    submitting.value = true;
    router.post(
        '/transactions/transfer',
        {
            from_account_id: fromId.value,
            source_account_type: sourceAccountType.value,
            source_provider: selectedSourceCompany.value.trim(),
            source_account_number: sourceAccountNumber.value.trim(),
            to_account_id: toId.value,
            destination_provider:
                from.value?.company ?? selectedPayoutCompany.value,
            destination_customer_name: destinationCustomerName.value.trim(),
            destination_account_number: destinationAccountNumber.value.trim(),
            amount: amount.value,
            customer_name: customerName.value.trim(),
            note: description.value,
            fee_payment_method: feePaymentMethod.value,
            fee_account_id: null,
            ...(needsCashFeeDenoms.value
                ? { fee_denominations: feeDenoms.value }
                : {}),
        },
        {
            headers: authHeaders(),
            onError: (e) => {
                errors.value = Object.fromEntries(
                    Object.entries(e).map(([key, value]) => [
                        key,
                        String(value),
                    ]),
                );
                step.value = 'form';
            },
            onFinish: () => (submitting.value = false),
        },
    );
}
</script>

<template>
    <BankLayout
        :role="role"
        :announcement="announcement"
        :notification-count="notificationCount"
    >
        <h1 class="text-2xl font-bold tracking-tight">
            {{ t('transaction.transfer') }}
        </h1>

        <template v-if="view === 'history'">
            <section
                class="mt-5 rounded-2xl border border-line bg-card p-4"
                aria-label="Transfer history filters"
            >
                <div
                    class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(16rem,2fr)_minmax(10rem,1fr)_minmax(9rem,1fr)_minmax(9rem,1fr)_auto]"
                >
                    <label class="min-w-0">
                        <span class="bank-label">Search</span>
                        <input
                            v-model="historySearch"
                            type="search"
                            class="bank-input mt-1.5"
                            placeholder="Reference, customer, phone, account"
                        />
                    </label>
                    <label>
                        <span class="bank-label">Status</span>
                        <select
                            v-model="historyStatus"
                            class="bank-input mt-1.5"
                        >
                            <option value="all">All statuses</option>
                            <option
                                v-for="status in historyStatuses"
                                :key="status"
                                :value="status"
                            >
                                {{ status }}
                            </option>
                        </select>
                    </label>
                    <label>
                        <span class="bank-label">From</span>
                        <input
                            v-model="historyDateFrom"
                            type="date"
                            class="bank-input mt-1.5"
                        />
                    </label>
                    <label>
                        <span class="bank-label">To</span>
                        <input
                            v-model="historyDateTo"
                            type="date"
                            class="bank-input mt-1.5"
                        />
                    </label>
                    <button
                        type="button"
                        class="bank-button bank-button-secondary self-end rounded-pill px-4"
                        @click="clearHistoryFilters"
                    >
                        Clear
                    </button>
                </div>
            </section>

            <TransactionHistoryTable
                :rows="filteredHistory"
                :title="`${t('transaction.transfer')} ${t('common.history', 'History')}`"
                empty-text="No transfer transactions match these filters."
            />
        </template>

        <!-- float lock: employees without an active float cannot enter cash movements -->
        <p
            v-if="view === 'entry' && floatLocked"
            class="mt-4 max-w-3xl rounded-field bg-brand-soft px-4 py-3 text-sm font-semibold text-brand-deep"
        >
            {{ t('transaction.floatLocked') }}
            <Link
                href="/teller/float"
                :headers="authHeaders()"
                class="underline underline-offset-2"
                >{{ t('transaction.goToFloats') }}</Link
            >
        </p>
        <p
            v-if="view === 'entry' && cashierLocked"
            class="mt-4 max-w-3xl rounded-field bg-brand-soft px-4 py-3 text-sm font-semibold text-brand-deep"
        >
            {{ t('transaction.cashierLocked') }}
        </p>

        <!-- ===== Receipt ===== -->
        <section
            v-if="view === 'entry' && completed"
            class="mt-5 max-w-xl rounded-2xl border border-line bg-card p-7 shadow-sm sm:p-9"
        >
            <div class="text-center">
                <span
                    class="mx-auto grid size-14 place-items-center rounded-full bg-balance/10 text-2xl text-balance"
                    >✓</span
                >
                <h2 class="mt-3 text-xl font-bold">
                    {{ t('transaction.transfer') }} {{ t('status.completed') }}
                </h2>
                <p class="money mt-1 text-sm text-slate">
                    Ref #{{ String(completed.id).padStart(6, '0') }} ·
                    {{ completed.created_at }}
                </p>
            </div>
            <dl
                class="transfer-receipt-list mt-6 divide-y divide-line border-t border-line"
            >
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.sourceAccount') }}
                    </dt>
                    <dd class="font-bold">{{ completed.from_label }}</dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.destinationAccount') }}
                    </dt>
                    <dd class="font-bold">{{ completed.to_label }}</dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.systemReceiveAccount') }}
                    </dt>
                    <dd class="text-right font-bold">
                        {{ completed.system_receive_label }}
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.systemPayoutAccount') }}
                    </dt>
                    <dd class="text-right font-bold">
                        {{ completed.system_payout_label }}
                    </dd>
                </div>
                <div class="flex justify-between py-3">
                    <dt class="text-sm text-slate">
                        {{ t('transaction.amount') }}
                    </dt>
                    <dd class="money text-lg font-bold">
                        {{ mmk(completed.amount) }}
                        <span class="text-[11px] text-slate">MMK</span>
                    </dd>
                </div>
                <div
                    v-if="completed.customer_name || completed.customer_phone"
                    class="flex justify-between gap-3 py-3 text-sm"
                >
                    <dt class="text-slate">
                        {{ t('transaction.customerName') }}
                    </dt>
                    <dd class="text-right font-bold">
                        {{ completed.customer_name || '-' }}
                        <span
                            v-if="completed.customer_phone"
                            class="block text-[11px] font-medium text-slate"
                            >{{ completed.customer_phone }}</span
                        >
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">{{ t('transaction.fee') }}</dt>
                    <dd class="money font-bold">
                        {{ mmk(completed.fee_amount) }} MMK
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.receiveCommission') }}
                    </dt>
                    <dd class="money font-bold">
                        {{ mmk(completed.receive_commission_amount ?? 0) }} MMK
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.payoutCommission') }}
                    </dt>
                    <dd class="money font-bold">
                        {{ mmk(completed.payout_commission_amount ?? 0) }} MMK
                    </dd>
                </div>
            </dl>
            <div class="mt-6 flex gap-2">
                <Link
                    href="/transactions/transfer"
                    :headers="authHeaders()"
                    class="bank-button bank-button-primary flex-1 rounded-pill"
                >
                    {{ t('transaction.transfer') }} အသစ်
                </Link>
                <Link
                    href="/dashboard"
                    :headers="authHeaders()"
                    class="bank-button bank-button-secondary rounded-pill"
                >
                    {{ t('common.home') }}
                </Link>
            </div>
        </section>

        <!-- ===== Enter Details ===== -->
        <section
            v-else-if="view === 'entry' && step === 'form'"
            class="bank-form-shell mt-5 max-w-3xl"
            :class="
                floatLocked || cashierLocked
                    ? 'pointer-events-none opacity-50'
                    : ''
            "
        >
            <h2 class="text-base font-bold">
                {{ t('transaction.enterTransferDetails') }}
            </h2>

            <section
                class="mt-4 rounded-2xl border border-line bg-card p-4 shadow-sm sm:p-5"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-black text-ink">
                            {{ t('transaction.transferCustomerInfo') }}
                        </h3>
                        <p class="mt-1 text-xs text-slate">
                            {{ t('transaction.transferCustomerInfoHint') }}
                        </p>
                    </div>
                    <span class="text-xs font-bold text-brand">{{
                        t('component.required')
                    }}</span>
                </div>

                <div class="mt-3 space-y-3">
                    <div class="max-w-md">
                        <span class="bank-label">{{
                            t('transaction.customerPayBank')
                        }}</span>
                        <div class="mt-1.5 grid grid-cols-2 gap-2">
                            <label
                                class="bank-choice flex cursor-pointer items-center gap-2 rounded-field border bg-card px-3 py-2.5 transition"
                                :class="
                                    sourceAccountType === 'pay'
                                        ? 'border-brand ring-1 ring-brand/20'
                                        : 'border-line hover:border-ink/30'
                                "
                            >
                                <input
                                    v-model="sourceAccountType"
                                    type="radio"
                                    value="pay"
                                    class="accent-brand"
                                />
                                <span class="text-sm font-bold">Pay</span>
                            </label>
                            <label
                                class="bank-choice flex cursor-pointer items-center gap-2 rounded-field border bg-card px-3 py-2.5 transition"
                                :class="
                                    sourceAccountType === 'bank'
                                        ? 'border-brand ring-1 ring-brand/20'
                                        : 'border-line hover:border-ink/30'
                                "
                            >
                                <input
                                    v-model="sourceAccountType"
                                    type="radio"
                                    value="bank"
                                    class="accent-brand"
                                />
                                <span class="text-sm font-bold">Bank</span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="bank-label">{{
                                t('transaction.customerSourceCompany')
                            }}</span>
                            <span class="text-[11px] font-bold text-slate">
                                {{ sourceCompanies.length }}
                                {{ t('transaction.companies') }}
                            </span>
                        </div>
                        <div
                            class="mt-1.5 grid grid-cols-2 gap-2 sm:grid-cols-4"
                            role="radiogroup"
                            aria-label="Source company"
                        >
                            <button
                                v-for="company in sourceCompanies"
                                :key="company.id ?? company.name"
                                type="button"
                                role="radio"
                                :aria-checked="
                                    selectedSourceCompany === company.name
                                "
                                class="group flex min-h-16 items-center gap-2 rounded-xl border px-3 py-2 text-left transition"
                                :class="
                                    selectedSourceCompany === company.name
                                        ? 'border-brand bg-brand-soft text-brand shadow-sm ring-2 ring-brand/15'
                                        : 'border-line bg-mist/40 text-ink hover:border-brand/40 hover:bg-brand-soft/40'
                                "
                                @click="selectedSourceCompany = company.name"
                            >
                                <span
                                    class="grid size-9 shrink-0 place-items-center overflow-hidden rounded-xl text-sm font-black"
                                    :class="
                                        selectedSourceCompany === company.name
                                            ? 'bg-brand text-white'
                                            : 'bg-white text-brand shadow-sm'
                                    "
                                >
                                    <img
                                        v-if="company.logoUrl"
                                        :src="company.logoUrl"
                                        :alt="`${company.name} logo`"
                                        class="size-full object-contain p-1"
                                    />
                                    <span v-else>
                                        {{
                                            company.name
                                                .slice(0, 1)
                                                .toUpperCase()
                                        }}
                                    </span>
                                </span>
                                <span class="min-w-0">
                                    <span
                                        class="block truncate text-xs font-black"
                                        >{{ company.name }}</span
                                    >
                                    <span
                                        class="mt-0.5 block text-[10px] text-slate"
                                    >
                                        {{ sourceAccountType.toUpperCase() }}
                                    </span>
                                </span>
                            </button>
                        </div>
                    </div>

                    <div class="grid gap-3 lg:grid-cols-2">
                        <label>
                            <span class="bank-label">{{
                                t('transaction.sourceBeneficiaryName')
                            }}</span>
                            <input
                                v-model="customerName"
                                type="text"
                                autocomplete="name"
                                :placeholder="
                                    t('transaction.sourceBeneficiaryName')
                                "
                                class="bank-input"
                            />
                        </label>
                        <label>
                            <span class="bank-label">{{
                                t('transaction.customerSourceAccountNumber')
                            }}</span>
                            <input
                                v-model="sourceAccountNumber"
                                type="text"
                                inputmode="text"
                                autocomplete="off"
                                :placeholder="
                                    t('transaction.customerSourceAccountNumber')
                                "
                                class="bank-input"
                            />
                        </label>
                    </div>
                </div>
            </section>

            <section
                class="mt-4 rounded-2xl border border-line bg-card p-4 shadow-sm sm:p-5"
                aria-labelledby="transfer-receive-account-title"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3
                            id="transfer-receive-account-title"
                            class="text-sm font-black text-ink"
                        >
                            {{ t('transaction.receiveLeg') }}:
                            {{ t('transaction.systemReceives') }}
                        </h3>
                        <p class="mt-1 text-xs text-slate">
                            {{ t('transaction.systemReceiveCompanyHint') }}
                        </p>
                    </div>
                    <span class="text-xs font-bold text-brand">{{
                        t('component.required')
                    }}</span>
                </div>

                <div
                    class="mt-3 flex items-center gap-3 rounded-field border border-line bg-mist/50 px-3 py-2.5"
                >
                    <span
                        class="grid size-9 shrink-0 place-items-center rounded-full bg-brand text-xs font-black text-white"
                    >
                        {{ selectedSourceCompany.slice(0, 1).toUpperCase() }}
                    </span>
                    <span class="min-w-0">
                        <span class="block text-xs font-semibold text-slate">
                            {{ t('transaction.systemReceiveCompany') }}
                        </span>
                        <span class="block truncate text-sm font-black">
                            {{ selectedSourceCompany }}
                            · {{ sourceAccountType.toUpperCase() }}
                        </span>
                    </span>
                </div>

                <AccountTile
                    class="mt-3"
                    v-model="toId"
                    :accounts="receiveAccounts"
                    :label="t('transaction.systemReceiveAccount')"
                    :exclude="fromId ? [fromId] : []"
                />
                <p
                    v-if="receiveAccounts.length === 0"
                    class="mt-2 text-xs font-semibold text-brand"
                >
                    {{ t('transaction.noSystemAccount') }}
                </p>
            </section>

            <section
                class="mt-4 rounded-2xl border border-line bg-card p-4 shadow-sm sm:p-5"
                aria-labelledby="transfer-payout-company-title"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3
                            id="transfer-payout-company-title"
                            class="text-sm font-black text-ink"
                        >
                            {{ t('transaction.payoutLeg') }}:
                            {{ t('transaction.systemSends') }}
                        </h3>
                        <p class="mt-1 text-xs text-slate">
                            {{ t('transaction.systemPayoutCompanyHint') }}
                        </p>
                    </div>
                    <span class="text-xs font-bold text-brand">{{
                        t('component.required')
                    }}</span>
                </div>

                <div class="mt-3 max-w-md">
                    <span class="bank-label">{{
                        t('transaction.payBank')
                    }}</span>
                    <div class="mt-1.5 grid grid-cols-2 gap-2">
                        <label
                            class="bank-choice flex cursor-pointer items-center gap-2 rounded-field border bg-card px-3 py-2.5 transition"
                            :class="
                                payoutAccountType === 'pay'
                                    ? 'border-brand ring-1 ring-brand/20'
                                    : 'border-line hover:border-ink/30'
                            "
                        >
                            <input
                                v-model="payoutAccountType"
                                type="radio"
                                value="pay"
                                class="accent-brand"
                            />
                            <span class="text-sm font-bold">Pay</span>
                        </label>
                        <label
                            class="bank-choice flex cursor-pointer items-center gap-2 rounded-field border bg-card px-3 py-2.5 transition"
                            :class="
                                payoutAccountType === 'bank'
                                    ? 'border-brand ring-1 ring-brand/20'
                                    : 'border-line hover:border-ink/30'
                            "
                        >
                            <input
                                v-model="payoutAccountType"
                                type="radio"
                                value="bank"
                                class="accent-brand"
                            />
                            <span class="text-sm font-bold">Bank</span>
                        </label>
                    </div>
                </div>

                <div
                    class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4"
                    role="radiogroup"
                    aria-label="System payout company"
                >
                    <button
                        v-for="company in payoutCompanies"
                        :key="company.id ?? company.name"
                        type="button"
                        role="radio"
                        :aria-checked="selectedPayoutCompany === company.name"
                        class="group flex min-h-16 items-center gap-2 rounded-xl border px-3 py-2 text-left transition"
                        :class="
                            selectedPayoutCompany === company.name
                                ? 'border-brand bg-brand-soft text-brand shadow-sm ring-2 ring-brand/15'
                                : 'border-line bg-mist/40 text-ink hover:border-brand/40 hover:bg-brand-soft/40'
                        "
                        @click="selectedPayoutCompany = company.name"
                    >
                        <span
                            class="grid size-9 shrink-0 place-items-center overflow-hidden rounded-xl text-sm font-black"
                            :class="
                                selectedPayoutCompany === company.name
                                    ? 'bg-brand text-white'
                                    : 'bg-white text-brand shadow-sm'
                            "
                        >
                            <img
                                v-if="company.logoUrl"
                                :src="company.logoUrl"
                                :alt="`${company.name} logo`"
                                class="size-full object-contain p-1"
                            />
                            <span v-else>
                                {{ company.name.slice(0, 1).toUpperCase() }}
                            </span>
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-xs font-black">{{
                                company.name
                            }}</span>
                            <span class="mt-0.5 block text-[10px] text-slate">
                                {{
                                    props.accounts.filter(
                                        (account) =>
                                            account.company === company.name,
                                    ).length
                                }}
                                {{ t('transaction.accounts') }}
                            </span>
                        </span>
                    </button>
                </div>

                <AccountTile
                    class="mt-3"
                    v-model="fromId"
                    :accounts="payoutAccounts"
                    :label="t('transaction.systemPayoutAccount')"
                    :must-cover="amount"
                    :exclude="toId ? [toId] : []"
                />

                <h4 class="mt-4 text-sm font-black text-ink">
                    {{ t('transaction.customerDestinationAccount') }}
                </h4>
                <p class="mt-1 text-xs text-slate">
                    {{ t('transaction.customerDestinationHint') }}
                </p>
                <div class="mt-3 grid gap-3 lg:grid-cols-2">
                    <label>
                        <span class="bank-label">{{
                            t('transaction.destinationBeneficiaryName')
                        }}</span>
                        <input
                            v-model="destinationCustomerName"
                            type="text"
                            autocomplete="name"
                            :placeholder="
                                t('transaction.destinationBeneficiaryName')
                            "
                            class="bank-input"
                        />
                    </label>
                    <label>
                        <span class="bank-label">{{
                            t('transaction.customerDestinationAccountNumber')
                        }}</span>
                        <input
                            v-model="destinationAccountNumber"
                            type="text"
                            inputmode="text"
                            autocomplete="off"
                            :placeholder="
                                t(
                                    'transaction.customerDestinationAccountNumber',
                                )
                            "
                            class="bank-input"
                        />
                    </label>
                </div>
            </section>

            <div class="mt-5">
                <BigAmountInput
                    v-model="amount"
                    :label="t('transaction.transferAmount')"
                />
            </div>

            <div
                class="mt-4 flex items-center justify-between rounded-field bg-mist px-4 py-3"
            >
                <p class="text-[13px] font-semibold text-slate">
                    {{ t('transaction.fee') }}
                    <span class="font-normal"
                        >({{ t('transaction.commissionTier') }})</span
                    >
                </p>
                <p class="money text-sm font-bold">
                    {{ mmk(feeNum) }}
                    <span class="text-[10px] text-slate">MMK</span>
                </p>
            </div>

            <div class="mt-4">
                <FeePaymentSelector
                    v-model="feePaymentMethod"
                    v-model:fee-account-id="feeAccountId"
                    :fee="feeNum"
                    :fee-accounts="feeAccounts"
                    :account-included-in-transaction="true"
                />
            </div>

            <div class="mt-5">
                <label class="bank-label" for="transfer-description">{{
                    t('transaction.description')
                }}</label>
                <div class="relative">
                    <textarea
                        id="transfer-description"
                        v-model="description"
                        maxlength="250"
                        autocomplete="off"
                        :placeholder="t('transaction.transfer')"
                        class="bank-input resize-none pb-7"
                        aria-describedby="transfer-description-count"
                    />
                    <span
                        id="transfer-description-count"
                        class="money pointer-events-none absolute right-3.5 bottom-2.5 text-[11px] text-slate"
                    >
                        ({{ description.length }}/250)
                    </span>
                </div>
            </div>

            <div v-if="needsCashFeeDenoms" class="mt-5">
                <DenomDrawer
                    v-model="feeDenoms"
                    :notes="notes"
                    :target="feeNum"
                    :enforce-stock="false"
                    :label="t('transaction.cashFeeReceivedNotes')"
                    id-prefix="transfer-fee-denomination"
                />
                <p class="mt-2 text-xs font-semibold text-slate">
                    {{ t('transaction.cashFeeReceivedHint') }}
                </p>
            </div>

            <p
                v-for="(msg, key) in errors"
                :key="key"
                class="mt-3 text-sm font-semibold text-brand"
            >
                {{ msg }}
            </p>

            <div class="mt-6 flex justify-end">
                <button
                    type="button"
                    :disabled="!ready"
                    @click="step = 'review'"
                    class="bank-button bank-button-primary px-7"
                >
                    {{ t('common.continueReview') }}
                </button>
            </div>
        </section>

        <!-- ===== Review ===== -->
        <section
            v-else-if="view === 'entry'"
            class="bank-form-shell mt-5 max-w-xl"
        >
            <h2 class="text-base font-bold">
                {{ t('transaction.transfer') }} {{ t('common.review') }}
            </h2>
            <p class="mt-1 text-[13px] text-slate">
                {{ t('transaction.reviewHint') }}
            </p>

            <div
                class="mt-5 rounded-field border border-balance/25 bg-balance/5 px-4 py-4"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-black uppercase text-balance">
                            {{ t('transaction.customerSends') }}
                        </p>
                        <p class="mt-1 text-[13px] font-semibold text-slate">
                            {{ t('transaction.amount') }} +
                            {{ t('transaction.fee') }}
                        </p>
                    </div>
                    <p class="money text-right text-2xl font-black text-balance">
                        {{ mmk(customerTotalDue) }}
                        <span class="text-xs text-slate">MMK</span>
                    </p>
                </div>
            </div>

            <dl
                class="transfer-review-list mt-5 divide-y divide-line border-y border-line"
            >
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.customerSourceCompany') }}
                    </dt>
                    <dd class="text-right font-bold">
                        {{ selectedSourceCompany }}
                        <span
                            class="block text-[11px] font-medium text-slate"
                            >{{ sourceAccountType.toUpperCase() }}</span
                        >
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.sourceBeneficiaryName') }}
                    </dt>
                    <dd class="text-right font-bold">{{ customerName }}</dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.customerSourceAccountNumber') }}
                    </dt>
                    <dd class="text-right font-bold">
                        {{ sourceAccountNumber }}
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.systemReceiveAccount') }}
                    </dt>
                    <dd class="text-right font-bold">
                        {{ to?.name }}
                        <span
                            class="block text-[11px] font-medium text-slate"
                            >{{ to?.company }}</span
                        >
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.systemPayoutAccount') }}
                    </dt>
                    <dd class="text-right font-bold">
                        {{ from?.name }}
                        <span
                            class="block text-[11px] font-medium text-slate"
                            >{{ from?.company }}</span
                        >
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.customerDestinationAccount') }}
                    </dt>
                    <dd class="text-right font-bold">
                        {{ from?.company }}
                        <span class="block text-[11px] font-medium text-slate"
                            >{{ destinationCustomerName }} ·
                            {{ destinationAccountNumber }}</span
                        >
                    </dd>
                </div>
                <div class="flex justify-between py-3">
                    <dt class="text-sm text-slate">
                        {{ t('transaction.amount') }}
                    </dt>
                    <dd class="money text-lg font-bold">
                        {{ mmk(amount) }}
                        <span class="text-[11px] text-slate">MMK</span>
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">{{ t('transaction.fee') }}</dt>
                    <dd class="money font-bold">{{ mmk(feeNum) }} MMK</dd>
                </div>
                <div
                    v-if="needsCashFeeDenoms"
                    class="flex justify-between py-3 text-sm"
                >
                    <dt class="font-bold">
                        {{ t('transaction.cashFeeReceivedNotes') }}
                    </dt>
                    <dd class="money font-bold text-balance">
                        +{{ mmk(feeDenomTotal) }} MMK
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.feePaymentMethod') }}
                    </dt>
                    <dd class="text-right font-bold">
                        {{
                            feePaymentMethod === 'cash'
                                ? t('transaction.feePaymentCash')
                                : t('transaction.feePaymentAccount')
                        }}
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.receiveCommission') }}
                    </dt>
                    <dd class="money font-bold text-balance">
                        +{{ mmk(receiveCommissionNum) }} MMK
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.payoutCommission') }}
                    </dt>
                    <dd class="money font-bold text-balance">
                        +{{ mmk(payoutCommissionNum) }} MMK
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="font-bold">
                        {{ t('transaction.accountCredited') }}: {{ to?.name }}
                    </dt>
                    <dd class="money font-bold text-balance">
                        +{{ mmk(receiveCredit) }} MMK
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="font-bold">
                        {{ t('transaction.accountDeducted') }}: {{ from?.name }}
                    </dt>
                    <dd
                        class="money font-bold"
                        :class="
                            payoutBalanceChange < 0
                                ? 'text-brand'
                                : 'text-balance'
                        "
                    >
                        {{ payoutBalanceChange > 0 ? '+' : ''
                        }}{{ mmk(payoutBalanceChange) }} MMK
                    </dd>
                </div>
                <div
                    v-if="description"
                    class="flex justify-between gap-6 py-3 text-sm"
                >
                    <dt class="shrink-0 text-slate">
                        {{ t('transaction.description') }}
                    </dt>
                    <dd class="text-right">{{ description }}</dd>
                </div>
            </dl>

            <div class="mt-6 flex gap-2">
                <button
                    type="button"
                    @click="step = 'form'"
                    class="bank-button bank-button-secondary rounded-pill"
                >
                    {{ t('common.back') }}
                </button>
                <button
                    type="button"
                    :disabled="submitting"
                    @click="submit"
                    class="bank-button bank-button-primary flex-1 rounded-pill disabled:opacity-40"
                >
                    {{
                        submitting
                            ? t('common.submitting')
                            : t('transaction.confirmTransfer')
                    }}
                </button>
            </div>
        </section>
    </BankLayout>
</template>

<style scoped>
@media (max-width: 420px) {
    .transfer-receipt-list > div,
    .transfer-review-list > div {
        align-items: flex-start;
        flex-direction: column;
        gap: 0.25rem;
    }

    .transfer-receipt-list dd,
    .transfer-review-list dd {
        overflow-wrap: anywhere;
        text-align: left;
        width: 100%;
    }
}
</style>
