<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AccountPicker from '@/components/teller/AccountPicker.vue';
import AmountField from '@/components/teller/AmountField.vue';
import MoneyText from '@/components/teller/MoneyText.vue';
import ReceiptSlip from '@/components/teller/ReceiptSlip.vue';
import ReviewSheet from '@/components/teller/ReviewSheet.vue';
import type { ReviewLine } from '@/components/teller/ReviewSheet.vue';
import TellerLayout from '@/layouts/TellerLayout.vue';
import { readStoredToken } from '@/lib/auth-token';
import { useLocale } from '@/lib/i18n';

type TellerAccount = {
    id: number;
    name: string;
    company: string;
    balance: string;
};
type TellerFloat = {
    id: number;
    status: string;
    current_balance: string;
} | null;
type CompletedTxn = {
    id: number;
    type: string;
    amount: string;
    fee_amount: string;
    status: string;
    created_at: string;
    account_label?: string;
    change_given?: string;
    customer_name?: string | null;
    customer_phone?: string | null;
};

const props = defineProps<{
    float: TellerFloat;
    notes: number[];
    floatStock: Record<number, number>;
    accounts: TellerAccount[];
    fee: string;
    completed?: CompletedTxn | null;
}>();

const fromAccountId = ref<number | null>(null);
const toAccountId = ref<number | null>(null);
const amount = ref<number>(0);
const customerName = ref('');
const customerPhone = ref('');
const reviewing = ref(false);
const submitting = ref(false);
const errors = ref<Record<string, string>>({});
const { t } = useLocale();

const feeNum = computed(() => Number(props.fee ?? 0));
const floatBalance = computed(() => Number(props.float?.current_balance ?? 0));

const ready = computed(
    () =>
        fromAccountId.value !== null &&
        toAccountId.value !== null &&
        fromAccountId.value !== toAccountId.value &&
        amount.value > 0 &&
        customerName.value.trim().length > 0 &&
        customerPhone.value.trim().length > 0,
);

let feeTimer: ReturnType<typeof setTimeout>;
watch([amount, fromAccountId], ([a, acc]) => {
    clearTimeout(feeTimer);

    if (a > 0 && acc) {
        feeTimer = setTimeout(
            () =>
                router.reload({
                    only: ['fee'],
                    data: { amount: a, account_id: acc },
                    headers: authHeaders(),
                }),
            350,
        );
    }
});

const reviewLines = computed<ReviewLine[]>(() => [
    {
        label: t('transaction.customerName'),
        value: customerName.value,
        kind: 'text',
    },
    {
        label: t('transaction.customerPhone'),
        value: customerPhone.value,
        kind: 'text',
    },
    {
        label: `${t('transaction.fee')} (${t('transaction.commissionTier')})`,
        value: feeNum.value,
    },
    {
        label: t('transaction.sourceAccount'),
        value: amount.value || 0,
        signed: 'debit',
    },
    {
        label: t('transaction.destinationAccount'),
        value: amount.value || 0,
        signed: 'credit',
        emphasize: true,
    },
    {
        label: t('transaction.floatAfterTransfer'),
        value: floatBalance.value,
    },
]);

function authHeaders(): Record<string, string> {
    const token = readStoredToken();

    return token ? { Authorization: `Bearer ${token}` } : {};
}

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function submit() {
    submitting.value = true;
    errors.value = {};

    router.post(
        '/transactions/transfer',
        {
            _token: csrfToken(),
            from_account_id: fromAccountId.value,
            to_account_id: toAccountId.value,
            amount: amount.value,
            customer_name: customerName.value.trim(),
            customer_phone: customerPhone.value.trim(),
        },
        {
            headers: authHeaders(),
            onError: (e) => {
                errors.value = e as Record<string, string>;
                reviewing.value = false;
            },
            onFinish: () => (submitting.value = false),
        },
    );
}
</script>

