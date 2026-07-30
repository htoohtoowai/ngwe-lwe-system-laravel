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
        cashOutStock?: Record<number, number>;
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
        fee: string;
        commission: string;
        requiresDenominations: boolean;
        cashOutRequiresDenominations: boolean;
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
const creditAccountType = ref<'pay' | 'bank'>('pay');
const selectedCreditCompany = ref('');
const amount = ref(0);
const description = ref('');
const denoms = ref<Record<number, number>>({});
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
const commissionNum = computed(() => Number(props.commission ?? 0));
const account = computed(() =>
    props.accounts.find((a) => a.id === accountId.value),
);
const creditCompanies = computed(() => {
    const unique = new Map<
        string,
        { id: number | null; name: string; logoUrl: string | null }
    >();

    for (const candidate of props.accounts) {
        const category = (candidate.company_category ?? 'Both')
            .trim()
            .toLowerCase();

        if (
            !candidate.company ||
            unique.has(candidate.company) ||
            (category !== creditAccountType.value && category !== 'both')
        ) {
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
const creditAccounts = computed(() =>
    props.accounts.filter(
        (candidate) =>
            candidate.company === selectedCreditCompany.value &&
            ['both', creditAccountType.value].includes(
                (candidate.company_category ?? 'Both').trim().toLowerCase(),
            ),
    ),
);
const denomTotal = computed(() =>
    props.notes.reduce(
        (sum, note) => sum + note * (denoms.value[note] ?? 0),
        0,
    ),
);
const feeDenomTotal = computed(() =>
    props.notes.reduce(
        (sum, note) => sum + note * (feeDenoms.value[note] ?? 0),
        0,
    ),
);
const needsCashDenoms = computed(() => props.cashOutRequiresDenominations);
const needsCashFeeDenoms = computed(
    () =>
        props.role === 'teller' &&
        feePaymentMethod.value === 'cash' &&
        feeNum.value > 0,
);
const accountCreditAmount = computed(() =>
    feePaymentMethod.value === 'account'
        ? amount.value + feeNum.value + commissionNum.value
        : amount.value + commissionNum.value,
);
const customerCashPayout = computed(() => amount.value);
const customerFeeDue = computed(() => feeNum.value);
const cashStock = computed(() =>
    props.role === 'admin' ? (props.cashOutStock ?? {}) : props.floatStock,
);
const floatLocked = computed(
    () => props.role === 'teller' && props.float?.status !== 'ACTIVE',
);
const cashierLocked = computed(() => props.role === 'cashier');
const feePaymentValid = computed(
    () =>
        feeNum.value <= 0 ||
        ['cash', 'account'].includes(feePaymentMethod.value),
);
const ready = computed(
    () =>
        accountId.value !== null &&
        amount.value > 0 &&
        (!needsCashDenoms.value || denomTotal.value === amount.value) &&
        (!needsCashFeeDenoms.value || feeDenomTotal.value === feeNum.value) &&
        feePaymentValid.value &&
        !floatLocked.value &&
        !cashierLocked.value,
);

watch(creditCompanies, (nextCompanies) => {
    if (
        !nextCompanies.some(
            (company) => company.name === selectedCreditCompany.value,
        )
    ) {
        selectedCreditCompany.value = '';
    }
});

watch([creditAccountType, selectedCreditCompany], () => {
    if (
        !creditAccounts.value.some(
            (candidate) => candidate.id === accountId.value,
        )
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
                    only: ['fee', 'commission'],
                    data: { amount: nextAmount, account_id: nextAccount },
                    headers: authHeaders(),
                }),
            350,
        );
    }
});

const mmk = (value: string | number) => Number(value).toLocaleString();
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
        '/transactions/cash-out',
        {
            account_id: accountId.value,
            amount: amount.value,
            customer_name: 'Counter Customer',
            customer_phone: '-',
            note: description.value,
            fee_payment_method: feePaymentMethod.value,
            fee_account_id:
                feePaymentMethod.value === 'account'
                    ? feeAccountId.value
                    : null,
            ...(needsCashDenoms.value ? { denominations: denoms.value } : {}),
            ...(needsCashFeeDenoms.value
                ? { fee_denominations: feeDenoms.value }
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
            {{ t('transaction.cashOut') }}
        </h1>

        <template v-if="view === 'history'">
            <section
                class="mt-5 rounded-2xl border border-line bg-card p-4"
                aria-label="Cash Out history filters"
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
                :title="`${t('transaction.cashOut')} ${t('common.history', 'History')}`"
                empty-text="No Cash Out transactions match these filters."
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
                    {{ t('transaction.cashOutSuccessful') }}
                </h2>
                <p class="money mt-1 text-sm text-slate">
                    Ref #{{ String(completed.id).padStart(6, '0') }} ·
                    {{ completed.created_at }}
                </p>
            </div>
            <dl class="mt-6 divide-y divide-line border-t border-line">
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.accountCredited') }}
                    </dt>
                    <dd class="font-bold">{{ completed.to_label }}</dd>
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
                    <dt class="text-slate">{{ t('transaction.fee') }}</dt>
                    <dd class="money font-bold">
                        {{ mmk(completed.fee_amount) }} MMK
                    </dd>
                </div>
            </dl>
            <div class="mt-6 flex gap-2">
                <Link
                    href="/transactions/cash-out"
                    :headers="authHeaders()"
                    class="bank-button bank-button-primary flex-1 rounded-pill"
                >
                    {{ t('transaction.newCashOut') }}
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
            class="bank-form-shell mt-5 max-w-3xl"
            :class="
                floatLocked || cashierLocked
                    ? 'pointer-events-none opacity-50'
                    : ''
            "
        >
            <h2 class="text-base font-bold">
                {{ t('transaction.enterCashOutDetails') }}
            </h2>

            <section
                class="mt-4 rounded-field border border-line bg-mist/25 p-4"
                aria-labelledby="cash-out-credit-company-title"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3
                            id="cash-out-credit-company-title"
                            class="text-sm font-black text-ink"
                        >
                            {{ t('transaction.cashOutCreditCompany') }}
                        </h3>
                        <p class="mt-1 text-xs text-slate">
                            {{ t('transaction.cashOutCreditCompanyHint') }}
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
                                creditAccountType === 'pay'
                                    ? 'border-brand ring-1 ring-brand/20'
                                    : 'border-line hover:border-ink/30'
                            "
                        >
                            <input
                                v-model="creditAccountType"
                                type="radio"
                                name="cash_out_credit_account_type"
                                value="pay"
                                class="accent-brand"
                            />
                            <span class="text-sm font-bold">Pay</span>
                        </label>
                        <label
                            class="bank-choice flex cursor-pointer items-center gap-2 rounded-field border bg-card px-3 py-2.5 transition"
                            :class="
                                creditAccountType === 'bank'
                                    ? 'border-brand ring-1 ring-brand/20'
                                    : 'border-line hover:border-ink/30'
                            "
                        >
                            <input
                                v-model="creditAccountType"
                                type="radio"
                                name="cash_out_credit_account_type"
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
                    aria-label="Company to credit"
                >
                    <button
                        v-for="company in creditCompanies"
                        :key="company.id ?? company.name"
                        type="button"
                        role="radio"
                        :aria-checked="selectedCreditCompany === company.name"
                        class="group flex min-h-16 items-center gap-2 rounded-xl border px-3 py-2 text-left transition"
                        :class="
                            selectedCreditCompany === company.name
                                ? 'border-brand bg-brand-soft text-brand shadow-sm ring-2 ring-brand/15'
                                : 'border-line bg-card text-ink hover:border-brand/40 hover:bg-brand-soft/40'
                        "
                        @click="selectedCreditCompany = company.name"
                    >
                        <span
                            class="grid size-9 shrink-0 place-items-center overflow-hidden rounded-xl text-sm font-black"
                            :class="
                                selectedCreditCompany === company.name
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
                                        (candidate) =>
                                            candidate.company === company.name,
                                    ).length
                                }}
                                {{ t('transaction.accounts') }}
                            </span>
                        </span>
                    </button>
                </div>

                <p
                    v-if="creditCompanies.length === 0"
                    class="mt-3 rounded-field border border-dashed border-line px-3 py-3 text-xs font-semibold text-slate"
                >
                    {{ t('transaction.noSystemAccount') }}
                </p>

                <AccountTile
                    class="mt-3"
                    v-model="accountId"
                    :accounts="creditAccounts"
                    :label="t('transaction.cashOutAccountCredit')"
                />
                <p class="mt-2 text-xs text-slate">
                    {{ t('transaction.cashOutFilteredAccountHint') }}
                </p>
            </section>

            <div class="mt-5">
                <BigAmountInput
                    v-model="amount"
                    :label="t('transaction.cashOutAmount')"
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
                    account-included-in-transaction
                />
            </div>

            <div class="mt-5">
                <label class="bank-label" for="cash-out-description">{{
                    t('transaction.description')
                }}</label>
                <div class="relative">
                    <textarea
                        id="cash-out-description"
                        v-model="description"
                        maxlength="250"
                        autocomplete="off"
                        :placeholder="t('transaction.cashOut')"
                        class="bank-input resize-none pb-7"
                        aria-describedby="cash-out-description-count"
                    />
                    <span
                        id="cash-out-description-count"
                        class="money pointer-events-none absolute right-3.5 bottom-2.5 text-[11px] text-slate"
                    >
                        ({{ description.length }}/250)
                    </span>
                </div>
            </div>

            <div v-if="needsCashDenoms" class="mt-5">
                <DenomDrawer
                    v-model="denoms"
                    :notes="notes"
                    :target="amount || 0"
                    :stock="cashStock"
                    :label="
                        role === 'admin'
                            ? t('transaction.notesMainVault')
                            : t('transaction.notesMyVault')
                    "
                />
            </div>

            <div v-if="needsCashFeeDenoms" class="mt-5">
                <DenomDrawer
                    v-model="feeDenoms"
                    :notes="notes"
                    :target="feeNum"
                    :enforce-stock="false"
                    :label="t('transaction.cashFeeReceivedNotes')"
                    id-prefix="cash-out-fee-denomination"
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

        <section
            v-else-if="view === 'entry'"
            class="bank-form-shell mt-5 max-w-xl"
        >
            <h2 class="text-base font-bold">
                {{ t('transaction.reviewCashOut') }}
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
                            {{ t('transaction.customerReceives') }}
                        </p>
                        <p class="mt-1 text-[13px] font-semibold text-slate">
                            {{ t('transaction.cashPaidCustomer') }}
                        </p>
                    </div>
                    <p class="money text-right text-2xl font-black text-balance">
                        {{ mmk(customerCashPayout) }}
                        <span class="text-xs text-slate">MMK</span>
                    </p>
                </div>
                <div
                    v-if="customerFeeDue > 0"
                    class="mt-3 flex items-center justify-between gap-4 border-t border-balance/15 pt-3 text-sm"
                >
                    <span class="font-semibold text-slate">
                        {{ t('transaction.fee') }}
                    </span>
                    <span class="money font-black text-balance">
                        +{{ mmk(customerFeeDue) }} MMK
                    </span>
                </div>
            </div>

            <dl class="mt-5 divide-y divide-line border-y border-line">
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.cashOutAccountCredit') }}
                    </dt>
                    <dd class="text-right font-bold">
                        {{ account?.name }}
                        <span class="block text-[11px] font-medium text-slate"
                            >{{ creditAccountType.toUpperCase() }} ·
                            {{ account?.company }}</span
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
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.agentCommission') }}
                    </dt>
                    <dd class="money font-bold">
                        +{{ mmk(commissionNum) }} MMK
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
                        }}<span
                            v-if="feePaymentMethod === 'account'"
                            class="block text-[11px] font-medium text-slate"
                            >{{ account?.name }}</span
                        >
                    </dd>
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
                    <dt class="font-bold">
                        {{ t('transaction.kpayBalanceIncreased') }}
                        <span
                            v-if="feePaymentMethod === 'account'"
                            class="block text-[11px] font-medium text-slate"
                        >
                            {{ account?.name }}
                        </span>
                    </dt>
                    <dd class="money font-bold text-balance">
                        +{{ mmk(accountCreditAmount) }} MMK
                    </dd>
                </div>
                <div
                    v-if="role === 'teller'"
                    class="flex justify-between py-3 text-sm"
                >
                    <dt class="font-bold">
                        {{ t('transaction.notesMyVault') }}
                    </dt>
                    <dd class="money font-bold text-brand">
                        −{{ mmk(amount) }} MMK
                    </dd>
                </div>
                <div
                    v-if="role === 'admin'"
                    class="flex justify-between py-3 text-sm"
                >
                    <dt class="font-bold">
                        {{ t('transaction.notesMainVault') }}
                    </dt>
                    <dd class="money font-bold text-brand">
                        −{{ mmk(amount) }} MMK
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
                            : t('transaction.confirmCashOut')
                    }}
                </button>
            </div>
        </section>
    </BankLayout>
</template>
