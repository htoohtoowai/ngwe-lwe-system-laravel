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
            company: string;
            company_id?: number | null;
            company_logo_url?: string | null;
            service?: string;
            service_type_id?: number | null;
            name: string;
            number?: string;
            balance: string;
        }[];
        serviceTypes: {
            id: number;
            company_id?: number | null;
            company: string;
            company_logo_url?: string | null;
            name: string;
            operation: string;
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
const selectedServiceTypeId = ref<number | null>(null);
const customerName = ref('');
const customerPhone = ref('');
const amount = ref(0);
const description = ref('');
const screenshot = ref<File | null>(null);
const receivedDenoms = ref<Record<number, number>>({});
const changeDenoms = ref<Record<number, number>>({});
const feePaymentMethod = ref<FeePaymentMethod>('cash');
const feeAccountId = ref<number | null>(null);
const submitting = ref(false);
const errors = ref<Record<string, string>>({});
const { t } = useLocale();

const feeNum = computed(() => Number(props.fee ?? 0));
const account = computed(() =>
    props.accounts.find((a) => a.id === accountId.value),
);
const feeAccount = computed(() =>
    props.feeAccounts.find((a) => a.id === feeAccountId.value),
);
const cashInServiceTypes = computed(() =>
    props.serviceTypes.filter(
        (serviceType) =>
            serviceType.operation === 'CashIn' ||
            serviceType.operation === 'All' ||
            ['WST', 'Pay_To_Pay', 'P2P'].includes(serviceType.name),
    ),
);
const companies = computed(() => {
    const unique = new Map<
        string,
        { id: number | null; name: string; logoUrl: string | null }
    >();

    for (const serviceType of cashInServiceTypes.value) {
        if (!serviceType.company || unique.has(serviceType.company)) {
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
const cashInServiceOptions = computed(() =>
    cashInServiceTypes.value.filter(
        (serviceType) =>
            !selectedCompany.value ||
            serviceType.company === selectedCompany.value,
    ),
);
const visibleAccounts = computed(() =>
    props.accounts.filter(
        (account) =>
            (!selectedCompany.value ||
                account.company === selectedCompany.value) &&
            (selectedServiceTypeId.value === null ||
                account.service_type_id === selectedServiceTypeId.value),
    ),
);
const receivedTotal = computed(() =>
    props.notes.reduce(
        (sum, note) => sum + note * (receivedDenoms.value[note] ?? 0),
        0,
    ),
);
const cashInNeedsDenoms = computed(() => props.cashInRequiresDenominations);
const accountBalanceRequired = computed(
    () =>
        amount.value +
        (feePaymentMethod.value === 'account' ? feeNum.value : 0),
);
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
const changeTotal = computed(() =>
    props.notes.reduce(
        (sum, note) => sum + note * (changeDenoms.value[note] ?? 0),
        0,
    ),
);
const changeBalanced = computed(() => changeTotal.value === changeDue.value);
const handoffStock = computed(() =>
    props.notes.reduce(
        (stock, note) => {
            stock[note] = Math.max(
                0,
                (props.floatStock[note] ?? 0) +
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
        const available = handoffStock.value[note] ?? 0;
        const quantity = Math.min(Math.floor(remaining / note), available);

        if (quantity > 0) {
            result[note] = quantity;
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

watch(
    companies,
    (values) => {
        if (!values.some((company) => company.name === selectedCompany.value)) {
            selectedCompany.value = values[0]?.name ?? '';
        }
    },
    { immediate: true },
);
watch(
    cashInServiceOptions,
    (values) => {
        if (
            !values.some(
                (serviceType) => serviceType.id === selectedServiceTypeId.value,
            )
        ) {
            selectedServiceTypeId.value = values[0]?.id ?? null;
        }
    },
    { immediate: true },
);
watch([selectedCompany, selectedServiceTypeId], () => {
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
            note: description.value,
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

        <TransactionHistoryTable
            v-if="view === 'history'"
            :rows="history"
            :title="`${t('transaction.cashIn')} ${t('common.history', 'History')}`"
        />

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
            class="bank-form-shell mt-5 max-w-6xl"
            :class="
                floatLocked || cashierLocked
                    ? 'pointer-events-none opacity-50'
                    : ''
            "
        >
            <h2 class="text-base font-bold">
                {{ t('transaction.enterDetails') }}
            </h2>

            <div class="mt-4 grid items-start gap-5 xl:grid-cols-2">
                <div class="min-w-0 space-y-4">
                    <section
                        class="rounded-2xl border border-line bg-card p-4 shadow-sm sm:p-5"
                        aria-labelledby="cash-in-provider-title"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3
                                    id="cash-in-provider-title"
                                    class="text-sm font-black text-ink"
                                >
                                    {{ t('transaction.company', 'Company') }}
                                </h3>
                                <p class="mt-1 text-xs text-slate">
                                    {{ t('transaction.chooseCompanyFirst') }}
                                </p>
                            </div>
                            <span class="text-xs font-bold text-brand">{{
                                t('component.required')
                            }}</span>
                        </div>

                        <div
                            class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4"
                            role="radiogroup"
                            aria-label="Company"
                        >
                            <button
                                v-for="company in companies"
                                :key="company.id ?? company.name"
                                type="button"
                                role="radio"
                                :aria-checked="selectedCompany === company.name"
                                class="group flex min-h-16 items-center gap-2 rounded-xl border px-3 py-2 text-left transition"
                                :class="
                                    selectedCompany === company.name
                                        ? 'border-brand bg-brand-soft text-brand shadow-sm ring-2 ring-brand/15'
                                        : 'border-line bg-mist/40 text-ink hover:border-brand/40 hover:bg-brand-soft/40'
                                "
                                @click="selectedCompany = company.name"
                            >
                                <span
                                    class="grid size-9 shrink-0 place-items-center overflow-hidden rounded-xl text-sm font-black"
                                    :class="
                                        selectedCompany === company.name
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
                                        {{
                                            props.accounts.filter(
                                                (account) =>
                                                    account.company ===
                                                    company.name,
                                            ).length
                                        }}
                                        {{ t('transaction.accounts') }}
                                    </span>
                                </span>
                            </button>
                        </div>

                        <div class="mt-4">
                            <label
                                class="bank-label"
                                for="cash-in-service-type"
                                >{{
                                    t('transaction.serviceType', 'Service type')
                                }}</label
                            >
                            <select
                                id="cash-in-service-type"
                                v-model.number="selectedServiceTypeId"
                                class="bank-input mt-1.5 h-11"
                                :disabled="!cashInServiceOptions.length"
                            >
                                <option :value="null" disabled>
                                    {{ t('transaction.chooseServiceType') }}
                                </option>
                                <option
                                    v-for="service in cashInServiceOptions"
                                    :key="service.id"
                                    :value="service.id"
                                >
                                    {{ service.name }}
                                </option>
                            </select>
                        </div>
                    </section>

                    <AccountTile
                        v-model="accountId"
                        :accounts="visibleAccounts"
                        :label="t('transaction.accountDebit')"
                        :must-cover="accountBalanceRequired"
                    />

                    <BigAmountInput
                        v-model="amount"
                        :label="t('transaction.cashInAmount')"
                    />

                    <div
                        class="flex items-center justify-between rounded-field bg-mist px-4 py-3"
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

                    <FeePaymentSelector
                        v-model="feePaymentMethod"
                        v-model:fee-account-id="feeAccountId"
                        :fee="feeNum"
                        :fee-accounts="feeAccounts"
                    />
                </div>

                <div class="min-w-0">
                    <div class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label
                                    class="bank-label"
                                    for="cash-in-customer-name"
                                    >{{ t('transaction.customerName') }}</label
                                >
                                <input
                                    id="cash-in-customer-name"
                                    v-model="customerName"
                                    type="text"
                                    autocomplete="name"
                                    class="bank-input mt-1.5"
                                    :placeholder="t('transaction.customerName')"
                                />
                            </div>
                            <div>
                                <label
                                    class="bank-label"
                                    for="cash-in-customer-phone"
                                    >{{ t('transaction.customerPhone') }}</label
                                >
                                <input
                                    id="cash-in-customer-phone"
                                    v-model="customerPhone"
                                    type="tel"
                                    autocomplete="tel"
                                    class="bank-input mt-1.5"
                                    :placeholder="
                                        t('transaction.customerPhone')
                                    "
                                />
                            </div>
                        </div>

                        <label class="bank-label" for="cash-in-description">{{
                            t('transaction.description')
                        }}</label>
                        <div class="relative">
                            <textarea
                                id="cash-in-description"
                                v-model="description"
                                maxlength="250"
                                rows="4"
                                autocomplete="off"
                                :placeholder="t('transaction.cashIn')"
                                class="bank-input resize-none pb-7"
                                aria-describedby="cash-in-description-count"
                            />
                            <span
                                id="cash-in-description-count"
                                class="money pointer-events-none absolute right-3.5 bottom-2.5 text-[11px] text-slate"
                            >
                                ({{ description.length }}/250)
                            </span>
                        </div>

                        <div>
                            <label
                                class="bank-label"
                                for="cash-in-screenshot"
                                >{{ t('transaction.screenshot') }}</label
                            >
                            <label
                                for="cash-in-screenshot"
                                class="mt-1.5 flex cursor-pointer items-center justify-between gap-3 rounded-field border border-dashed border-line bg-card px-4 py-3 text-sm transition hover:border-brand/50 hover:bg-brand-soft/30"
                            >
                                <span class="min-w-0">
                                    <span class="block font-bold text-ink">{{
                                        screenshot?.name ??
                                        t('transaction.attachScreenshot')
                                    }}</span>
                                    <span
                                        class="mt-0.5 block truncate text-xs text-slate"
                                        >{{
                                            t('transaction.screenshotHint')
                                        }}</span
                                    >
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

                <div
                    v-if="cashInNeedsDenoms && canCountCash"
                    class="min-w-0 xl:col-span-2"
                >
                    <div
                        class="mb-4 flex items-start gap-3 rounded-2xl border border-brand/15 bg-brand-soft px-4 py-3.5 sm:px-5"
                    >
                        <span
                            class="grid size-8 shrink-0 place-items-center rounded-full bg-brand text-xs font-black text-white"
                            >01</span
                        >
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-ink">
                                {{ t('transaction.cashInDenominationHint') }}
                            </p>
                            <p class="mt-1 text-xs leading-5 text-slate">
                                {{ t('transaction.cashInDescription') }}
                            </p>
                        </div>
                    </div>

                    <div class="grid items-start gap-4">
                        <section
                            class="overflow-hidden rounded-2xl border-2 border-brand/20 bg-card shadow-sm"
                            aria-labelledby="cash-in-customer-cash-title"
                        >
                            <header
                                class="flex items-start justify-between gap-3 border-b border-line bg-brand-soft/55 px-4 py-4 sm:px-5"
                            >
                                <div class="flex min-w-0 items-start gap-3">
                                    <span
                                        class="grid size-9 shrink-0 place-items-center rounded-xl bg-brand text-xs font-black text-white"
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
                                        <p
                                            class="mt-1 text-xs leading-5 text-slate"
                                        >
                                            {{ t('transaction.cashReceived') }}
                                        </p>
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
                            </header>
                            <div class="p-3 sm:p-4">
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
                                class="flex items-center justify-between border-t border-line bg-mist/45 px-4 py-3 text-xs sm:px-5"
                            >
                                <span class="font-semibold text-slate">{{
                                    t('transaction.cashReceived')
                                }}</span>
                                <span class="money font-black text-ink"
                                    >{{ mmk(receivedTotal) }} MMK</span
                                >
                            </footer>
                        </section>

                        <section
                            v-if="role === 'teller' && changeDue > 0"
                            class="overflow-hidden rounded-2xl border-2 border-held/20 bg-card shadow-sm"
                            aria-labelledby="cash-in-change-title"
                        >
                            <header
                                class="flex items-start justify-between gap-3 border-b border-line bg-held/5 px-4 py-4 sm:px-5"
                            >
                                <div class="flex min-w-0 items-start gap-3">
                                    <span
                                        class="grid size-9 shrink-0 place-items-center rounded-xl bg-held text-xs font-black text-white"
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
                                            class="mt-1 text-xs leading-5 text-slate"
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
                            </header>
                            <div class="p-3 sm:p-4">
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
                                class="flex items-center justify-between border-t px-4 py-3 text-xs sm:px-5"
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

            <div
                class="sticky bottom-3 z-10 mt-5 flex justify-end rounded-pill bg-card/90 p-1 backdrop-blur sm:mt-6"
            >
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
                    v-if="description"
                    class="flex justify-between gap-6 py-3 text-sm"
                >
                    <dt class="shrink-0 text-slate">
                        {{ t('transaction.description') }}
                    </dt>
                    <dd class="text-right">{{ description }}</dd>
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
