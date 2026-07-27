<script setup lang="ts">
import { useLocale } from '@/lib/i18n';

export type FeePaymentMethod = 'cash' | 'account';

withDefaults(
    defineProps<{
        modelValue: FeePaymentMethod;
        feeAccountId: number | null;
        fee: number;
        feeAccounts: {
            id: number;
            company: string;
            name: string;
            number?: string;
            balance: string;
        }[];
        disabled?: boolean;
        accountIncludedInTransaction?: boolean;
    }>(),
    { disabled: false, accountIncludedInTransaction: false },
);

const emit = defineEmits<{
    'update:modelValue': [FeePaymentMethod];
    'update:feeAccountId': [number | null];
}>();
const { t } = useLocale();

function setMethod(method: FeePaymentMethod) {
    emit('update:modelValue', method);

    if (method === 'cash') {
        emit('update:feeAccountId', null);
    }
}

function setAccount(event: Event) {
    const value = Number((event.target as HTMLSelectElement).value);
    emit(
        'update:feeAccountId',
        Number.isInteger(value) && value > 0 ? value : null,
    );
}
</script>

<template>
    <fieldset
        class="rounded-field border border-line bg-mist/60 px-4 py-3.5"
        :disabled="disabled"
    >
        <legend class="px-1 text-[13px] font-bold text-slate">
            {{ t('transaction.feePaymentMethod') }}
        </legend>

        <div class="grid gap-2 sm:grid-cols-2">
            <label
                class="bank-choice flex cursor-pointer items-start gap-2 rounded-field border bg-card px-3 py-2.5 transition"
                :class="
                    modelValue === 'cash'
                        ? 'border-brand ring-1 ring-brand/20'
                        : 'border-line hover:border-ink/30'
                "
            >
                <input
                    type="radio"
                    name="fee_payment_method"
                    value="cash"
                    :checked="modelValue === 'cash'"
                    class="mt-0.5 accent-brand"
                    @change="setMethod('cash')"
                />
                <span>
                    <span class="block text-sm font-bold">{{
                        t('transaction.feePaymentCash')
                    }}</span>
                    <span class="block text-[11px] text-slate">{{
                        t('transaction.feePaymentCashHint')
                    }}</span>
                </span>
            </label>

            <label
                class="bank-choice flex cursor-pointer items-start gap-2 rounded-field border bg-card px-3 py-2.5 transition"
                :class="
                    modelValue === 'account'
                        ? 'border-brand ring-1 ring-brand/20'
                        : 'border-line hover:border-ink/30'
                "
            >
                <input
                    type="radio"
                    name="fee_payment_method"
                    value="account"
                    :checked="modelValue === 'account'"
                    class="mt-0.5 accent-brand"
                    @change="setMethod('account')"
                />
                <span>
                    <span class="block text-sm font-bold">{{
                        t('transaction.feePaymentAccount')
                    }}</span>
                    <span class="block text-[11px] text-slate">{{
                        accountIncludedInTransaction
                            ? t('transaction.feePaymentAccountIncludedHint')
                            : t('transaction.feePaymentAccountHint')
                    }}</span>
                </span>
            </label>
        </div>

        <div
            v-if="modelValue === 'account' && !accountIncludedInTransaction"
            class="mt-3"
        >
            <label
                class="mb-1.5 block text-[12px] font-bold text-slate"
                for="fee-account-select"
            >
                {{ t('transaction.feeAccount') }}
            </label>
            <select
                id="fee-account-select"
                class="bank-input py-2.5"
                :value="feeAccountId ?? ''"
                :aria-invalid="feeAccounts.length > 0 && feeAccountId === null"
                @change="setAccount"
            >
                <option value="" disabled>
                    {{ t('transaction.chooseFeeAccount') }}
                </option>
                <option
                    v-for="account in feeAccounts"
                    :key="account.id"
                    :value="account.id"
                >
                    {{ account.name }} — {{ account.company }}
                </option>
            </select>
            <p
                v-if="!feeAccounts.length"
                class="mt-1.5 text-xs font-semibold text-brand"
            >
                {{ t('transaction.noFeeAccounts') }}
            </p>
            <p
                v-else-if="feeAccountId === null"
                class="mt-1.5 text-xs font-semibold text-brand"
            >
                {{ t('transaction.feeAccountRequired') }}
            </p>
        </div>

        <p class="mt-2 text-[11px] text-slate">
            {{ t('transaction.feeAmount') }}:
            <span class="money font-bold text-ink"
                >{{ fee.toLocaleString() }} MMK</span
            >
        </p>
    </fieldset>
</template>
