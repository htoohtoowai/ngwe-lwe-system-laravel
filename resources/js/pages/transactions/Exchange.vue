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
        accounts: {
            id: number;
            company: string;
            company_id?: number | null;
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
        fee: string;
        commission: string;
        rate: { buy_rate: string; sell_rate: string };
        requiresDenominations: boolean;
        completed?: {
            id: number;
            amount: string;
            currency: 'MMK' | 'THB';
            fee_amount: string;
            status: string;
            created_at: string;
            from_label: string;
            to_label: string;
            commission_amount?: string;
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
const amount = ref(0);
const currency = ref<'MMK' | 'THB'>('MMK');
const exchangePaymentMethod = ref<'cash' | 'account'>('account');
const exchangePaymentMethods = ['account', 'cash'] as const;
const customerName = ref('');
const customerPhone = ref('');
const description = ref('');
const denoms = ref<Record<number, number>>({});
const receivedDenoms = ref<Record<number, number>>({});
const showPayoutDenoms = ref(true);
const showReceivedDenoms = ref(true);
const submitting = ref(false);
const errors = ref<Record<string, string>>({});
const feePaymentMethod = ref<FeePaymentMethod>('cash');
const feeAccountId = ref<number | null>(null);
const historySearch = ref('');
const historyDirection = ref<'all' | 'MMK' | 'THB'>('all');
const historyStatus = ref('all');
const historyDateFrom = ref('');
const historyDateTo = ref('');
const failedCompanyLogos = ref<Set<string>>(new Set());
const { t } = useLocale();

const feeNum = computed(() => Number(props.fee ?? 0));
const commissionNum = computed(() => Number(props.commission ?? 0));
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
                row.currency,
                row.exchange_rate,
                row.status,
            ].some((value) =>
                String(value ?? '')
                    .toLowerCase()
                    .includes(query),
            );
        const matchesDirection =
            historyDirection.value === 'all' ||
            row.currency === historyDirection.value;
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
            matchesSearch &&
            matchesDirection &&
            matchesStatus &&
            matchesDateFrom &&
            matchesDateTo
        );
    });
});
const account = computed(() =>
    props.accounts.find((a) => a.id === accountId.value),
);
const companies = computed(() => {
    const unique = new Map<
        string,
        { id: number | null; name: string; logoUrl: string | null }
    >();

    for (const candidate of props.accounts) {
        if (!candidate.company || unique.has(candidate.company)) {
            continue;
        }

        unique.set(candidate.company, {
            id: candidate.company_id ?? null,
            name: candidate.company,
            logoUrl: candidate.company_logo_url ?? null,
        });
    }

    return Array.from(unique.values());
});
const visibleAccounts = computed(() =>
    props.accounts.filter(
        (candidate) =>
            !selectedCompany.value ||
            candidate.company === selectedCompany.value,
    ),
);
const denomTotal = computed(() =>
    props.notes.reduce(
        (sum, note) => sum + note * (denoms.value[note] ?? 0),
        0,
    ),
);
const receivedDenomTotal = computed(() =>
    props.notes.reduce(
        (sum, note) => sum + note * (receivedDenoms.value[note] ?? 0),
        0,
    ),
);
const cashierLocked = computed(() => props.role === 'cashier');
const feePaymentValid = computed(
    () =>
        feeNum.value <= 0 ||
        feePaymentMethod.value === 'cash' ||
        feeAccountId.value !== null,
);
const activeRate = computed(() =>
    currency.value === 'MMK' ? props.rate.sell_rate : props.rate.buy_rate,
);
const mmkSettlementAmount = computed(() =>
    currency.value === 'THB'
        ? Math.round((amount.value || 0) * Number(activeRate.value))
        : amount.value || 0,
);
const exchangeBalanceChange = computed(
    () => mmkSettlementAmount.value + commissionNum.value,
);
const exchangeResultCurrency = computed<'MMK' | 'THB'>(() =>
    currency.value === 'MMK' ? 'THB' : 'MMK',
);
const exchangeResultAmount = computed(() => {
    if (currency.value === 'THB') {
        return mmkSettlementAmount.value;
    }

    const rate = Number(activeRate.value);

    return rate > 0 ? (amount.value || 0) / rate : 0;
});
const exchangeCustomerActionLabel = computed(() =>
    currency.value === 'THB'
        ? t('transaction.customerReceives')
        : t('transaction.customerSends'),
);
const exchangeCustomerActionHint = computed(() =>
    currency.value === 'THB'
        ? `${currency.value} -> MMK`
        : t('transaction.cashReceivedCustomer'),
);
const cashReceivedIsCash = computed(
    () => exchangePaymentMethod.value === 'cash',
);
const needsPayoutDenoms = computed(
    () =>
        props.role === 'teller' &&
        currency.value === 'THB' &&
        cashReceivedIsCash.value,
);
const needsReceivedDenoms = computed(
    () =>
        props.role === 'teller' &&
        cashReceivedIsCash.value &&
        currency.value === 'MMK',
);
const floatLocked = computed(
    () =>
        (needsPayoutDenoms.value || needsReceivedDenoms.value) &&
        props.float?.status !== 'ACTIVE',
);
const ready = computed(
    () =>
        accountId.value !== null &&
        amount.value > 0 &&
        customerName.value.trim().length > 0 &&
        customerPhone.value.trim().length > 0 &&
        (!needsPayoutDenoms.value ||
            denomTotal.value === mmkSettlementAmount.value) &&
        (!needsReceivedDenoms.value ||
            receivedDenomTotal.value === mmkSettlementAmount.value) &&
        feePaymentValid.value &&
        !floatLocked.value &&
        !cashierLocked.value,
);
const readyIssue = computed(() => {
    if (cashierLocked.value) {
        return t('transaction.cashierLocked');
    }

    if (floatLocked.value) {
        return t('transaction.floatLocked');
    }

    if (accountId.value === null) {
        return t(
            'transaction.chooseAccountFirst',
            'Choose an Exchange account.',
        );
    }

    if (amount.value <= 0) {
        return t('transaction.enterAmountBeforeContinue', 'Enter an amount.');
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

    if (
        needsPayoutDenoms.value &&
        denomTotal.value !== mmkSettlementAmount.value
    ) {
        return t(
            'transaction.cashOutDenominationHint',
            'Count the MMK paid from the teller vault.',
        );
    }

    if (
        needsReceivedDenoms.value &&
        receivedDenomTotal.value !== mmkSettlementAmount.value
    ) {
        return t('transaction.countCustomerCash', 'Count the customer cash.');
    }

    return '';
});

const money = (value: string | number) =>
    Number(value).toLocaleString(undefined, {
        maximumFractionDigits: currency.value === 'THB' ? 2 : 0,
    });
const resultMoney = (value: string | number) =>
    Number(value).toLocaleString(undefined, {
        maximumFractionDigits: exchangeResultCurrency.value === 'THB' ? 2 : 0,
    });
const mmk = (value: string | number) => Number(value).toLocaleString();
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
function clearHistoryFilters(): void {
    historySearch.value = '';
    historyDirection.value = 'all';
    historyStatus.value = 'all';
    historyDateFrom.value = '';
    historyDateTo.value = '';
}
function authHeaders(): Record<string, string> {
    const token = readStoredToken();

    return token ? { Authorization: `Bearer ${token}` } : {};
}

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
        !visibleAccounts.value.some(
            (candidate) => candidate.id === accountId.value,
        )
    ) {
        accountId.value = null;
    }
});