<template>
    <TellerLayout :float="float">
        <template v-if="completed">
            <header class="mb-6 text-center">
                <h1 class="font-display text-2xl font-semibold tracking-tight">
                    {{ t('transaction.transfer') }} ပြီးပါပြီ
                </h1>
                <p class="mt-1 text-sm text-ink-700/70">
                    {{ t('transaction.completedHint') }}
                </p>
            </header>
            <ReceiptSlip
                :txn="completed"
                next-href="/transactions/transfer"
                :next-label="t('transaction.transfer')"
            />
        </template>

        <template v-else>
            <header class="mb-5">
                <h1 class="font-display text-2xl font-semibold tracking-tight">
                    {{ t('transaction.transfer') }}
                </h1>
                <p class="mt-1 text-sm text-ink-700/70">
                    {{ t('transaction.transferDescription') }}
                </p>
            </header>

            <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
                <div class="space-y-5">
                    <section
                        class="rounded-counter border border-paper-edge bg-white p-5"
                    >
                        <div class="grid gap-5">
                            <AccountPicker
                                v-model="fromAccountId"
                                :accounts="accounts"
                                :must-cover="amount"
                                :label="t('transaction.sourceAccount')"
                            />
                            <AccountPicker
                                v-model="toAccountId"
                                :accounts="accounts"
                                :label="t('transaction.destinationAccount')"
                            />
                            <AmountField
                                v-model="amount"
                                :label="t('transaction.transferAmount')"
                            />
                            <div class="grid gap-4 sm:grid-cols-2">
                                <label>
                                    <span class="field-label">{{
                                        t('transaction.customerName')
                                    }}</span>
                                    <input
                                        v-model="customerName"
                                        type="text"
                                        autocomplete="name"
                                        :placeholder="
                                            t('transaction.customerName')
                                        "
                                        class="field-input mt-1.5"
                                    />
                                </label>
                                <label>
                                    <span class="field-label">{{
                                        t('transaction.customerPhone')
                                    }}</span>
                                    <input
                                        v-model="customerPhone"
                                        type="tel"
                                        inputmode="tel"
                                        autocomplete="tel"
                                        :placeholder="
                                            t('transaction.customerPhone')
                                        "
                                        class="field-input mt-1.5"
                                    />
                                </label>
                            </div>
                            <div>
                                <p class="field-label">
                                    {{ t('transaction.fee') }} ({{
                                        t('transaction.commissionTier')
                                    }})
                                </p>
                                <p
                                    class="field-input money mt-1.5 bg-paper text-lg text-ink-700"
                                >
                                    {{ feeNum.toLocaleString() }}
                                </p>
                            </div>
                        </div>
                    </section>

                </div>

                <aside
                    class="h-fit rounded-counter border border-ink-800 bg-ink-900 p-5 text-ink-100 lg:sticky lg:top-24"
                >
                    <h2
                        class="font-display text-sm font-semibold tracking-[0.14em] text-ink-300 uppercase"
                    >
                        {{ t('transaction.slip') }}
                    </h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-ink-300">
                                {{ t('transaction.fee') }}
                            </dt>
                            <dd><MoneyText :value="feeNum" /></dd>
                        </div>
                        <div
                            class="flex justify-between border-t border-ink-800 pt-3"
                        >
                            <dt class="font-semibold">
                                {{ t('transaction.accountCredited') }}
                            </dt>
                            <dd class="font-semibold">
                                <MoneyText
                                    :value="amount || 0"
                                    signed="credit"
                                />
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-ink-300">Source debited</dt>
                            <dd>
                                <MoneyText
                                    :value="amount || 0"
                                    signed="debit"
                                />
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-ink-300">
                                {{ t('transaction.floatAfterTransfer') }}
                            </dt>
                            <dd>
                                <MoneyText :value="floatBalance" />
                            </dd>
                        </div>
                    </dl>

                    <button
                        type="button"
                        :disabled="!ready"
                        @click="reviewing = true"
                        class="mt-5 w-full rounded-counter bg-seal py-3 text-sm font-semibold text-ink-950 transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-35"
                    >
                        {{ t('common.reviewSlip') }}
                    </button>
                    <p
                        v-for="(msg, key) in errors"
                        :key="key"
                        class="mt-2 text-sm text-debit"
                    >
                        {{ msg }}
                    </p>
                </aside>
            </div>

            <ReviewSheet
                :open="reviewing"
                :busy="submitting"
                :title="t('transaction.transfer')"
                :lines="reviewLines"
                :confirm-label="t('transaction.confirmTransfer')"
                :consequence="t('transaction.transferConsequence')"
                @confirm="submit"
                @close="reviewing = false"
            />
        </template>
    </TellerLayout>
</template>
