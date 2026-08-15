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
        compact?: boolean;
    }>(),
    { disabled: false, accountIncludedInTransaction: false, compact: false },
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
        class="rounded-field"
        :class="compact ? '' : 'border border-line bg-mist/60 px-4 py-3.5'"
        :disabled="disabled"
    >
        <legend
            class="px-1 font-bold text-slate"
            :class="compact ? 'mb-2 text-xs' : 'text-[13px]'"
        >
            {{ t('transaction.feePaymentMethod') }}
        </legend>

        <div class="flex gap-5" :class="compact ? '' : 'grid sm:grid-cols-2'">
            <label
                class="bank-choice flex cursor-pointer items-center gap-2 transition"
                :class="[
                    compact
                        ? 'min-h-7 px-0 py-0'
                        : 'items-start rounded-field border bg-card py-2.5',
                    modelValue === 'cash'
                        ? compact
                            ? 'text-brand'
                            : 'border-brand ring-1 ring-brand/20'
                        : compact
                          ? 'text-slate'
                          : 'border-line hover:border-ink/30',
                ]"
            >
                <input
                    type="radio"
                    name="fee_payment_method"
                    value="cash"
                    :checked="modelValue === 'cash'"
                    class="sr-only"
                    @change="setMethod('cash')"
                />
                <span
                    class="grid size-4 shrink-0 place-items-center rounded-full border transition"
                    :class="
                        modelValue === 'cash'
                            ? 'border-brand'
                            : 'border-slate/50'
                    "
                    aria-hidden="true"
                >
                    <span
                        class="size-2 rounded-full transition"
                        :class="
                            modelValue === 'cash' ? 'bg-brand' : 'bg-transparent'
                        "
                    />
                </span>
                <span>
                    <span class="block text-sm font-bold leading-none">{{
                        t('transaction.feePaymentCash')
                    }}</span>
                    <span v-if="!compact" class="block text-[11px] text-slate">{{
                        t('transaction.feePaymentCashHint')
                    }}</span>
                </span>
            </label>

            <label
                class="bank-choice flex cursor-pointer items-center gap-2 transition"
                :class="[
                    compact
                        ? 'min-h-7 px-0 py-0'
                        : 'items-start rounded-field border bg-card py-2.5',
                    modelValue === 'account'
                        ? compact
                            ? 'text-brand'
                            : 'border-brand ring-1 ring-brand/20'
                        : compact
                          ? 'text-slate'
                          : 'border-line hover:border-ink/30',
                ]"
            >
                <input
                    type="radio"
                    name="fee_payment_method"
                    value="account"
                    :checked="modelValue === 'account'"
                    class="sr-only"
                    @change="setMethod('account')"
                />
                <span
                    class="grid size-4 shrink-0 place-items-center rounded-full border transition"
                    :class="
                        modelValue === 'account'
                            ? 'border-brand'
                            : 'border-slate/50'
                    "
                    aria-hidden="true"
                >
                    <span
                        class="size-2 rounded-full transition"
                        :class="
                            modelValue === 'account'
                                ? 'bg-brand'
                                : 'bg-transparent'
                        "
                    />
                </span>
                <span>
                    <span class="block text-sm font-bold leading-none">{{
                        t('transaction.feePaymentAccount')
                    }}</span>
                    <span v-if="!compact" class="block text-[11px] text-slate">{{
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
                class="bank-label bank-required"
                for="fee-account-select"
            >
                {{ t('transaction.feeAccount') }}
            </label>
            <select
                id="fee-account-select"
                class="bank-input min-h-12 border border-line bg-mist px-3 py-2 transition focus:border-brand focus:ring-2 focus:ring-brand/20"
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

        <p v-if="!compact" class="mt-2 text-[11px] text-slate">
            {{ t('transaction.feeAmount') }}:
            <span class="money font-bold text-ink"
                >{{ fee.toLocaleString() }} MMK</span
            >
        </p>
    </fieldset>
</template>
