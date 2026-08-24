<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AccountTile from '@/components/bank/AccountTile.vue';
import BigAmountInput from '@/components/bank/BigAmountInput.vue';
import CashOutSettlementDrawer from '@/components/bank/CashOutSettlementDrawer.vue';
import DenomDrawer from '@/components/bank/DenomDrawer.vue';
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
        cashOutStock?: Record<number, number>;
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
const selectedCreditCompany = ref('');
const amount = ref(0);
const description = ref('');
const denoms = ref<Record<number, number>>({});
const feeDenoms = ref<Record<number, number>>({});
const changeDenoms = ref<Record<number, number>>({});
const submitting = ref(false);
const errors = ref<Record<string, string>>({});
const feePaymentMethod = ref<FeePaymentMethod>('cash');
const feeAccountId = ref<number | null>(null);
const showCashDenoms = ref(true);
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
const creditAccounts = computed(() =>
    props.accounts.filter(
        (candidate) => candidate.company === selectedCreditCompany.value,
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
const changeDenomTotal = computed(() =>
    props.notes.reduce(
        (sum, note) => sum + note * (changeDenoms.value[note] ?? 0),
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
const cashFeeChangeDue = computed(() =>
    needsCashFeeDenoms.value
        ? Math.max(0, feeDenomTotal.value - feeNum.value)
        : 0,
);
const cashStock = computed(() =>
    props.role === 'admin' ? (props.cashOutStock ?? {}) : props.floatStock,
);
const projectedStockValid = computed(() =>
    props.notes.every(
        (note) =>
            Number(cashStock.value[note] ?? 0) -
                Number(denoms.value[note] ?? 0) +
                (needsCashFeeDenoms.value
                    ? Number(feeDenoms.value[note] ?? 0) -
                      Number(changeDenoms.value[note] ?? 0)
                    : 0) >=
            0,
    ),
);
const accountCreditAmount = computed(() =>
    feePaymentMethod.value === 'account'
        ? amount.value + feeNum.value + commissionNum.value
        : amount.value + commissionNum.value,
);
const customerCashPayout = computed(() => amount.value);
const customerFeeDue = computed(() => feeNum.value);
const tellerCashNetMovement = computed(
    () => -amount.value + (needsCashFeeDenoms.value ? feeNum.value : 0),
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
        (!needsCashFeeDenoms.value ||
            (feeDenomTotal.value >= feeNum.value &&
                changeDenomTotal.value === cashFeeChangeDue.value)) &&
        projectedStockValid.value &&
        feePaymentValid.value &&
        !floatLocked.value &&
        !cashierLocked.value,
);
const readyIssue = computed(() => {
    if (floatLocked.value) {
        return t('transaction.floatLocked');
    }

    if (cashierLocked.value) {
        return t('transaction.cashierLocked');
    }

    if (accountId.value === null) {
        return t(
            'transaction.selectAccountBeforeContinue',
            'Choose the account to credit.',
        );
    }

    if (amount.value <= 0) {
        return t('transaction.enterAmountBeforeContinue', 'Enter an amount.');
    }

    if (needsCashDenoms.value && denomTotal.value !== amount.value) {
        return t(
            'transaction.cashOutDenominationHint',
            'Count the cash paid to the customer until it matches the amount.',
        );
    }

    if (needsCashFeeDenoms.value && feeDenomTotal.value < feeNum.value) {
        return t(
            'transaction.cashFeeReceivedMinimumHint',
            'Count at least the fee amount received from the customer.',
        );
    }

    if (
        needsCashFeeDenoms.value &&
        changeDenomTotal.value !== cashFeeChangeDue.value
    ) {
        return t(
            'transaction.cashOutChangeHint',
            'Count the exact change to return to the customer.',
        );
    }

    if (!projectedStockValid.value) {
        return t(
            'transaction.projectedStockError',
            'A denomination would go below zero. Adjust payout or change notes.',
        );
    }

    return t(
        'transaction.completeRequiredFields',
        'Complete the required fields.',
    );
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

watch(creditCompanies, (nextCompanies) => {
    if (
        !nextCompanies.some(
            (company) => company.name === selectedCreditCompany.value,
        )
    ) {
        selectedCreditCompany.value = '';
    }
});

watch(selectedCreditCompany, () => {
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
                ? {
                      fee_denominations: feeDenoms.value,
                      change_denominations: changeDenoms.value,
                  }
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
            class="bank-form-shell mt-5 max-w-5xl p-5 sm:p-6"
            :class="
                floatLocked || cashierLocked
                    ? 'pointer-events-none opacity-50'
                    : ''
            "
        >
            <h2 class="text-base font-bold">
                {{ t('transaction.enterCashOutDetails') }}
            </h2>

            <div class="mt-4 space-y-4">
                <section
                    class="space-y-2"
                    aria-labelledby="cash-out-credit-company-title"
                >
                    <div>
                        <h3
                            id="cash-out-credit-company-title"
                            class="text-xs font-black text-slate"
                        >
                            {{ t('transaction.cashOutCreditCompany') }}
                        </h3>
                        <p class="mt-0.5 text-[11px] text-slate">
                            {{ t('transaction.cashOutCreditCompanyHint') }}
                        </p>
                    </div>

                    <div
                        class="flex gap-2 overflow-x-auto pb-1.5"
                        role="radiogroup"
                        aria-label="Company to credit"
                    >
                        <button
                            v-for="company in creditCompanies"
                            :key="company.id ?? company.name"
                            type="button"
                            role="radio"
                            :aria-checked="
                                selectedCreditCompany === company.name
                            "
                            :aria-label="company.name"
                            :title="company.name"
                            class="group flex min-h-12 shrink-0 items-center gap-2 rounded-field border px-2.5 py-1.5 text-left transition"
                            :class="[
                                selectedCreditCompany === company.name
                                    ? 'border-brand bg-brand-soft text-brand shadow-sm ring-2 ring-brand/15'
                                    : 'border-line bg-mist/40 text-ink hover:border-brand/40 hover:bg-brand-soft/40',
                                hasCompanyLogo(company)
                                    ? 'min-w-16 justify-center'
                                    : 'min-w-36',
                            ]"
                            @click="selectedCreditCompany = company.name"
                        >
                            <span
                                v-if="hasCompanyLogo(company)"
                                class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-lg border border-line bg-card text-xs font-black shadow-sm"
                                :class="
                                    selectedCreditCompany === company.name
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

                    <p
                        v-if="creditCompanies.length === 0"
                        class="rounded-field border border-dashed border-line px-3 py-3 text-xs font-semibold text-slate"
                    >
                        {{ t('transaction.noSystemAccount') }}
                    </p>
                </section>

                <div
                    class="grid items-start gap-3 md:grid-cols-2 xl:grid-cols-3"
                >
                    <AccountTile
                        v-model="accountId"
                        :accounts="creditAccounts"
                        :label="t('transaction.cashOutAccountCredit')"
                        compact
                    />

                    <BigAmountInput
                        v-model="amount"
                        :label="t('transaction.cashOutAmount')"
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
                        account-included-in-transaction
                        compact
                    />
                </div>

                <div>
                    <label class="bank-label" for="cash-out-description">{{
                        t('transaction.description')
                    }}</label>
                    <div class="relative">
                        <textarea
                            id="cash-out-description"
                            v-model="description"
                            maxlength="250"
                            rows="2"
                            autocomplete="off"
                            :placeholder="t('transaction.cashOut')"
                            class="bank-input min-h-12 resize-none border border-line bg-mist px-3 py-2 pr-14"
                            aria-describedby="cash-out-description-count"
                        />
                        <span
                            id="cash-out-description-count"
                            class="money pointer-events-none absolute right-3 bottom-2 text-[10px] text-slate"
                        >
                            {{ description.length }}/250
                        </span>
                    </div>
                </div>

                <section
                    v-if="needsCashDenoms && role === 'teller'"
                    class="overflow-hidden rounded-field border border-brand/20 bg-card"
                    aria-labelledby="cash-out-settlement-title"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 border-b border-line bg-brand-soft/55 px-3 py-2.5 text-left transition hover:bg-brand-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/35 sm:px-4"
                        :aria-expanded="showCashDenoms"
                        aria-controls="cash-out-settlement"
                        @click="showCashDenoms = !showCashDenoms"
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <span
                                class="grid size-6 shrink-0 place-items-center rounded-lg bg-brand text-[10px] font-black text-white"
                                >01</span
                            >
                            <h3
                                id="cash-out-settlement-title"
                                class="truncate text-sm font-bold text-ink"
                            >
                                {{
                                    t(
                                        'transaction.cashSettlement',
                                        'Cash settlement',
                                    )
                                }}
                            </h3>
                        </div>
                        <div class="ml-auto shrink-0 text-right">
                            <p
                                class="text-[10px] font-bold tracking-wide text-slate uppercase"
                            >
                                {{
                                    t(
                                        'transaction.netTellerCash',
                                        'Net teller cash',
                                    )
                                }}
                            </p>
                            <p class="money text-base font-black text-brand">
                                −{{
                                    mmk(
                                        amount -
                                            (needsCashFeeDenoms ? feeNum : 0),
                                    )
                                }}
                                <span class="text-[10px] text-slate">MMK</span>
                            </p>
                        </div>
                        <span
                            class="grid size-7 shrink-0 place-items-center rounded-full bg-card text-sm font-black text-slate shadow-sm"
                            aria-hidden="true"
                        >
                            {{ showCashDenoms ? '⌃' : '⌄' }}
                        </span>
                    </button>
                    <div
                        v-show="showCashDenoms"
                        id="cash-out-settlement"
                        class="p-2.5 sm:p-3"
                    >
                        <CashOutSettlementDrawer
                            :notes="notes"
                            :stock="cashStock"
                            :payout="denoms"
                            :fee-received="feeDenoms"
                            :change="changeDenoms"
                            :payout-target="amount || 0"
                            :fee-due="feeNum"
                            :cash-fee="needsCashFeeDenoms"
                            @update:payout="denoms = $event"
                            @update:fee-received="feeDenoms = $event"
                            @update:change="changeDenoms = $event"
                        />
                    </div>
                </section>

                <section
                    v-else-if="needsCashDenoms"
                    class="overflow-hidden rounded-field border border-brand/20 bg-card"
                    aria-labelledby="cash-out-denomination-title"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 border-b border-line bg-brand-soft/55 px-3 py-2.5 text-left transition hover:bg-brand-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/35 sm:px-4"
                        :aria-expanded="showCashDenoms"
                        aria-controls="cash-out-denominations"
                        @click="showCashDenoms = !showCashDenoms"
                    >
                        <h3
                            id="cash-out-denomination-title"
                            class="text-sm font-bold text-ink"
                        >
                            {{ t('transaction.notesMainVault') }}
                        </h3>
                        <p
                            class="money ml-auto text-base font-black text-brand"
                        >
                            {{ mmk(denomTotal) }}
                            <span class="text-[10px] text-slate">MMK</span>
                        </p>
                        <span
                            class="grid size-7 place-items-center rounded-full bg-card text-sm font-black text-slate shadow-sm"
                            aria-hidden="true"
                        >
                            {{ showCashDenoms ? '⌃' : '⌄' }}
                        </span>
                    </button>
                    <div
                        v-show="showCashDenoms"
                        id="cash-out-denominations"
                        class="p-2.5 sm:p-3"
                    >
                        <DenomDrawer
                            v-model="denoms"
                            :notes="notes"
                            :target="amount || 0"
                            :stock="cashStock"
                            :label="t('transaction.notesMainVault')"
                            id-prefix="cash-out-denomination"
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
                        <p class="text-xs font-black text-balance uppercase">
                            {{ t('transaction.customerReceives') }}
                        </p>
                        <p class="mt-1 text-[13px] font-semibold text-slate">
                            {{ t('transaction.cashPaidCustomer') }}
                        </p>
                    </div>
                    <p
                        class="money text-right text-2xl font-black text-balance"
                    >
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
                        {{ t('transaction.feeReceived', 'Fee cash received') }}
                    </dt>
                    <dd class="money font-bold text-balance">
                        +{{ mmk(feeDenomTotal) }} MMK
                    </dd>
                </div>
                <div
                    v-if="needsCashFeeDenoms && cashFeeChangeDue > 0"
                    class="flex justify-between py-3 text-sm"
                >
                    <dt class="font-bold">
                        {{
                            t(
                                'transaction.changeToCustomer',
                                'Change to customer',
                            )
                        }}
                    </dt>
                    <dd class="money font-bold text-brand">
                        −{{ mmk(changeDenomTotal) }} MMK
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
                        {{ t('transaction.netTellerCash', 'Net teller cash') }}
                    </dt>
                    <dd
                        class="money font-bold"
                        :class="
                            tellerCashNetMovement < 0
                                ? 'text-brand'
                                : 'text-balance'
                        "
                    >
                        {{ tellerCashNetMovement > 0 ? '+' : ''
                        }}{{ mmk(tellerCashNetMovement) }}
                        MMK
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
