<script setup lang="ts">
import { computed } from 'vue';
import { useLocale } from '@/lib/i18n';
import MoneyText from './MoneyText.vue';

const props = withDefaults(
    defineProps<{
        notes: number[];
        modelValue: Record<number, number>;
        target?: number | null;
        stock?: Record<number, number> | null;
        expected?: Record<number, number> | null;
        label?: string;
        readonly?: boolean;
    }>(),
    { target: null, stock: null, expected: null, readonly: false },
);

const emit = defineEmits<{ 'update:modelValue': [Record<number, number>] }>();
const { t } = useLocale();

const qty = (n: number) => Number(props.modelValue?.[n] ?? 0);
const lineTotal = (n: number) => n * qty(n);

const total = computed(() =>
    props.notes.reduce((sum, n) => sum + lineTotal(n), 0),
);
const difference = computed(() =>
    props.target === null ? 0 : total.value - (props.target ?? 0),
);
const balanced = computed(() =>
    props.target === null ? true : difference.value === 0,
);
const noteCount = computed(() => props.notes.reduce((c, n) => c + qty(n), 0));

function capFor(n: number): number {
    if (props.stock !== null) {
        return Number(props.stock[n] ?? 0);
    }

    if (props.expected !== null) {
        return Number(props.expected[n] ?? 0);
    }

    return Infinity;
}

function clampQuantity(n: number, next: number): number {
    const cap = capFor(n);

    return Math.max(0, Math.min(Math.floor(next || 0), cap));
}

function set(n: number, next: number) {
    if (props.readonly) {
        return;
    }

    const clamped = clampQuantity(n, next);
    emit('update:modelValue', { ...props.modelValue, [n]: clamped });
}

function setFromInput(n: number, event: Event) {
    const input = event.target as HTMLInputElement;
    const clamped = clampQuantity(n, Number(input.value));

    input.value = String(clamped);
    emit('update:modelValue', { ...props.modelValue, [n]: clamped });
}

function autoFill() {
    if (props.target === null || props.readonly) {
        return;
    }

    let left = props.target;
    const next: Record<number, number> = {};

    for (const n of [...props.notes].sort((a, b) => b - a)) {
        const cap = capFor(n);
        const take = Math.min(Math.floor(left / n), cap);
        next[n] = take;
        left -= take * n;
    }

    emit('update:modelValue', next);
}

function clear() {
    if (props.readonly) {
        return;
    }

    emit('update:modelValue', {});
}

const mismatch = (n: number) =>
    props.expected !== null && qty(n) !== Number(props.expected?.[n] ?? 0);
</script>

