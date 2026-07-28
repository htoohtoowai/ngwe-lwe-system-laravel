<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AccountTile from '@/components/bank/AccountTile.vue';
import BigAmountInput from '@/components/bank/BigAmountInput.vue';
import DenomDrawer from '@/components/bank/DenomDrawer.vue';
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
        rate: { buy_rate: string; sell_rate: string };
        requiresDenominations: boolean;
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
const amount = ref(0);
const currency = ref<'MMK' | 'THB'>('MMK');
const exchangePaymentDisplayMethod = ref<'pay' | 'bank' | 'cash'>('pay');
const exchangePaymentMethod = ref<'cash' | 'account'>('account');
const customerName = ref('');
const customerPhone = ref('');
const description = ref('');
const denoms = ref<Record<number, number>>({});
const receivedDenoms = ref<Record<number, number>>({});
const submitting = ref(false);
const errors = ref<Record<string, string>>({});
const feePaymentMethod = ref<'cash' | 'account'>('cash');
const feeAccountId = ref<number | null>(null);
const { t } = useLocale();

const feeNum = computed(() => Number(props.fee ?? 0));
const account = computed(() =>
    props.accounts.find((a) => a.id === accountId.value),
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
const exchangeCustomerActionAmount = computed(() => mmkSettlementAmount.value);
const exchangeCustomerTotalDue = computed(
    () => mmkSettlementAmount.value + feeNum.value,
);
const cashReceivedIsCash = computed(
    () => exchangePaymentDisplayMethod.value === 'cash',
);
const needsPayoutDenoms = computed(
    () => props.role === 'teller' && currency.value === 'THB',
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

const money = (value: string | number) =>
    Number(value).toLocaleString(undefined, {
        maximumFractionDigits: currency.value === 'THB' ? 2 : 0,
    });
const mmk = (value: string | number) => Number(value).toLocaleString();
function selectExchangePayment(method: 'pay' | 'bank' | 'cash'): void {
    exchangePaymentDisplayMethod.value = method;
    exchangePaymentMethod.value = method === 'cash' ? 'cash' : 'account';
}
function authHeaders(): Record<string, string> {
    const token = readStoredToken();

    return token ? { Authorization: `Bearer ${token}` } : {};
}

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

        <TransactionHistoryTable
            v-if="view === 'history'"
            :rows="history"
            :title="`${t('transaction.exchange')} ${t('common.history', 'History')}`"
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
                        <span class="text-[11px] text-slate">MMK</span>
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
            class="bank-form-shell mt-5 max-w-3xl"
            :class="
                floatLocked || cashierLocked
                    ? 'pointer-events-none opacity-50'
                    : ''
            "
        >
            <h2 class="text-base font-bold">Enter Details</h2>

            <div class="mt-4 grid items-stretch gap-4 md:grid-cols-2">
                <div>
                    <p class="mb-1.5 text-[13px] font-semibold text-slate">
                        {{ t('transaction.direction') }}
                    </p>
                    <div class="flex min-h-16 rounded-field bg-mist p-1">
                        <button
                            type="button"
                            class="bank-choice w-1/2 rounded-field px-4 py-3 text-sm font-bold transition"
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
                            class="bank-choice w-1/2 rounded-field px-4 py-3 text-sm font-bold transition"
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
                <AccountTile
                    v-model="accountId"
                    :accounts="accounts"
                    :label="t('transaction.exchangeAccount')"
                />
            </div>

            <div class="mt-5">
                <BigAmountInput
                    v-model="amount"
                    :currency="currency"
                    currency-class="font-medium text-slate"
                    :reading-currency-label="
                        currency === 'THB' ? 'ဘတ်' : 'ကျပ်'
                    "
                    :label="t('transaction.cashToExchange')"
                    :chips="
                        currency === 'THB' ? [100, 500, 1000, 5000] : undefined
                    "
                />
            </div>

            <div class="mt-5 space-y-4">
                <div>
                    <p class="mb-1.5 text-[13px] font-semibold text-slate">
                        {{ t('transaction.cashReceived') }}
                    </p>
                    <div class="grid grid-cols-3 rounded-field bg-mist p-1">
                        <button
                            type="button"
                            class="bank-choice rounded-field px-4 py-3 text-sm font-bold transition"
                            :aria-pressed="
                                exchangePaymentDisplayMethod === 'pay'
                            "
                            :class="
                                exchangePaymentDisplayMethod === 'pay'
                                    ? 'bg-card text-ink shadow-sm'
                                    : 'text-slate'
                            "
                            @click="selectExchangePayment('pay')"
                        >
                            Pay
                        </button>
                        <button
                            type="button"
                            class="bank-choice rounded-field px-4 py-3 text-sm font-bold transition"
                            :aria-pressed="
                                exchangePaymentDisplayMethod === 'bank'
                            "
                            :class="
                                exchangePaymentDisplayMethod === 'bank'
                                    ? 'bg-card text-ink shadow-sm'
                                    : 'text-slate'
                            "
                            @click="selectExchangePayment('bank')"
                        >
                            Bank
                        </button>
                        <button
                            type="button"
                            class="bank-choice rounded-field px-4 py-3 text-sm font-bold transition"
                            :aria-pressed="
                                exchangePaymentDisplayMethod === 'cash'
                            "
                            :class="
                                exchangePaymentDisplayMethod === 'cash'
                                    ? 'bg-card text-ink shadow-sm'
                                    : 'text-slate'
                            "
                            @click="selectExchangePayment('cash')"
                        >
                            Cash
                        </button>
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="bank-label" for="exchange-customer-name">
                            {{
                                exchangePaymentDisplayMethod === 'bank'
                                    ? 'Beneficiary Name'
                                    : t('transaction.customerName')
                            }}
                        </label>
                        <input
                            id="exchange-customer-name"
                            v-model="customerName"
                            type="text"
                            autocomplete="name"
                            class="bank-input mt-1.5"
                            :placeholder="
                                exchangePaymentDisplayMethod === 'bank'
                                    ? 'Beneficiary Name'
                                    : t('transaction.customerName')
                            "
                        />
                    </div>
                    <div>
                        <label class="bank-label" for="exchange-customer-phone">
                            {{
                                exchangePaymentDisplayMethod === 'bank'
                                    ? 'Account Number'
                                    : t('transaction.customerPhone')
                            }}
                        </label>
                        <input
                            id="exchange-customer-phone"
                            v-model="customerPhone"
                            type="tel"
                            :inputmode="
                                exchangePaymentDisplayMethod === 'bank'
                                    ? 'numeric'
                                    : 'tel'
                            "
                            :autocomplete="
                                exchangePaymentDisplayMethod === 'bank'
                                    ? 'off'
                                    : 'tel'
                            "
                            class="bank-input mt-1.5"
                            :placeholder="
                                exchangePaymentDisplayMethod === 'bank'
                                    ? 'Account Number'
                                    : t('transaction.customerPhone')
                            "
                        />
                    </div>
                </div>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div class="rounded-field bg-mist px-4 py-3">
                        <p class="text-[13px] font-semibold text-slate">
                            {{ t('transaction.sellRate') }} /
                            {{ t('transaction.buyRate') }}
                        </p>
                        <p class="money text-sm font-bold">{{ mmk(activeRate) }}</p>
                    </div>
                    <div class="rounded-field bg-mist px-4 py-3">
                        <p class="text-[13px] font-semibold text-slate">MMK</p>
                        <p class="money text-sm font-bold">
                            {{ mmk(mmkSettlementAmount) }}
                        </p>
                    </div>
                </div>
                <section
                    v-if="needsReceivedDenoms"
                    class="overflow-hidden rounded-2xl border-2 border-brand/20 bg-card shadow-sm"
                    aria-labelledby="exchange-customer-cash-title"
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
                                    id="exchange-customer-cash-title"
                                    class="text-sm font-bold text-ink"
                                >
                                    {{ t('transaction.cashReceivedCustomer') }}
                                </h3>
                                <p class="mt-1 text-xs leading-5 text-slate">
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
                                {{ mmk(receivedDenomTotal) }}
                            </p>
                            <p class="text-[10px] font-bold text-slate">MMK</p>
                        </div>
                    </header>
                    <div class="p-3 sm:p-4">
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
                    <footer
                        class="flex items-center justify-between border-t border-line bg-mist/45 px-4 py-3 text-xs sm:px-5"
                    >
                        <span class="font-semibold text-slate">{{
                            t('transaction.cashReceived')
                        }}</span>
                        <span class="money font-black text-ink"
                            >{{ mmk(receivedDenomTotal) }} MMK</span
                        >
                    </footer>
                </section>
            </div>



            <div class="mt-5">
                <label class="bank-label" for="exchange-description">{{
                    t('transaction.description')
                }}</label>
                <div class="relative">
                    <textarea
                        id="exchange-description"
                        v-model="description"
                        maxlength="250"
                        rows="4"
                        autocomplete="off"
                        :placeholder="t('transaction.exchange')"
                        class="bank-input resize-none pb-7"
                        aria-describedby="exchange-description-count"
                    />
                    <span
                        id="exchange-description-count"
                        class="money pointer-events-none absolute right-3.5 bottom-2.5 text-[11px] text-slate"
                    >
                        ({{ description.length }}/250)
                    </span>
                </div>
            </div>

            <div v-if="needsPayoutDenoms" class="mt-5">
                <DenomDrawer
                    v-model="denoms"
                    :notes="notes"
                    :target="mmkSettlementAmount"
                    :stock="floatStock"
                    :label="t('transaction.notesMyVault')"
                />
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
                        {{
                            mmk(
                                currency === 'MMK'
                                    ? exchangeCustomerTotalDue
                                    : exchangeCustomerActionAmount,
                            )
                        }}
                        <span class="text-xs text-slate">MMK</span>
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
                    <dt class="font-bold">
                        {{ t('transaction.accountCredited') }}:
                        {{ account?.name }}
                    </dt>
                    <dd class="money font-bold text-balance">
                        +{{ mmk(mmkSettlementAmount) }} MMK
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
