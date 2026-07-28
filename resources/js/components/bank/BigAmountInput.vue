<script setup lang="ts">
import { computed } from 'vue';
import { useLocale } from '@/lib/i18n';

const props = withDefaults(
    defineProps<{
        modelValue: number;
        label?: string;
        id?: string;
        currency?: string;
        currencyClass?: string;
        readingCurrencyLabel?: string;
        chips?: number[];
    }>(),
    {
        id: 'transaction-amount',
        currency: 'MMK',
        currencyClass: 'font-bold text-slate',
        readingCurrencyLabel: 'ကျပ်',
        chips: () => [10_000, 50_000, 100_000, 500_000],
    },
);

const emit = defineEmits<{ 'update:modelValue': [number] }>();
const { t } = useLocale();

const UNITS: [number, string][] = [
    [10_000_000, 'ကုဋေ'],
    [100_000, 'သိန်း'],
    [10_000, 'သောင်း'],
    [1_000, 'ထောင်'],
    [100, 'ရာ'],
];

const reading = computed(() => {
    let amount = Math.floor(props.modelValue || 0);

    if (amount <= 0) {
        return '';
    }

    const parts: string[] = [];

    for (const [unit, name] of UNITS) {
        const quantity = Math.floor(amount / unit);

        if (quantity > 0) {
            parts.push(`${quantity.toLocaleString()} ${name}`);
            amount -= quantity * unit;
        }
    }

    if (amount > 0) {
        parts.push(String(amount));
    }

    return parts.join(' ');
});

function set(value: number): void {
    emit('update:modelValue', Math.max(0, Math.floor(value || 0)));
}
</script>

<template>
    <div>
        <label class="bank-label bank-required" :for="id">{{
            label ?? t('component.enterAmount')
        }}</label>

        <div
            class="flex items-center gap-3 rounded-field bg-mist px-5 py-4 transition focus-within:bg-white focus-within:ring-2 focus-within:ring-brand/40"
        >
            <input
                :id="id"
                :value="modelValue || ''"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                enterkeyhint="next"
                placeholder="0"
                :aria-label="label ?? t('component.enterAmount')"
                :aria-describedby="`${id}-reading`"
                class="money min-w-0 flex-1 bg-transparent text-3xl font-bold text-ink outline-none placeholder:text-slate/40"
                @input="
                    set(
                        Number(
                            ($event.target as HTMLInputElement).value.replace(
                                /[^\d]/g,
                                '',
                            ),
                        ),
                    )
                "
            />
            <span class="shrink-0 text-sm" :class="currencyClass">{{
                currency
            }}</span>
            <button
                v-if="modelValue"
                type="button"
                :aria-label="t('common.clear')"
                @click="set(0)"
                class="bank-button grid size-8 shrink-0 place-items-center rounded-full bg-card p-0 text-xs text-slate shadow-sm hover:text-ink"
            >
                ✕
            </button>
        </div>

        <p
            :id="`${id}-reading`"
            aria-live="polite"
            class="mt-1.5 min-h-5 text-[13px] font-semibold"
            :class="modelValue > 0 ? 'text-ink' : 'text-transparent'"
        >
            {{ reading || '—' }}
            <span v-if="modelValue > 0" class="font-medium text-slate">{{
                readingCurrencyLabel
            }}</span>
        </p>

        <div class="flex flex-wrap gap-1.5">
            <button
                v-for="chip in chips"
                :key="chip"
                type="button"
                @click="set((modelValue || 0) + chip)"
                :aria-label="`+${chip.toLocaleString()} ${currency}`"
                class="bank-button money min-h-9 rounded-pill bg-mist px-3.5 py-1.5 text-xs font-bold text-slate hover:bg-line hover:text-ink"
            >
                +{{ chip.toLocaleString() }}
            </button>
        </div>
    </div>
</template>
