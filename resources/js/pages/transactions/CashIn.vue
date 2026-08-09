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

const props = withDefaults(
    defineProps<{
        role: 'admin' | 'cashier' | 'teller';
        view?: 'entry' | 'history';
        announcement?: string | null;
        notificationCount?: number;
        float: { status: string } | null;
        notes: number[];
        floatStock: Record<number, number>;
        cashInStock?: Record<number, number>;
        accounts: {
            id: number;
            features?: string[];
            company: string;
            company_id?: number | null;
            company_logo_url?: string | null;
            service?: string;
            service_type_id?: number | null;
            name: string;
            number?: string;
            balance: string;
        }[];
        feeAccounts: {
            id: number;
            company: string;
            service?: string;
            name: string;
            number?: string;
            balance: string;
        }[];
        fee: string;
        requiresDenominations: boolean;
        cashInRequiresDenominations: boolean;
        completed?: {
            id: number;
            amount: string;
            fee_amount: string;
            status: string;
            created_at: string;
            from_label: string;
            to_label: string;
        } | null;
        history?: TransactionHistoryRow[];
    }>(),
    {
        view: 'entry',
        history: () => [],
    },
);

const step = ref<'form' | 'review'>('form');
const accountId = ref<number | null>(null);
const selectedCompany = ref('');
const customerName = ref('');
const customerPhone = ref('');
const amount = ref(0);
const screenshot = ref<File | null>(null);
const receivedDenoms = ref<Record<number, number>>({});
const changeDenoms = ref<Record<number, number>>({});
const showReceivedDenoms = ref(true);
const showChangeDenoms = ref(true);
const feePaymentMethod = ref<FeePaymentMethod>('cash');
const feeAccountId = ref<number | null>(null);
const submitting = ref(false);
const errors = ref<Record<string, string>>({});
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
const account = computed(() =>
    props.accounts.find((a) => a.id === accountId.value),
);
const feeAccount = computed(() =>
    props.feeAccounts.find((a) => a.id === feeAccountId.value),
);
const cashInAccountCounts = computed(() => {
    const counts = new Map<string, number>();

    for (const account of props.accounts) {
        counts.set(account.company, (counts.get(account.company) ?? 0) + 1);
    }

    return counts;
});
const companies = computed(() => {
    const unique = new Map<
        string,
        { id: number | null; name: string; logoUrl: string | null }
    >();

    for (const account of props.accounts) {
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
const visibleAccounts = computed(() =>
    props.accounts.filter(
        (account) =>
            !selectedCompany.value || account.company === selectedCompany.value,
    ),
);
const receivedTotal = computed(() =>
    props.notes.reduce(
        (sum, note) => sum + note * (receivedDenoms.value[note] ?? 0),
        0,
    ),
);
const cashInNeedsDenoms = computed(() => props.cashInRequiresDenominations);
const accountBalanceRequired = computed(() => amount.value);
const cashSettlementAmount = computed(
    () => amount.value + (feePaymentMethod.value === 'cash' ? feeNum.value : 0),
);
const customerTotalDue = computed(() => amount.value + feeNum.value);
const amountReceived = computed(() =>
    cashInNeedsDenoms.value ? receivedTotal.value : amount.value,
);
const changeDue = computed(() =>
    Math.max(0, amountReceived.value - cashSettlementAmount.value),
);
const cashDifference = computed(
    () => amountReceived.value - cashSettlementAmount.value,
);
const cashShortfall = computed(() => Math.max(0, -cashDifference.value));
const changeTotal = computed(() =>
    props.notes.reduce(
        (sum, note) => sum + note * (changeDenoms.value[note] ?? 0),
        0,
    ),
);
const changeBalanced = computed(() => changeTotal.value === changeDue.value);
const netReceivedDenoms = computed(() =>
    props.notes.reduce(
        (stock, note) => {
            stock[note] = Math.max(
                0,
                (receivedDenoms.value[note] ?? 0) -
                    (changeDenoms.value[note] ?? 0),
            );

            return stock;
        },
        {} as Record<number, number>,
    ),
);
const handoffDenoms = computed(() => {
    let remaining = cashSettlementAmount.value;
    const result: Record<number, number> = {};

    for (const note of [...props.notes].sort((a, b) => b - a)) {
        const available = netReceivedDenoms.value[note] ?? 0;
        const quantity = Math.min(Math.floor(remaining / note), available);

        if (quantity > 0) {
            result[note] = quantity;
            remaining -= quantity * note;
        }
    }

    for (const note of [...props.notes].sort((a, b) => b - a)) {
        const alreadyUsed = result[note] ?? 0;
        const available = Math.max(
            0,
            (props.floatStock[note] ?? 0) +
                (netReceivedDenoms.value[note] ?? 0) -
                alreadyUsed,
        );
        const quantity = Math.min(Math.floor(remaining / note), available);

        if (quantity > 0) {
            result[note] = alreadyUsed + quantity;
            remaining -= quantity * note;
        }
    }

    return result;
});
const handoffTotal = computed(() =>
    props.notes.reduce(
        (sum, note) => sum + note * (handoffDenoms.value[note] ?? 0),
        0,
    ),
);
const tellerDenominationDelta = computed(() =>
    props.notes
        .map((note) => ({
            note,
            quantity:
                (receivedDenoms.value[note] ?? 0) -
                (changeDenoms.value[note] ?? 0) -
                (handoffDenoms.value[note] ?? 0),
        }))
        .filter((line) => line.quantity !== 0),
);
const floatLocked = computed(
    () => props.role === 'teller' && props.float?.status !== 'ACTIVE',
);
const cashierLocked = computed(() => props.role === 'cashier');
const canCountCash = computed(
    () =>
        accountId.value !== null &&
        amount.value > 0 &&
        !floatLocked.value &&
        !cashierLocked.value,
);
const feePaymentValid = computed(
    () =>
        feeNum.value <= 0 ||
        feePaymentMethod.value === 'cash' ||
        feeAccountId.value !== null,
);
const ready = computed(
    () =>
        accountId.value !== null &&
        customerName.value.trim().length > 0 &&
        customerPhone.value.trim().length > 0 &&
        amount.value > 0 &&
        Number(account.value?.balance ?? 0) >= accountBalanceRequired.value &&
        feePaymentValid.value &&
        (!cashInNeedsDenoms.value ||
            receivedTotal.value >= cashSettlementAmount.value) &&
        (props.role !== 'teller' ||
            handoffTotal.value === cashSettlementAmount.value) &&
        (!cashInNeedsDenoms.value ||
            changeDue.value === 0 ||
            changeTotal.value === changeDue.value) &&
        !floatLocked.value &&
        !cashierLocked.value,
);
const readyIssue = computed(() => {
    if (floatLocked.value) {
        return t(
            'transaction.floatLocked',
            'Activate your teller float first.',
        );
    }

    if (cashierLocked.value) {
        return t(
            'transaction.cashierLocked',
            'Cashier review mode is read-only.',
        );
    }

    if (accountId.value === null) {
        return t(
            'transaction.chooseAccountFirst',
            'Choose the KPay account first.',
        );
    }

    if (amount.value <= 0) {
        return t(
            'transaction.enterCashInAmountFirst',
            'Enter the Cash In amount.',
        );
    }

    if (Number(account.value?.balance ?? 0) < accountBalanceRequired.value) {
        return t(
            'transaction.accountBalanceNotEnough',
            'Selected account balance is not enough.',
        );
    }

    if (customerName.value.trim().length === 0) {
        return t('transaction.customerNameRequired', 'Enter customer name.');
    }

    if (customerPhone.value.trim().length === 0) {
        return t('transaction.customerPhoneRequired', 'Enter customer phone.');
    }

    if (!feePaymentValid.value) {
        return t('transaction.feeAccountRequired', 'Choose the fee account.');
    }

    if (cashInNeedsDenoms.value && receivedTotal.value <= 0) {
        return t('transaction.countCustomerCash', 'Count the customer cash.');
    }

    if (cashInNeedsDenoms.value && cashShortfall.value > 0) {
        return `${t('transaction.cashShort', 'Received cash is short.')} ${cashShortfall.value.toLocaleString()} MMK`;
    }

    if (
        cashInNeedsDenoms.value &&
        changeDue.value > 0 &&
        !changeBalanced.value
    ) {
        return `${t('transaction.changeMyVault', 'Change from my teller vault')}: ${changeDue.value.toLocaleString()} MMK`;
    }

    if (
        props.role === 'teller' &&
        handoffTotal.value !== cashSettlementAmount.value
    ) {
        return t(
            'transaction.cashierHandoffNotReady',
            'Cashier handoff notes are not ready.',
        );
    }

    return '';
});

watch(
    companies,
    (values) => {
        if (!values.some((company) => company.name === selectedCompany.value)) {
            selectedCompany.value = values[0]?.name ?? '';
        }
    },
    { immediate: true },
);
watch(selectedCompany, () => {
    if (
        !visibleAccounts.value.some((account) => account.id === accountId.value)
    ) {
        accountId.value = null;
    }
});
watch(canCountCash, (canCount) => {
    if (canCount) {
        showReceivedDenoms.value = true;
    }
});
watch(changeDue, (nextChange, previousChange) => {
    if (nextChange > 0 && previousChange <= 0) {
        showChangeDenoms.value = true;
    }
});

let feeTimer: ReturnType<typeof setTimeout>;
watch([amount, accountId], ([nextAmount, nextAccount]) => {
    clearTimeout(feeTimer);

    if (nextAmount > 0 && nextAccount) {
        feeTimer = setTimeout(
            () =>
                router.reload({
                    only: ['fee'],
                    data: { amount: nextAmount, account_id: nextAccount },
                    headers: authHeaders(),
                }),
            350,
        );
    }
});

const mmk = (value: string | number) => Number(value).toLocaleString();
const denominationSummary = (map: Record<number, number>) =>
    props.notes
        .filter((note) => (map[note] ?? 0) > 0)
        .map((note) => `${mmk(note)} × ${map[note]}`)
        .join(', ');
function authHeaders(): Record<string, string> {
    const token = readStoredToken();

    return token ? { Authorization: `Bearer ${token}` } : {};
}

function onScreenshotChange(event: Event) {
    const input = event.target as HTMLInputElement;
    screenshot.value = input.files?.[0] ?? null;
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
        '/transactions/cash-in',
        {
            account_id: accountId.value,
            amount: amount.value,
            customer_name: customerName.value.trim(),
            customer_phone: customerPhone.value.trim(),
            amount_received: amountReceived.value,
            fee_payment_method: feePaymentMethod.value,
            fee_account_id:
                feePaymentMethod.value === 'account'
                    ? feeAccountId.value
                    : null,
            screenshot: screenshot.value,
            received_denominations: receivedDenoms.value,
            handoff_denominations:
                props.role === 'teller' ? handoffDenoms.value : {},
            change_denominations: changeDue.value > 0 ? changeDenoms.value : {},
        },
        {
            headers: authHeaders(),
            forceFormData: true,
            onError: (errorBag) => {
                errors.value = Object.fromEntries(
                    Object.entries(errorBag).map(([key, value]) => [
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
            {{ t('transaction.cashIn') }}
        </h1>

        <template v-if="view === 'history'">
            <section
                class="mt-5 rounded-2xl border border-line bg-card p-4"
                aria-label="Cash In history filters"
            >
                <div
                    class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(16rem,2fr)_minmax(10rem,1fr)_minmax(9rem,1fr)_minmax(9rem,1fr)_auto]"
                >
                    <label class="group relative min-w-0">
                        <input
                            v-model="historySearch"
                            type="search"
                            class="bank-input h-14 pt-5 transition focus:border-brand focus:ring-2 focus:ring-brand/20"
                            placeholder="Reference, customer, phone, account"
                        />
                        <span
                            class="pointer-events-none absolute top-0 left-3 -translate-y-1/2 bg-card px-2 text-xs font-black text-slate transition-colors group-focus-within:text-brand"
                            >Search</span
                        >
                    </label>
                    <label class="group relative">
                        <select
                            v-model="historyStatus"
                            class="bank-input h-14 pt-5 transition focus:border-brand focus:ring-2 focus:ring-brand/20"
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
                        <span
                            class="pointer-events-none absolute top-0 left-3 -translate-y-1/2 bg-card px-2 text-xs font-black text-slate transition-colors group-focus-within:text-brand"
                            >Status</span
                        >
                    </label>
                    <label class="group relative">
                        <input
                            v-model="historyDateFrom"
                            type="date"
                            class="bank-input h-14 pt-5 transition focus:border-brand focus:ring-2 focus:ring-brand/20"
                        />
                        <span
                            class="pointer-events-none absolute top-0 left-3 -translate-y-1/2 bg-card px-2 text-xs font-black text-slate transition-colors group-focus-within:text-brand"
                            >From</span
                        >
                    </label>
                    <label class="group relative">
                        <input
                            v-model="historyDateTo"
                            type="date"
                            class="bank-input h-14 pt-5 transition focus:border-brand focus:ring-2 focus:ring-brand/20"
                        />
                        <span
                            class="pointer-events-none absolute top-0 left-3 -translate-y-1/2 bg-card px-2 text-xs font-black text-slate transition-colors group-focus-within:text-brand"
                            >To</span
                        >
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
                :title="`${t('transaction.cashIn')} ${t('common.history', 'History')}`"
                empty-text="No Cash In transactions match these filters."
            />
        </template>

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
                    {{ t('transaction.cashInSubmitted') }}
                </h2>
                <p class="mt-1 text-sm font-semibold text-slate">
                    {{ t('transaction.awaitingCashier') }}
                </p>
                <p class="money mt-1 text-sm text-slate">
                    Ref #{{ String(completed.id).padStart(6, '0') }} ·
                    {{ completed.created_at }}
                </p>
            </div>
            <dl class="mt-6 divide-y divide-line border-t border-line">
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.accountDeducted') }}
                    </dt>
                    <dd class="font-bold">{{ completed.from_label }}</dd>
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
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">Status</dt>
                    <dd class="font-bold">{{ completed.status }}</dd>
                </div>
            </dl>
            <div class="mt-6 flex gap-2">
                <Link
                    href="/transactions/cash-in"
                    :headers="authHeaders()"
                    class="bank-button bank-button-primary flex-1 rounded-pill"
                >
                    {{ t('transaction.newCashIn') }}
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
                {{ t('transaction.enterDetails') }}
            </h2>

            <div class="mt-4 space-y-4">
                <div
                    class="grid items-start gap-3 md:grid-cols-2 xl:grid-cols-3"
                >
                    <section
                        class="space-y-2 md:col-span-2 xl:col-span-3"
                        aria-labelledby="cash-in-provider-title"
                    >
                        <h3
                            id="cash-in-provider-title"
                            class="text-xs font-black text-slate"
                        >
                            {{ t('transaction.company', 'Company') }}
                        </h3>

                        <div
                            class="flex gap-2 overflow-x-auto pb-1.5"
                            role="radiogroup"
                            aria-label="Company"
                        >
                            <button
                                v-for="company in companies"
                                :key="company.id ?? company.name"
                                type="button"
                                role="radio"
                                :aria-checked="selectedCompany === company.name"
                                :aria-label="company.name"
                                :title="company.name"
                                class="group flex min-h-12 shrink-0 items-center gap-2 rounded-field border px-2.5 py-1.5 text-left transition"
                                :class="[
                                    selectedCompany === company.name
                                        ? 'border-brand bg-brand-soft text-brand shadow-sm ring-2 ring-brand/15'
                                        : 'border-line bg-mist/40 text-ink hover:border-brand/40 hover:bg-brand-soft/40',
                                    hasCompanyLogo(company)
                                        ? 'min-w-16 justify-center'
                                        : 'min-w-36',
                                ]"
                                @click="selectedCompany = company.name"
                            >
                                <span
                                    v-if="hasCompanyLogo(company)"
                                    class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-lg border border-line bg-card text-xs font-black"
                                    :class="
                                        selectedCompany === company.name
                                            ? 'border-brand/25 shadow-sm'
                                            : 'shadow-sm'
                                    "
                                >
                                    <img
                                        :src="company.logoUrl"
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

                    </section>

                    <AccountTile
                        v-model="accountId"
                        :accounts="visibleAccounts"
                        :label="t('transaction.accountDebit')"
                        :must-cover="accountBalanceRequired"
                        compact
                    />

                    <BigAmountInput
                        v-model="amount"
                        :label="t('transaction.cashInAmount')"
                        required
                        compact
                    />

                    <div>
                        <p class="bank-label">{{ t('transaction.fee') }}</p>
                        <div
                            class="flex min-h-12 items-center justify-between gap-3 rounded-field border border-line bg-mist px-3 py-2"
                        >
                            <p class="text-sm font-bold text-ink">
                                {{ t('transaction.commissionTier') }}
                            </p>
                            <p class="money text-base font-bold text-ink">
                                {{ mmk(feeNum) }}
                                <span class="text-[10px] text-slate">MMK</span>
                            </p>
                        </div>
                    </div>

                    <FeePaymentSelector
                        class="md:col-span-2 xl:col-span-3"
                        v-model="feePaymentMethod"
                        v-model:fee-account-id="feeAccountId"
                        :fee="feeNum"
                        :fee-accounts="feeAccounts"
                        compact
                    />
                </div>

                <div class="min-w-0">
                    <div
                        class="grid items-start gap-3 md:grid-cols-2 xl:grid-cols-3"
                    >
                        <div class="grid gap-3 sm:grid-cols-2 xl:col-span-2">
                            <div>
                                <label
                                    class="bank-label bank-required"
                                    for="cash-in-customer-name"
                                >
                                    {{ t('transaction.customerName') }}
                                </label>
                                <input
                                    id="cash-in-customer-name"
                                    v-model="customerName"
                                    type="text"
                                    autocomplete="name"
                                    placeholder=" "
                                    :aria-invalid="
                                        Boolean(errors.customer_name)
                                    "
                                    class="bank-input min-h-12 border border-line bg-mist px-3 py-2 transition focus:border-brand focus:ring-2 focus:ring-brand/20"
                                    :class="
                                        errors.customer_name
                                            ? 'border-brand text-brand focus:border-brand focus:ring-brand/20'
                                            : ''
                                    "
                                />
                            </div>
                            <div>
                                <label
                                    class="bank-label bank-required"
                                    for="cash-in-customer-phone"
                                >
                                    {{ t('transaction.customerPhone') }}
                                </label>
                                <input
                                    id="cash-in-customer-phone"
                                    v-model="customerPhone"
                                    type="tel"
                                    autocomplete="tel"
                                    placeholder=" "
                                    :aria-invalid="
                                        Boolean(errors.customer_phone)
                                    "
                                    class="bank-input min-h-12 border border-line bg-mist px-3 py-2 transition focus:border-brand focus:ring-2 focus:ring-brand/20"
                                    :class="
                                        errors.customer_phone
                                            ? 'border-brand text-brand focus:border-brand focus:ring-brand/20'
                                            : ''
                                    "
                                />
                            </div>
                        </div>

                        <div>
                            <p class="bank-label">
                                {{ t('transaction.screenshot') }}
                            </p>
                            <label
                                for="cash-in-screenshot"
                                class="flex min-h-12 cursor-pointer items-center justify-between gap-3 rounded-field border border-dashed border-line bg-mist px-3 py-2 text-sm transition focus-within:border-brand focus-within:ring-2 focus-within:ring-brand/20 hover:border-brand/50 hover:bg-brand-soft/30"
                            >
                                <span class="min-w-0">
                                    <span class="block font-bold text-ink">{{
                                        screenshot?.name ??
                                        t('transaction.attachScreenshot')
                                    }}</span>
                                </span>
                                <span
                                    class="shrink-0 rounded-pill bg-mist px-3 py-1 text-xs font-bold text-slate"
                                    >{{ t('common.choose') }}</span
                                >
                            </label>
                            <input
                                id="cash-in-screenshot"
                                type="file"
                                accept="image/png,image/jpeg,image/jpg,image/bmp,image/gif"
                                class="sr-only"
                                @change="onScreenshotChange"
                            />
                        </div>
                    </div>
                </div>

                <div v-if="cashInNeedsDenoms && canCountCash" class="min-w-0">
                    <div class="grid items-start gap-3">
                        <section
                            class="overflow-hidden rounded-field border border-brand/20 bg-card"
                            aria-labelledby="cash-in-customer-cash-title"
                        >
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-3 border-b border-line bg-brand-soft/55 px-3 py-2.5 text-left transition hover:bg-brand-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/35 sm:px-4"
                                :aria-expanded="showReceivedDenoms"
                                aria-controls="cash-in-customer-denominations"
                                @click="
                                    showReceivedDenoms = !showReceivedDenoms
                                "
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <span
                                        class="grid size-6 shrink-0 place-items-center rounded-lg bg-brand text-[10px] font-black text-white"
                                        >01</span
                                    >
                                    <div class="min-w-0">
                                        <h3
                                            id="cash-in-customer-cash-title"
                                            class="text-sm font-bold text-ink"
                                        >
                                            {{
                                                t(
                                                    'transaction.cashReceivedCustomer',
                                                )
                                            }}
                                        </h3>
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p
                                        class="text-[10px] font-bold tracking-wide text-slate uppercase"
                                    >
                                        {{ t('component.counted') }}
                                    </p>
                                    <p
                                        class="money mt-0.5 text-lg font-black text-brand"
                                    >
                                        {{ mmk(amountReceived) }}
                                    </p>
                                    <p class="text-[10px] font-bold text-slate">
                                        MMK
                                    </p>
                                </div>
                                <span
                                    class="grid size-7 shrink-0 place-items-center rounded-full bg-card text-sm font-black text-slate shadow-sm"
                                    aria-hidden="true"
                                >
                                    {{ showReceivedDenoms ? '⌃' : '⌄' }}
                                </span>
                            </button>
                            <div
                                v-show="showReceivedDenoms"
                                id="cash-in-customer-denominations"
                                class="p-2.5 sm:p-3"
                            >
                                <DenomDrawer
                                    v-model="receivedDenoms"
                                    :notes="notes"
                                    :target="null"
                                    :label="t('component.notesCounted')"
                                    id-prefix="cash-in-customer-denomination"
                                    :show-title="false"
                                    :compact="true"
                                />
                            </div>
                            <footer
                                class="grid gap-2 border-t border-line bg-mist/45 px-3 py-2 text-xs sm:px-4"
                            >
                                <div class="flex items-center justify-between">
                                    <span class="font-semibold text-slate">{{
                                        t('transaction.cashReceived')
                                    }}</span>
                                    <span class="money font-black text-ink"
                                        >{{ mmk(receivedTotal) }} MMK</span
                                    >
                                </div>
                                <div
                                    v-if="receivedTotal > 0"
                                    class="grid gap-1 rounded-lg bg-card/70 px-2.5 py-2 sm:grid-cols-3"
                                >
                                    <div class="flex justify-between gap-3">
                                        <span
                                            class="font-semibold text-slate"
                                            >{{
                                                t('transaction.cashDue', 'Due')
                                            }}</span
                                        >
                                        <span
                                            class="money font-black text-ink"
                                            >{{
                                                mmk(cashSettlementAmount)
                                            }}</span
                                        >
                                    </div>
                                    <div class="flex justify-between gap-3">
                                        <span
                                            class="font-semibold text-slate"
                                            >{{
                                                t(
                                                    'transaction.cashReceived',
                                                    'Received',
                                                )
                                            }}</span
                                        >
                                        <span
                                            class="money font-black text-ink"
                                            >{{ mmk(receivedTotal) }}</span
                                        >
                                    </div>
                                    <div
                                        class="flex justify-between gap-3"
                                        :class="
                                            cashShortfall > 0
                                                ? 'text-brand'
                                                : changeDue > 0
                                                  ? 'text-held'
                                                  : 'text-balance'
                                        "
                                    >
                                        <span class="font-black">{{
                                            cashShortfall > 0
                                                ? t(
                                                      'transaction.cashShortfall',
                                                      'Short',
                                                  )
                                                : changeDue > 0
                                                  ? t(
                                                        'transaction.changeDue',
                                                        'Change due',
                                                    )
                                                  : t(
                                                        'common.balanced',
                                                        'Balanced',
                                                    )
                                        }}</span>
                                        <span class="money font-black">{{
                                            mmk(cashShortfall || changeDue)
                                        }}</span>
                                    </div>
                                </div>
                            </footer>
                        </section>

                        <p
                            v-if="cashShortfall > 0"
                            class="rounded-field border border-brand/25 bg-brand-soft px-3 py-2 text-xs font-bold text-brand"
                        >
                            {{ t('transaction.cashShort') }}
                            <span class="money block"
                                >{{ mmk(cashShortfall) }} MMK</span
                            >
                        </p>

                        <section
                            v-if="role === 'teller' && changeDue > 0"
                            class="overflow-hidden rounded-field border border-held/20 bg-card"
                            aria-labelledby="cash-in-change-title"
                        >
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-3 border-b border-line bg-held/5 px-3 py-2.5 text-left transition hover:bg-held/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-held/35 sm:px-4"
                                :aria-expanded="showChangeDenoms"
                                aria-controls="cash-in-change-denominations"
                                @click="showChangeDenoms = !showChangeDenoms"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <span
                                        class="grid size-6 shrink-0 place-items-center rounded-lg bg-held text-[10px] font-black text-white"
                                        >02</span
                                    >
                                    <div class="min-w-0">
                                        <h3
                                            id="cash-in-change-title"
                                            class="text-sm font-bold text-ink"
                                        >
                                            {{ t('transaction.changeMyVault') }}
                                        </h3>
                                        <p
                                            class="mt-0.5 text-[11px] font-semibold text-slate"
                                        >
                                            {{ t('transaction.changeNotice') }}
                                        </p>
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p
                                        class="text-[10px] font-bold tracking-wide text-slate uppercase"
                                    >
                                        {{ t('component.required') }}
                                    </p>
                                    <p
                                        class="money mt-0.5 text-lg font-black text-held"
                                    >
                                        {{ mmk(changeDue) }}
                                    </p>
                                    <p class="text-[10px] font-bold text-slate">
                                        MMK
                                    </p>
                                </div>
                                <span
                                    class="grid size-7 shrink-0 place-items-center rounded-full bg-card text-sm font-black text-slate shadow-sm"
                                    aria-hidden="true"
                                >
                                    {{ showChangeDenoms ? '⌃' : '⌄' }}
                                </span>
                            </button>
                            <div
                                v-show="showChangeDenoms"
                                id="cash-in-change-denominations"
                                class="p-2.5 sm:p-3"
                            >
                                <DenomDrawer
                                    v-model="changeDenoms"
                                    :notes="notes"
                                    :target="changeDue"
                                    :stock="floatStock"
                                    :label="t('component.notesCounted')"
                                    id-prefix="cash-in-change-denomination"
                                    :show-title="false"
                                    :compact="true"
                                />
                            </div>
                            <footer
                                class="flex items-center justify-between border-t px-3 py-2 text-xs sm:px-4"
                                :class="
                                    changeBalanced
                                        ? 'border-balance/25 bg-balance/5'
                                        : 'border-held/20 bg-held/5'
                                "
                            >
                                <span
                                    class="font-semibold"
                                    :class="
                                        changeBalanced
                                            ? 'text-balance'
                                            : 'text-held'
                                    "
                                    >{{
                                        t('transaction.floatAfterChange')
                                    }}</span
                                >
                                <span class="money font-black text-ink"
                                    >{{ mmk(changeTotal) }} /
                                    {{ mmk(changeDue) }} MMK</span
                                >
                            </footer>
                        </section>
                    </div>
                </div>

                <div
                    v-else-if="cashInNeedsDenoms"
                    class="min-w-0 xl:col-span-2"
                >
                    <div
                        class="flex items-start gap-3 rounded-2xl border border-line bg-mist/60 px-4 py-4 sm:px-5"
                    >
                        <span
                            class="grid size-8 shrink-0 place-items-center rounded-full bg-ink text-xs font-black text-white"
                            >01</span
                        >
                        <div>
                            <p class="text-sm font-bold text-ink">
                                {{ t('transaction.cashInCountPrerequisite') }}
                            </p>
                            <p class="mt-1 text-xs leading-5 text-slate">
                                {{ t('transaction.cashInDenominationHint') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

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
                            ready
                                ? t(
                                      'transaction.readyForReview',
                                      'Ready for review.',
                                  )
                                : readyIssue
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

        <section
            v-else-if="view === 'entry'"
            class="bank-form-shell mt-5 max-w-xl"
        >
            <h2 class="text-base font-bold">
                {{ t('transaction.reviewCashIn') }}
            </h2>
            <p class="mt-1 text-[13px] text-slate">
                {{ t('transaction.awaitingCashier') }}
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

            <dl class="mt-5 divide-y divide-line border-y border-line">
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.accountDebit') }}
                    </dt>
                    <dd class="text-right font-bold">
                        {{ account?.name }}
                        <span
                            class="block text-[11px] font-medium text-slate"
                            >{{ account?.company }}</span
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
                <div
                    v-if="cashInNeedsDenoms"
                    class="flex justify-between py-3 text-sm"
                >
                    <dt class="text-slate">
                        {{ t('transaction.cashReceived') }}
                    </dt>
                    <dd class="money font-bold">
                        {{ mmk(amountReceived) }} MMK
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="font-bold">
                        {{ t('transaction.cashHandedCashier') }}
                    </dt>
                    <dd class="money font-bold text-balance">
                        +{{ mmk(cashSettlementAmount) }} MMK
                    </dd>
                </div>
                <div class="flex justify-between gap-6 py-3 text-sm">
                    <dt class="shrink-0 text-slate">
                        {{ t('transaction.customerName') }}
                    </dt>
                    <dd class="text-right font-bold">{{ customerName }}</dd>
                </div>
                <div class="flex justify-between gap-6 py-3 text-sm">
                    <dt class="shrink-0 text-slate">
                        {{ t('transaction.customerPhone') }}
                    </dt>
                    <dd class="text-right font-bold">{{ customerPhone }}</dd>
                </div>
                <div
                    v-if="role === 'teller'"
                    class="flex justify-between py-3 text-sm"
                >
                    <dt class="font-bold">
                        {{ t('transaction.tellerVaultNetChange') }}
                    </dt>
                    <dd class="money font-bold text-balance">0 MMK</dd>
                </div>
                <div
                    v-if="role === 'teller' && tellerDenominationDelta.length"
                    class="flex justify-between gap-6 py-3 text-sm"
                >
                    <dt class="font-bold">
                        {{ t('transaction.tellerDenominationChange') }}
                    </dt>
                    <dd class="text-right">
                        <span
                            v-for="line in tellerDenominationDelta"
                            :key="line.note"
                            class="money block font-bold"
                            :class="
                                line.quantity > 0
                                    ? 'text-balance'
                                    : 'text-brand'
                            "
                        >
                            {{ line.quantity > 0 ? '+' : '−'
                            }}{{ mmk(line.note) }} ×
                            {{ Math.abs(line.quantity) }}
                        </span>
                    </dd>
                </div>
                <div
                    v-if="role === 'teller' && changeDue > 0"
                    class="flex justify-between py-3 text-sm"
                >
                    <dt class="font-bold">
                        {{ t('transaction.changeMyVault') }}
                    </dt>
                    <dd class="text-right font-bold text-brand">
                        <span class="money block"
                            >−{{ mmk(changeDue) }} MMK</span
                        >
                        <span class="money block text-[11px] font-medium">{{
                            denominationSummary(changeDenoms)
                        }}</span>
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
                        <span
                            v-if="feePaymentMethod === 'account'"
                            class="block text-[11px] font-medium text-slate"
                            >{{ feeAccount?.name }}</span
                        >
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="font-bold">
                        {{ t('transaction.kpayBalanceDecreased') }}
                    </dt>
                    <dd class="money font-bold text-brand">
                        −{{ mmk(accountBalanceRequired) }} MMK
                    </dd>
                </div>
                <div
                    v-if="screenshot"
                    class="flex justify-between gap-6 py-3 text-sm"
                >
                    <dt class="shrink-0 text-slate">
                        {{ t('transaction.screenshot') }}
                    </dt>
                    <dd class="truncate text-right font-bold">
                        {{ screenshot.name }}
                    </dd>
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
                            : t('transaction.confirmCashIn')
                    }}
                </button>
            </div>
        </section>
    </BankLayout>
</template>
