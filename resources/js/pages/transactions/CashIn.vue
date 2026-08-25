<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AccountTile from '@/components/bank/AccountTile.vue';
import BigAmountInput from '@/components/bank/BigAmountInput.vue';
import FeePaymentSelector from '@/components/bank/FeePaymentSelector.vue';
import type { FeePaymentMethod } from '@/components/bank/FeePaymentSelector.vue';
import TransactionHistoryTable from '@/components/teller/TransactionHistoryTable.vue';
import BankLayout from '@/layouts/BankLayout.vue';
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
const accountBalanceRequired = computed(() => amount.value);
const cashSettlementAmount = computed(
    () => amount.value + (feePaymentMethod.value === 'cash' ? feeNum.value : 0),
);
const cashierLocked = computed(() => props.role === 'cashier');
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
        !cashierLocked.value,
);
const readyIssue = computed(() => {
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
function authHeaders(): Record<string, string> {
    return {};
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
            fee_payment_method: feePaymentMethod.value,
            fee_account_id:
                feePaymentMethod.value === 'account'
                    ? feeAccountId.value
                    : null,
            screenshot: screenshot.value,
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
        <h1 class="text-2xl font-bold tracking-tight text-credit">
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
            :class="cashierLocked ? 'pointer-events-none opacity-50' : ''"
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
                                        :src="company.logoUrl ?? undefined"
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

                <div class="min-w-0 xl:col-span-2">
                    <div
                        class="flex items-start gap-3 rounded-2xl border border-credit/25 bg-credit/5 px-4 py-4 sm:px-5"
                    >
                        <span
                            class="grid size-8 shrink-0 place-items-center rounded-full bg-credit text-xs font-black text-white"
                            >✓</span
                        >
                        <div>
                            <p class="text-sm font-black text-credit">
                                {{
                                    t(
                                        'transaction.cashierCountsCash',
                                        'Cashier counts the physical cash',
                                    )
                                }}
                            </p>
                            <p
                                class="mt-1 text-xs leading-5 font-semibold text-slate"
                            >
                                {{
                                    t(
                                        'transaction.cashierCountsCashHint',
                                        'Enter the Cash In details only. The Cashier will count received notes and return any change before confirming the transaction.',
                                    )
                                }}
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
                            {{ t('transaction.amount') }}
                            <template v-if="feePaymentMethod === 'cash'">
                                + {{ t('transaction.fee') }}
                            </template>
                        </p>
                    </div>
                    <p
                        class="money text-right text-2xl font-black text-balance"
                    >
                        {{ mmk(cashSettlementAmount) }}
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
                <div class="flex justify-between gap-6 py-3 text-sm">
                    <dt class="font-bold">
                        {{
                            t(
                                'transaction.physicalCashCount',
                                'Physical cash count',
                            )
                        }}
                    </dt>
                    <dd class="text-right font-bold text-credit">
                        {{
                            t(
                                'transaction.pendingCashierCount',
                                'Pending Cashier count',
                            )
                        }}
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