let commissionTimer: ReturnType<typeof setTimeout>;
watch([amount, accountId, currency], ([value, selectedAccountId]) => {
    clearTimeout(commissionTimer);

    if (value <= 0 || selectedAccountId === null) {
        return;
    }

    commissionTimer = setTimeout(
        () =>
            router.reload({
                only: ['commission'],
                data: {
                    amount: value,
                    account_id: selectedAccountId,
                    currency: currency.value,
                },
                headers: authHeaders(),
            }),
        350,
    );
});

function submit() {
    submitting.value = true;
    router.post(
        '/transactions/exchange',
        {
            account_id: accountId.value,
            amount: amount.value,
            currency: currency.value,
            customer_name: customerName.value.trim(),
            customer_phone: customerPhone.value.trim(),
            exchange_payment_method: exchangePaymentMethod.value,
            note: description.value,
            fee_payment_method: feePaymentMethod.value,
            fee_account_id:
                feePaymentMethod.value === 'account'
                    ? feeAccountId.value
                    : null,
            ...(needsPayoutDenoms.value ? { denominations: denoms.value } : {}),
            ...(needsReceivedDenoms.value
                ? { received_denominations: receivedDenoms.value }
                : {}),
        },
        {
            headers: authHeaders(),
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
            {{ t('transaction.exchange') }}
        </h1>

        <template v-if="view === 'history'">
            <section
                class="mt-5 rounded-2xl border border-line bg-card p-4"
                aria-label="Exchange history filters"
            >
                <div
                    class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(14rem,2fr)_minmax(10rem,1fr)_minmax(10rem,1fr)_minmax(9rem,1fr)_minmax(9rem,1fr)_auto]"
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
                        <span class="bank-label">Direction</span>
                        <select
                            v-model="historyDirection"
                            class="bank-input mt-1.5"
                        >
                            <option value="all">All directions</option>
                            <option value="MMK">MMK → THB</option>
                            <option value="THB">THB → MMK</option>
                        </select>
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
                :title="`${t('transaction.exchange')} ${t('common.history', 'History')}`"
                empty-text="No exchange transactions match these filters."
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
                    {{ t('transaction.exchange') }} {{ t('status.completed') }}
                </h2>
                <p class="money mt-1 text-sm text-slate">
                    Ref #{{ String(completed.id).padStart(6, '0') }} ·
                    {{ completed.created_at }}
                </p>
            </div>
            <dl class="mt-6 divide-y divide-line border-t border-line">
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">Account</dt>
                    <dd class="font-bold">{{ completed.to_label }}</dd>
                </div>
                <div class="flex justify-between py-3">
                    <dt class="text-sm text-slate">
                        {{ t('transaction.amount') }}
                    </dt>
                    <dd class="money text-lg font-bold">
                        {{ mmk(completed.amount) }}
                        <span class="text-[11px] text-slate">{{
                            completed.currency
                        }}</span>
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.agentCommission') }}
                    </dt>
                    <dd class="money font-bold text-balance">
                        +{{ mmk(completed.commission_amount ?? 0) }} MMK
                    </dd>
                </div>
            </dl>
            <div class="mt-6 flex gap-2">
                <Link
                    href="/transactions/exchange"
                    :headers="authHeaders()"
                    class="bank-button bank-button-primary flex-1 rounded-pill"
                >
                    {{ t('transaction.exchange') }} အသစ်
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
            <h2 class="text-base font-bold">Enter Details</h2>

            <div class="mt-4 space-y-4">
                <div>
                    <p class="bank-label">{{ t('transaction.direction') }}</p>
                    <div
                        class="grid min-h-12 grid-cols-2 rounded-field border border-line bg-mist p-1"
                    >
                        <button
                            type="button"
                            class="bank-choice rounded-lg px-3 py-2 text-sm font-bold transition"
                            :aria-pressed="currency === 'MMK'"
                            :class="
                                currency === 'MMK'
                                    ? 'bg-card text-ink shadow-sm'
                                    : 'text-slate'
                            "
                            @click="currency = 'MMK'"
                        >
                            {{ t('transaction.mmkToThb') }}
                        </button>
                        <button
                            type="button"
                            class="bank-choice rounded-lg px-3 py-2 text-sm font-bold transition"
                            :aria-pressed="currency === 'THB'"
                            :class="
                                currency === 'THB'
                                    ? 'bg-card text-ink shadow-sm'
                                    : 'text-slate'
                            "
                            @click="currency = 'THB'"
                        >
                            {{ t('transaction.thbToMmk') }}
                        </button>
                    </div>
                </div>

                <section
                    class="space-y-2"
                    aria-labelledby="exchange-company-title"
                >
                    <h3
                        id="exchange-company-title"
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
                                class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-lg border border-line bg-card text-xs font-black shadow-sm"
                                :class="
                                    selectedCompany === company.name
                                        ? 'border-brand/25'
                                        : ''
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
                </section>

                <div
                    class="grid items-start gap-3 md:grid-cols-2 xl:grid-cols-4"
                >
                    <AccountTile
                        v-model="accountId"
                        :accounts="visibleAccounts"
                        :label="t('transaction.exchangeAccount')"
                        compact
                    />

                    <BigAmountInput
                        v-model="amount"
                        :currency="currency"
                        currency-class="font-medium text-slate"
                        :reading-currency-label="
                            currency === 'THB' ? 'ဘတ်' : 'ကျပ်'
                        "
                        :label="t('transaction.cashToExchange')"
                        :chips="
                            currency === 'THB'
                                ? [100, 500, 1000, 5000]
                                : undefined
                        "
                        required
                        compact
                    />

                    <div>
                        <p class="bank-label">
                            {{ t('transaction.sellRate') }} /
                            {{ t('transaction.buyRate') }}
                        </p>
                        <div
                            class="flex min-h-12 items-center justify-between gap-3 rounded-field border border-line bg-mist px-3 py-2"
                        >
                            <span class="text-sm font-bold text-ink">{{
                                t('transaction.rate')
                            }}</span>
                            <span class="money text-base font-black text-ink">{{
                                mmk(activeRate)
                            }}</span>
                        </div>
                    </div>

                    <div>
                        <p class="bank-label">{{ exchangeResultCurrency }}</p>
                        <div
                            class="flex min-h-12 items-center justify-between gap-3 rounded-field border border-line bg-mist px-3 py-2"
                        >
                            <span class="text-sm font-bold text-slate">{{
                                t('transaction.exchange')
                            }}</span>
                            <span
                                class="money text-base font-black text-balance"
                            >
                                {{ resultMoney(exchangeResultAmount) }}
                                <span class="text-[10px] text-slate">{{
                                    exchangeResultCurrency
                                }}</span>
                            </span>
                        </div>
                    </div>

                    <div>
                        <p class="bank-label">{{ t('transaction.fee') }}</p>
                        <div
                            class="flex min-h-12 items-center justify-between gap-3 rounded-field border border-line bg-mist px-3 py-2"
                        >
                            <span class="text-sm font-bold text-ink"
                                >Exchange</span
                            >
                            <span class="money text-base font-black text-ink">
                                {{ mmk(feeNum) }}
                                <span class="text-[10px] text-slate">MMK</span>
                            </span>
                        </div>
                    </div>

                    <div>
                        <p class="bank-label">
                            {{ t('transaction.agentCommission') }}
                        </p>
                        <div
                            class="flex min-h-12 items-center justify-between gap-3 rounded-field border border-line bg-mist px-3 py-2"
                        >
                            <span class="text-sm font-bold text-ink"
                                >Agent</span
                            >
                            <span
                                class="money text-base font-black text-balance"
                            >
                                +{{ mmk(commissionNum) }}
                                <span class="text-[10px] text-slate">MMK</span>
                            </span>
                        </div>
                    </div>

                    <FeePaymentSelector
                        class="md:col-span-2 xl:col-span-4"
                        v-model="feePaymentMethod"
                        v-model:fee-account-id="feeAccountId"
                        :fee="feeNum"
                        :fee-accounts="feeAccounts"
                        compact
                    />
                </div>

                <fieldset>
                    <legend class="bank-label mb-2">
                        {{
                            t(
                                'transaction.exchangePaymentMethod',
                                'Exchange payment method',
                            )
                        }}
                    </legend>
                    <div class="flex gap-5">
                        <label
                            v-for="method in exchangePaymentMethods"
                            :key="method"
                            class="bank-choice flex min-h-7 cursor-pointer items-center gap-2 text-sm font-bold transition"
                            :class="
                                exchangePaymentMethod === method
                                    ? 'text-brand'
                                    : 'text-slate'
                            "
                        >
                            <input
                                v-model="exchangePaymentMethod"
                                type="radio"
                                name="exchange_payment_method"
                                :value="method"
                                class="sr-only"
                            />
                            <span
                                class="grid size-4 shrink-0 place-items-center rounded-full border transition"
                                :class="
                                    exchangePaymentMethod === method
                                        ? 'border-brand'
                                        : 'border-slate/50'
                                "
                                aria-hidden="true"
                            >
                                <span
                                    class="size-2 rounded-full transition"
                                    :class="
                                        exchangePaymentMethod === method
                                            ? 'bg-brand'
                                            : 'bg-transparent'
                                    "
                                />
                            </span>
                            {{
                                method === 'account'
                                    ? t('transaction.feePaymentAccount')
                                    : t('transaction.feePaymentCash')
                            }}
                        </label>
                    </div>
                </fieldset>

                <div
                    class="grid items-start gap-3 md:grid-cols-2 xl:grid-cols-3"
                >
                    <div>
                        <label
                            class="bank-label bank-required"
                            for="exchange-customer-name"
                        >
                            {{ t('transaction.customerName') }}
                        </label>
                        <input
                            id="exchange-customer-name"
                            v-model="customerName"
                            type="text"
                            autocomplete="name"
                            placeholder=" "
                            class="bank-input min-h-12 border border-line bg-mist px-3 py-2 transition focus:border-brand focus:ring-2 focus:ring-brand/20"
                        />
                    </div>
                    <div>
                        <label
                            class="bank-label bank-required"
                            for="exchange-customer-phone"
                        >
                            {{ t('transaction.customerPhone') }}
                        </label>
                        <input
                            id="exchange-customer-phone"
                            v-model="customerPhone"
                            type="tel"
                            inputmode="tel"
                            autocomplete="tel"
                            placeholder=" "
                            class="bank-input min-h-12 border border-line bg-mist px-3 py-2 transition focus:border-brand focus:ring-2 focus:ring-brand/20"
                        />
                    </div>
                    <div>
                        <label class="bank-label" for="exchange-description">{{
                            t('transaction.description')
                        }}</label>
                        <div class="relative">
                            <textarea
                                id="exchange-description"
                                v-model="description"
                                maxlength="250"
                                rows="2"
                                autocomplete="off"
                                :placeholder="t('transaction.exchange')"
                                class="bank-input min-h-12 resize-none border border-line bg-mist px-3 py-2 pr-14"
                                aria-describedby="exchange-description-count"
                            />
                            <span
                                id="exchange-description-count"
                                class="money pointer-events-none absolute right-3 bottom-2 text-[10px] text-slate"
                            >
                                {{ description.length }}/250
                            </span>
                        </div>
                    </div>
                </div>

                <section
                    v-if="needsReceivedDenoms"
                    class="overflow-hidden rounded-field border border-brand/20 bg-card"
                    aria-labelledby="exchange-customer-cash-title"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 border-b border-line bg-brand-soft/55 px-3 py-2.5 text-left transition hover:bg-brand-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/35 sm:px-4"
                        :aria-expanded="showReceivedDenoms"
                        aria-controls="exchange-received-denominations"
                        @click="showReceivedDenoms = !showReceivedDenoms"
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <span
                                class="grid size-6 shrink-0 place-items-center rounded-lg bg-brand text-[10px] font-black text-white"
                                >01</span
                            >
                            <h3
                                id="exchange-customer-cash-title"
                                class="truncate text-sm font-bold text-ink"
                            >
                                {{ t('transaction.cashReceivedCustomer') }}
                            </h3>
                        </div>
                        <div class="ml-auto shrink-0 text-right">
                            <p
                                class="text-[10px] font-bold tracking-wide text-slate uppercase"
                            >
                                {{ t('component.counted') }}
                            </p>
                            <p
                                class="money mt-0.5 text-lg font-black text-brand"
                            >
                                {{ mmk(receivedDenomTotal) }}
                            </p>
                            <p class="text-[10px] font-bold text-slate">MMK</p>
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
                        id="exchange-received-denominations"
                        class="p-2.5 sm:p-3"
                    >
                        <DenomDrawer
                            v-model="receivedDenoms"
                            :notes="notes"
                            :target="mmkSettlementAmount"
                            :enforce-stock="false"
                            :label="t('component.notesCounted')"
                            id-prefix="exchange-received-denomination"
                            :show-title="false"
                            :compact="true"
                        />
                    </div>
                </section>

                <section
                    v-if="needsPayoutDenoms"
                    class="overflow-hidden rounded-field border border-held/20 bg-card"
                    aria-labelledby="exchange-payout-title"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 border-b border-line bg-held/5 px-3 py-2.5 text-left transition hover:bg-held/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-held/35 sm:px-4"
                        :aria-expanded="showPayoutDenoms"
                        aria-controls="exchange-payout-denominations"
                        @click="showPayoutDenoms = !showPayoutDenoms"
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <span
                                class="grid size-6 shrink-0 place-items-center rounded-lg bg-held text-[10px] font-black text-white"
                                >01</span
                            >
                            <h3
                                id="exchange-payout-title"
                                class="truncate text-sm font-bold text-ink"
                            >
                                {{ t('transaction.notesMyVault') }}
                            </h3>
                        </div>
                        <div class="ml-auto shrink-0 text-right">
                            <p
                                class="text-[10px] font-bold tracking-wide text-slate uppercase"
                            >
                                {{ t('component.counted') }}
                            </p>
                            <p class="money text-base font-black text-held">
                                {{ mmk(denomTotal) }}
                                <span class="text-[10px] text-slate">MMK</span>
                            </p>
                        </div>
                        <span
                            class="grid size-7 shrink-0 place-items-center rounded-full bg-card text-sm font-black text-slate shadow-sm"
                            aria-hidden="true"
                        >
                            {{ showPayoutDenoms ? '⌃' : '⌄' }}
                        </span>
                    </button>
                    <div
                        v-show="showPayoutDenoms"
                        id="exchange-payout-denominations"
                        class="p-2.5 sm:p-3"
                    >
                        <DenomDrawer
                            v-model="denoms"
                            :notes="notes"
                            :target="mmkSettlementAmount"
                            :stock="floatStock"
                            :label="t('transaction.notesMyVault')"
                            id-prefix="exchange-payout-denomination"
                            :show-title="false"
                            compact
                        />
                    </div>
                </section>
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
                {{ t('transaction.exchange') }} {{ t('common.review') }}
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
                            {{ exchangeCustomerActionLabel }}
                        </p>
                        <p class="mt-1 text-[13px] font-semibold text-slate">
                            {{ exchangeCustomerActionHint }}
                        </p>
                    </div>
                    <p
                        class="money text-right text-2xl font-black text-balance"
                    >
                        {{ resultMoney(exchangeResultAmount) }}
                        <span class="text-xs text-slate">{{
                            exchangeResultCurrency
                        }}</span>
                    </p>
                </div>
            </div>

            <dl class="mt-5 divide-y divide-line border-y border-line">
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">Account</dt>
                    <dd class="text-right font-bold">
                        {{ account?.name }}
                        <span
                            class="block text-[11px] font-medium text-slate"
                            >{{ account?.company }}</span
                        >
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{
                            t(
                                'transaction.exchangePaymentMethod',
                                'Exchange payment method',
                            )
                        }}
                    </dt>
                    <dd class="font-bold">
                        {{
                            exchangePaymentMethod === 'account'
                                ? t('transaction.feePaymentAccount')
                                : t('transaction.feePaymentCash')
                        }}
                    </dd>
                </div>
                <div class="flex justify-between py-3">
                    <dt class="text-sm text-slate">
                        {{ t('transaction.amount') }}
                    </dt>
                    <dd class="money text-lg font-bold">
                        {{ money(amount) }}
                        <span class="text-[11px] font-medium text-slate">{{
                            currency
                        }}</span>
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">{{ t('transaction.rate') }}</dt>
                    <dd class="money font-bold">{{ mmk(activeRate) }}</dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">MMK</dt>
                    <dd class="money font-bold">
                        {{ mmk(mmkSettlementAmount) }} MMK
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.customerName') }}
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
                        {{ t('transaction.agentCommission') }}
                    </dt>
                    <dd class="money font-bold text-balance">
                        +{{ mmk(commissionNum) }} MMK
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="font-bold">
                        {{ t('transaction.accountCredited') }}:
                        {{ account?.name }}
                    </dt>
                    <dd class="money font-bold text-balance">
                        +{{ mmk(exchangeBalanceChange) }} MMK
                    </dd>
                </div>
                <div
                    v-if="needsReceivedDenoms"
                    class="flex justify-between py-3 text-sm"
                >
                    <dt class="font-bold">
                        {{ t('transaction.cashReceivedCustomer') }}
                    </dt>
                    <dd class="money font-bold text-balance">
                        +{{ mmk(receivedDenomTotal) }} MMK
                    </dd>
                </div>
                <div
                    v-if="needsPayoutDenoms"
                    class="flex justify-between py-3 text-sm"
                >
                    <dt class="font-bold">Float cash paid</dt>
                    <dd class="money font-bold text-brand">
                        -{{ mmk(mmkSettlementAmount) }} MMK
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
                            : t('transaction.confirmExchange')
                    }}
                </button>
            </div>
        </section>
    </BankLayout>
</template>
