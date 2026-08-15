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
import { useLocale } from '@/lib/i18n';
import type { TransactionHistoryRow } from '@/types/domain';

type TransferAccount = {
    id: number;
    company: string;
    company_id?: number | null;
    company_logo_url?: string | null;
    name: string;
    number?: string;
    balance: string;
};

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
        accounts: TransferAccount[];
        sendMoneyAccounts?: TransferAccount[];
        receiveMoneyAccounts?: TransferAccount[];
        feeAccounts: {
            id: number;
            company: string;
            name: string;
            number?: string;
            balance: string;
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
        sendMoneyAccounts: () => [],
        receiveMoneyAccounts: () => [],
    },
);

const step = ref<'form' | 'review'>('form');
const fromId = ref<number | null>(null);
const toId = ref<number | null>(null);
const selectedPayoutCompany = ref('');
const selectedSourceCompany = ref('');
const sourceAccountNumber = ref('');
const destinationCustomerName = ref('');
const destinationAccountNumber = ref('');
const amount = ref(0);
const customerName = ref('');
const customerPhone = ref('');
const description = ref('');
const feeDenoms = ref<Record<number, number>>({});
const showFeeDenoms = ref(true);
const submitting = ref(false);
const errors = ref<Record<string, string>>({});
const feePaymentMethod = ref<FeePaymentMethod>('cash');
const feeAccountId = ref<number | null>(null);
const historySearch = ref('');
const historyStatus = ref('all');
const historyDateFrom = ref('');
const historyDateTo = ref('');
const failedCompanyLogos = ref<Set<string>>(new Set());
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
            ].some((value) =>
                String(value ?? '')
                    .toLowerCase()
                    .includes(query),
            );
        const matchesStatus =
            historyStatus.value === 'all' || row.status === historyStatus.value;
        const transactionDate = row.created_at?.slice(0, 10) ?? '';
        const matchesDateFrom =
            historyDateFrom.value === '' ||
            transactionDate >= historyDateFrom.value;
        const matchesDateTo =
            historyDateTo.value === '' ||
            transactionDate <= historyDateTo.value;

        return (
            matchesSearch && matchesStatus && matchesDateFrom && matchesDateTo
        );
    });
});
const receiveCommissionNum = computed(() =>
    Number(props.receiveCommission ?? 0),
);
const payoutCommissionNum = computed(() => Number(props.payoutCommission ?? 0));
const from = computed(() =>
    props.sendMoneyAccounts.find((account) => account.id === fromId.value),
);
const to = computed(() =>
    props.receiveMoneyAccounts.find((account) => account.id === toId.value),
);
const sourceCompanies = computed(() => {
    const unique = new Map<
        string,
        { id: number | null; name: string; logoUrl: string | null }
    >();

    for (const account of props.receiveMoneyAccounts) {
        if (!account.company || unique.has(account.company)) {
            continue;
        }

        unique.set(account.company, {
            id: account.company_id ?? null,
            name: account.company,
            logoUrl: account.company_logo_url ?? null,
        });
    }

    return Array.from(unique.values());
});
const payoutCompanies = computed(() => {
    const unique = new Map<
        string,
        { id: number | null; name: string; logoUrl: string | null }
    >();

    for (const account of props.sendMoneyAccounts) {
        if (!account.company || unique.has(account.company)) {
            continue;
        }

        unique.set(account.company, {
            id: account.company_id ?? null,
            name: account.company,
            logoUrl: account.company_logo_url ?? null,
        });
    }

    return Array.from(unique.values());
});
const receiveAccounts = computed(() =>
    props.receiveMoneyAccounts.filter(
        (account) => account.company === selectedSourceCompany.value,
    ),
);
const payoutAccounts = computed(() =>
    props.sendMoneyAccounts.filter(
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
        customerPhone.value.trim().length > 0 &&
        (!needsCashFeeDenoms.value || feeDenomTotal.value === feeNum.value) &&
        !floatLocked.value &&
        !cashierLocked.value,
);

const readyIssue = computed(() => {
    if (cashierLocked.value) return t('transaction.cashierLocked');
    if (floatLocked.value) return t('transaction.floatLocked');
    if (toId.value === null) return 'Choose a Receive Money account.';
    if (sourceAccountNumber.value.trim() === '') {
        return 'Enter the customer source account number.';
    }
    if (fromId.value === null) return 'Choose a Send Money account.';
    if (destinationCustomerName.value.trim() === '') {
        return 'Enter the recipient name.';
    }
    if (destinationAccountNumber.value.trim() === '') {
        return 'Enter the recipient account number.';
    }
    if (amount.value <= 0) return 'Enter the transfer amount.';
    if (customerName.value.trim() === '') return 'Enter customer name.';
    if (customerPhone.value.trim() === '') return 'Enter customer phone.';
    if (needsCashFeeDenoms.value && feeDenomTotal.value !== feeNum.value) {
        return t('transaction.cashFeeReceivedHint');
    }

    return '';
});

function companyKey(company: { id: number | null; name: string }): string {
    return company.id !== null ? `id:${company.id}` : `name:${company.name}`;
}

function hasCompanyLogo(company: {
    id: number | null;
    name: string;
    logoUrl: string | null;
}): boolean {
    return Boolean(
        company.logoUrl && !failedCompanyLogos.value.has(companyKey(company)),
    );
}

function markCompanyLogoFailed(company: {
    id: number | null;
    name: string;
    logoUrl: string | null;
}): void {
    failedCompanyLogos.value = new Set([
        ...failedCompanyLogos.value,
        companyKey(company),
    ]);
}

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

watch([selectedSourceCompany, receiveAccounts], () => {
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
    return {};
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
            source_account_type: 'account',
            source_provider: selectedSourceCompany.value.trim(),
            source_account_number: sourceAccountNumber.value.trim(),
            to_account_id: toId.value,
            destination_provider:
                from.value?.company ?? selectedPayoutCompany.value,
            destination_customer_name: destinationCustomerName.value.trim(),
            destination_account_number: destinationAccountNumber.value.trim(),
            amount: amount.value,
            customer_name: customerName.value.trim(),
            customer_phone: customerPhone.value.trim(),
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
            class="bank-form-shell mt-5 max-w-5xl p-5 sm:p-6"
            :class="
                floatLocked || cashierLocked
                    ? 'pointer-events-none opacity-50'
                    : ''
            "
        >
            <h2 class="text-base font-bold">
                {{ t('transaction.enterTransferDetails') }}
            </h2>

            <section class="mt-4 space-y-3">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-sm font-black text-ink">
                        {{ t('transaction.transferCustomerInfo') }}
                    </h3>
                </div>

                <div class="mt-3 space-y-3">
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
                            class="mt-1.5 flex gap-2 overflow-x-auto pb-1.5"
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
                                class="group flex min-h-12 shrink-0 items-center gap-2 rounded-field border px-2.5 py-1.5 text-left transition"
                                :class="[
                                    selectedSourceCompany === company.name
                                        ? 'border-brand bg-brand-soft text-brand shadow-sm ring-2 ring-brand/15'
                                        : 'border-line bg-mist/40 text-ink hover:border-brand/40 hover:bg-brand-soft/40',
                                    hasCompanyLogo(company)
                                        ? 'min-w-16 justify-center'
                                        : 'min-w-36',
                                ]"
                                @click="selectedSourceCompany = company.name"
                            >
                                <span
                                    v-if="hasCompanyLogo(company)"
                                    class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-lg border border-line bg-card text-sm font-black shadow-sm"
                                    :class="
                                        selectedSourceCompany === company.name
                                            ? 'bg-brand text-white'
                                            : 'bg-white text-brand shadow-sm'
                                    "
                                >
                                    <img
                                        :src="company.logoUrl ?? ''"
                                        :alt="`${company.name} logo`"
                                        class="size-full object-contain p-0.5"
                                        @error="markCompanyLogoFailed(company)"
                                    />
                                </span>
                                <span v-else class="min-w-0">
                                    <span
                                        class="block truncate text-xs font-black"
                                        >{{ company.name }}</span
                                    >
                                </span>
                            </button>
                        </div>
                    </div>

                    <div class="grid gap-3 md:grid-cols-3">
                        <label>
                            <span class="bank-label bank-required">{{
                                t('transaction.sourceBeneficiaryName')
                            }}</span>
                            <input
                                v-model="customerName"
                                type="text"
                                autocomplete="name"
                                :placeholder="
                                    t('transaction.sourceBeneficiaryName')
                                "
                                class="bank-input min-h-12 bg-mist"
                            />
                        </label>
                        <label>
                            <span class="bank-label bank-required">{{
                                t('transaction.customerPhone')
                            }}</span>
                            <input
                                v-model="customerPhone"
                                type="tel"
                                autocomplete="tel"
                                inputmode="tel"
                                placeholder=" "
                                class="bank-input min-h-12 bg-mist"
                            />
                        </label>
                        <label>
                            <span class="bank-label bank-required">{{
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
                                class="bank-input min-h-12 bg-mist"
                            />
                        </label>
                    </div>
                </div>
            </section>

            <section
                class="mt-4 border-t border-line pt-4"
                aria-labelledby="transfer-receive-account-title"
            >
                <div class="flex items-start justify-between gap-3">
                    <h3
                        id="transfer-receive-account-title"
                        class="text-sm font-black text-ink"
                    >
                        {{ t('transaction.receiveLeg') }}:
                        {{ t('transaction.systemReceives') }}
                    </h3>
                </div>

                <AccountTile
                    class="mt-3"
                    v-model="toId"
                    :accounts="receiveAccounts"
                    :label="t('transaction.systemReceiveAccount')"
                    :exclude="fromId ? [fromId] : []"
                    compact
                />
                <p
                    v-if="receiveAccounts.length === 0"
                    class="mt-2 text-xs font-semibold text-brand"
                >
                    {{ t('transaction.noSystemAccount') }}
                </p>
            </section>

            <section
                class="mt-4 border-t border-line pt-4"
                aria-labelledby="transfer-payout-company-title"
            >
                <div class="flex items-start justify-between gap-3">
                    <h3
                        id="transfer-payout-company-title"
                        class="text-sm font-black text-ink"
                    >
                        {{ t('transaction.payoutLeg') }}:
                        {{ t('transaction.systemSends') }}
                    </h3>
                </div>

                <div
                    class="mt-3 flex gap-2 overflow-x-auto pb-1.5"
                    role="radiogroup"
                    aria-label="System payout company"
                >
                    <button
                        v-for="company in payoutCompanies"
                        :key="company.id ?? company.name"
                        type="button"
                        role="radio"
                        :aria-checked="selectedPayoutCompany === company.name"
                        class="group flex min-h-12 shrink-0 items-center gap-2 rounded-field border px-2.5 py-1.5 text-left transition"
                        :class="[
                            selectedPayoutCompany === company.name
                                ? 'border-brand bg-brand-soft text-brand shadow-sm ring-2 ring-brand/15'
                                : 'border-line bg-mist/40 text-ink hover:border-brand/40 hover:bg-brand-soft/40',
                            hasCompanyLogo(company)
                                ? 'min-w-16 justify-center'
                                : 'min-w-36',
                        ]"
                        @click="selectedPayoutCompany = company.name"
                    >
                        <span
                            v-if="hasCompanyLogo(company)"
                            class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-lg border border-line bg-card text-sm font-black shadow-sm"
                            :class="
                                selectedPayoutCompany === company.name
                                    ? 'bg-brand text-white'
                                    : 'bg-white text-brand shadow-sm'
                            "
                        >
                            <img
                                :src="company.logoUrl ?? ''"
                                :alt="`${company.name} logo`"
                                class="size-full object-contain p-0.5"
                                @error="markCompanyLogoFailed(company)"
                            />
                        </span>
                        <span v-else class="min-w-0">
                            <span class="block truncate text-xs font-black">{{
                                company.name
                            }}</span>
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
                    compact
                />

                <h4 class="mt-4 text-sm font-black text-ink">
                    {{ t('transaction.customerDestinationAccount') }}
                </h4>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <label>
                        <span class="bank-label bank-required">{{
                            t('transaction.destinationBeneficiaryName')
                        }}</span>
                        <input
                            v-model="destinationCustomerName"
                            type="text"
                            autocomplete="name"
                            :placeholder="
                                t('transaction.destinationBeneficiaryName')
                            "
                            class="bank-input min-h-12 bg-mist"
                        />
                    </label>
                    <label>
                        <span class="bank-label bank-required">{{
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
                            class="bank-input min-h-12 bg-mist"
                        />
                    </label>
                </div>
            </section>

            <div class="mt-5">
                <BigAmountInput
                    v-model="amount"
                    :label="t('transaction.transferAmount')"
                    required
                    compact
                />
            </div>

            <div
                class="mt-4 flex items-center justify-between rounded-field bg-mist px-4 py-3"
            >
                <p class="text-[13px] font-semibold text-slate">
                    {{ t('transaction.fee') }}
                    <span class="font-normal"
                        >({{ t('transaction.transferFeeTier') }})</span
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
                    compact
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
                        rows="2"
                        class="bank-input min-h-12 resize-none bg-mist pb-7"
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

            <section
                v-if="needsCashFeeDenoms"
                class="mt-5 overflow-hidden rounded-field border border-brand/20 bg-card"
            >
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-3 bg-brand-soft/55 px-3 py-2.5 text-left"
                    :aria-expanded="showFeeDenoms"
                    @click="showFeeDenoms = !showFeeDenoms"
                >
                    <span class="text-sm font-bold">{{
                        t('transaction.cashFeeReceivedNotes')
                    }}</span>
                    <span class="money text-sm font-black text-brand">
                        {{ mmk(feeDenomTotal) }} MMK
                    </span>
                </button>
                <div v-show="showFeeDenoms" class="p-2.5 sm:p-3">
                    <DenomDrawer
                        v-model="feeDenoms"
                        :notes="notes"
                        :target="feeNum"
                        :enforce-stock="false"
                        :show-title="false"
                        compact
                        id-prefix="transfer-fee-denomination"
                    />
                </div>
            </section>

            <p
                v-for="(msg, key) in errors"
                :key="key"
                class="mt-3 text-sm font-semibold text-brand"
            >
                {{ msg }}
            </p>

            <div class="mt-6 border-t border-line pt-4">
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p
                        class="min-h-5 text-xs font-bold"
                        :class="ready ? 'text-balance' : 'text-brand'"
                    >
                        {{
                            ready ? t('transaction.readyForReview') : readyIssue
                        }}
                    </p>
                    <button
                        type="button"
                        :disabled="!ready"
                        @click="step = 'review'"
                        class="bank-button bank-button-primary w-full px-7 sm:w-auto"
                    >
                        {{ t('common.continueReview') }}
                    </button>
                </div>
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
                        <p class="text-xs font-black text-balance uppercase">
                            {{ t('transaction.customerSends') }}
                        </p>
                        <p class="mt-1 text-[13px] font-semibold text-slate">
                            {{ t('transaction.amount') }} +
                            {{ t('transaction.fee') }}
                        </p>
                    </div>
                    <p
                        class="money text-right text-2xl font-black text-balance"
                    >
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
                        <span class="block text-[11px] font-medium text-slate"
                            >Receive Money</span
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
                        {{ t('transaction.customerPhone') }}
                    </dt>
                    <dd class="text-right font-bold">{{ customerPhone }}</dd>
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