<template>
    <section
        class="rounded-counter border border-paper-edge bg-white"
        role="group"
        :aria-label="label ?? t('component.notesCounted')"
    >
        <header
            class="flex flex-wrap items-center justify-between gap-2 border-b border-paper-edge px-3 py-2.5 sm:px-4"
        >
            <div>
                <h3 class="field-label">
                    {{ label ?? t('component.notesCounted') }}
                </h3>
                <p class="mt-0.5 text-xs text-ink-700/60">
                    {{ noteCount }} {{ t('teller.notes') }}
                </p>
            </div>
            <div v-if="!readonly" class="flex gap-1.5">
                <button
                    v-if="target !== null"
                    type="button"
                    @click="autoFill"
                    class="bank-button bank-button-secondary min-h-9 rounded-counter px-2.5 py-1 text-xs"
                >
                    {{ t('component.fillLargest') }}
                </button>
                <button
                    type="button"
                    @click="clear"
                    class="bank-button bank-button-danger min-h-9 rounded-counter px-2.5 py-1 text-xs"
                >
                    {{ t('common.clear') }}
                </button>
            </div>
        </header>

        <ul class="divide-y divide-paper-edge">
            <li
                v-for="n in notes"
                :key="n"
                class="flex items-center gap-2 px-3 py-2.5 transition sm:gap-3 sm:px-4"
                :class="[
                    qty(n) > 0 ? 'bg-ink-100/40' : '',
                    mismatch(n) ? 'bg-debit/5' : '',
                    !readonly && capFor(n) > qty(n)
                        ? 'cursor-pointer select-none active:bg-ink-100'
                        : '',
                ]"
                :title="readonly ? undefined : t('component.tapAdd')"
                @click="!readonly && set(n, qty(n) + 1)"
            >
                <span
                    class="money w-16 shrink-0 text-sm font-semibold text-ink-900 tabular-nums sm:w-24"
                    >{{ n.toLocaleString() }}</span
                >

                <span
                    v-if="stock"
                    class="hidden w-24 shrink-0 text-[11px] text-ink-700/55 sm:block"
                >
                    {{ (stock[n] ?? 0).toLocaleString() }}
                    {{ t('component.onHand') }}
                </span>
                <span
                    v-else-if="expected"
                    class="hidden w-24 shrink-0 text-[11px] text-ink-700/55 sm:block"
                >
                    {{ (expected[n] ?? 0).toLocaleString() }}
                    {{ t('component.issued') }}
                </span>

                <div class="ml-auto flex items-center gap-1" @click.stop>
                    <button
                        type="button"
                        :disabled="readonly || qty(n) === 0"
                        @click="set(n, qty(n) - 1)"
                        class="bank-button bank-button-secondary grid size-10 min-h-10 place-items-center rounded-counter p-0 disabled:opacity-30"
                        :aria-label="`လျော့ရန် ${n.toLocaleString()}`"
                    >
                        -
                    </button>
                    <input
                        :id="`teller-denomination-${n}`"
                        :value="qty(n)"
                        :readonly="readonly"
                        :max="Number.isFinite(capFor(n)) ? capFor(n) : undefined"
                        min="0"
                        inputmode="numeric"
                        autocomplete="off"
                        :aria-label="`${n.toLocaleString()} denomination count`"
                        :aria-invalid="mismatch(n)"
                        @input="setFromInput(n, $event)"
                        class="field-input money h-10 w-14 rounded-counter px-1 py-1 text-center text-sm"
                        :class="mismatch(n) ? 'border-debit text-debit' : ''"
                    />
                    <button
                        type="button"
                        :disabled="readonly || qty(n) >= capFor(n)"
                        @click="set(n, qty(n) + 1)"
                        class="bank-button bank-button-secondary grid size-10 min-h-10 place-items-center rounded-counter p-0 disabled:opacity-30"
                        :aria-label="`တိုးရန် ${n.toLocaleString()}`"
                    >
                        +
                    </button>
                </div>

                <MoneyText
                    :value="lineTotal(n)"
                    class="w-20 shrink-0 text-right text-xs sm:w-32 sm:text-sm"
                    :class="qty(n) > 0 ? 'text-ink-900' : 'text-ink-300'"
                />
            </li>
        </ul>

        <footer
            class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 transition"
            :class="
                target === null
                    ? 'border-paper-edge bg-paper'
                    : balanced
                      ? 'border-credit/30 bg-credit/5'
                      : 'border-debit/30 bg-debit/5'
            "
        >
            <div>
                <p class="field-label">{{ t('component.counted') }}</p>
                <MoneyText :value="total" class="text-lg font-semibold" />
            </div>

            <template v-if="target !== null">
                <div>
                    <p class="field-label">{{ t('component.required') }}</p>
                    <MoneyText
                        :value="target"
                        class="text-lg font-semibold text-ink-700"
                    />
                </div>
                <div class="text-right">
                    <p class="field-label">
                        {{
                            balanced
                                ? t('component.balanced')
                                : difference > 0
                                  ? t('component.overBy')
                                  : t('component.shortBy')
                        }}
                    </p>
                    <MoneyText
                        v-if="!balanced"
                        :value="Math.abs(difference)"
                        class="text-lg font-semibold"
                        :class="difference > 0 ? 'text-held' : 'text-debit'"
                    />
                    <p v-else class="money text-lg font-semibold text-credit">
                        OK 0
                    </p>
                </div>
            </template>
        </footer>
    </section>
</template>
