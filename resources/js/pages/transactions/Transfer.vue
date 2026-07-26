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
const amount = ref(0);
const description = ref('');
const denoms = ref<Record<number, number>>({});
const submitting = ref(false);
const errors = ref<Record<string, string>>({});
const feePaymentMethod = ref<FeePaymentMethod>('cash');
const feeAccountId = ref<number | null>(null);
const { t } = useLocale();

const feeNum = computed(() => Number(props.fee ?? 0));
const from = computed(() => props.accounts.find((a) => a.id === fromId.value));
const to = computed(() => props.accounts.find((a) => a.id === toId.value));
const totalDebit = computed(
    () =>
        (amount.value || 0) +
        (feePaymentMethod.value === 'account' ? feeNum.value : 0),
);
const denomTotal = computed(() =>
    props.notes.reduce((s, n) => s + n * (denoms.value[n] ?? 0), 0),
);

const floatLocked = computed(
    () => props.requiresDenominations && props.float?.status !== 'ACTIVE',
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
        fromId.value !== null &&
        toId.value !== null &&
        fromId.value !== toId.value &&
        amount.value > 0 &&
        Number(from.value?.balance ?? 0) >= totalDebit.value &&
        (!props.requiresDenominations || denomTotal.value === amount.value) &&
        feePaymentValid.value &&
        !floatLocked.value &&
        !cashierLocked.value,
);

/** Fee is server truth — debounce-reload it when the slip changes. */
let feeTimer: ReturnType<typeof setTimeout>;
watch([amount, fromId], ([a, f]) => {
    clearTimeout(feeTimer);

    if (a > 0 && f) {
        feeTimer = setTimeout(
            () =>
                router.reload({
                    only: ['fee'],
                    data: { amount: a, account_id: f },
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

function submit() {
    submitting.value = true;
    router.post(
        '/transactions/transfer',
        {
            from_account_id: fromId.value,
            to_account_id: toId.value,
            amount: amount.value,
            note: description.value,
            fee_payment_method: feePaymentMethod.value,
            fee_account_id:
                feePaymentMethod.value === 'account'
                    ? feeAccountId.value
                    : null,
            ...(props.requiresDenominations
                ? { denominations: denoms.value }
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

        <TransactionHistoryTable
            v-if="view === 'history'"
            :rows="history"
            :title="`${t('transaction.transfer')} ${t('common.history', 'History')}`"
        />

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
            <dl class="mt-6 divide-y divide-line border-t border-line">
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
                {{ t('transaction.enterDetails') }}
            </h2>

            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <AccountTile
                    v-model="fromId"
                    :accounts="accounts"
                    :label="t('transaction.sourceAccount')"
                    :must-cover="totalDebit"
                    :exclude="toId ? [toId] : []"
                />
                <AccountTile
                    v-model="toId"
                    :accounts="accounts"
                    :label="t('transaction.destinationAccount')"
                    :exclude="fromId ? [fromId] : []"
                />
            </div>

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

            <div v-if="requiresDenominations" class="mt-5">
                <DenomDrawer
                    v-model="denoms"
                    :notes="notes"
                    :target="amount || 0"
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

            <dl class="mt-5 divide-y divide-line border-y border-line">
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">From</dt>
                    <dd class="text-right font-bold">
                        {{ from?.name }}
                        <span
                            class="block text-[11px] font-medium text-slate"
                            >{{ from?.company }}</span
                        >
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">To</dt>
                    <dd class="text-right font-bold">
                        {{ to?.name }}
                        <span
                            class="block text-[11px] font-medium text-slate"
                            >{{ to?.company }}</span
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
                        Total debited from {{ from?.name }}
                    </dt>
                    <dd class="money font-bold text-brand">
                        −{{ mmk(totalDebit) }} MMK
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="font-bold">
                        {{ t('transaction.accountCredited') }}: {{ to?.name }}
                    </dt>
                    <dd class="money font-bold text-balance">
                        +{{ mmk(amount) }} MMK
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
