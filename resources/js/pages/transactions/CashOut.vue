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
const amount = ref(0);
const description = ref('');
const denoms = ref<Record<number, number>>({});
const submitting = ref(false);
const errors = ref<Record<string, string>>({});
const feePaymentMethod = ref<FeePaymentMethod>('cash');
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
const needsCashDenoms = computed(() => props.cashOutRequiresDenominations);
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
        feePaymentMethod.value === 'cash' ||
        feeAccountId.value !== null,
);
const ready = computed(
    () =>
        accountId.value !== null &&
        amount.value > 0 &&
        (!needsCashDenoms.value || denomTotal.value === amount.value) &&
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

const mmk = (value: string | number) => Number(value).toLocaleString();
function authHeaders(): Record<string, string> {
    const token = readStoredToken();

    return token ? { Authorization: `Bearer ${token}` } : {};
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

        <TransactionHistoryTable
            v-if="view === 'history'"
            :rows="history"
            :title="`${t('transaction.cashOut')} ${t('common.history', 'History')}`"
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
                {{ t('transaction.enterDetails') }}
            </h2>

            <div class="mt-4">
                <AccountTile
                    v-model="accountId"
                    :accounts="accounts"
                    :label="t('transaction.accountCredit')"
                />
            </div>

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

            <dl class="mt-5 divide-y divide-line border-y border-line">
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">
                        {{ t('transaction.accountCredit') }}
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
                            >{{
                                feeAccounts.find(
                                    (item) => item.id === feeAccountId,
                                )?.name
                            }}</span
                        >
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="font-bold">
                        {{ t('transaction.kpayBalanceIncreased') }}
                    </dt>
                    <dd class="money font-bold text-balance">
                        +{{ mmk(amount) }} MMK
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
