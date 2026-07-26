<script setup lang="ts">
import { computed } from 'vue';
import { useLocale } from '@/lib/i18n';

/**
 * Denomination drawer, banking skin. Same rules as the teller version:
 * tap a note row to +1, steppers for corrections, stock caps, and a footer
 * that stays red until Counted equals Required. Employees cannot submit a
 * cash movement while this is out of balance.
 */
const props = withDefaults(
    defineProps<{
        notes: number[];
        modelValue: Record<number, number>;
        target?: number | null;
        stock?: Record<number, number> | null;
        expected?: Record<number, number> | null;
        label?: string;
        readonly?: boolean;
        compact?: boolean;
        idPrefix?: string;
        showTitle?: boolean;
        enforceStock?: boolean;
    }>(),
    {
        target: null,
        stock: null,
        expected: null,
        readonly: false,
        compact: false,
        idPrefix: 'denomination',
        showTitle: true,
        enforceStock: true,
    },
);

const emit = defineEmits<{ 'update:modelValue': [Record<number, number>] }>();
const { t } = useLocale();

const qty = (n: number) => Number(props.modelValue?.[n] ?? 0);
const lineTotal = (n: number) => n * qty(n);
const total = computed(() => props.notes.reduce((s, n) => s + lineTotal(n), 0));
const difference = computed(() =>
    props.target === null ? 0 : total.value - (props.target ?? 0),
);
const balanced = computed(
    () => props.target === null || difference.value === 0,
);
const mismatch = (n: number) =>
    props.expected !== null && qty(n) !== Number(props.expected?.[n] ?? 0);

function set(n: number, next: number) {
    if (props.readonly) {
        return;
    }

    const cap = props.enforceStock ? (props.stock?.[n] ?? Infinity) : Infinity;
    emit('update:modelValue', {
        ...props.modelValue,
        [n]: Math.max(0, Math.min(Math.floor(next || 0), cap)),
    });
}

function autoFill() {
    if (props.target === null || props.readonly) {
        return;
    }

    let left = props.target;
    const next: Record<number, number> = {};

    for (const n of [...props.notes].sort((a, b) => b - a)) {
        const stockCap = props.enforceStock
            ? (props.stock?.[n] ?? Infinity)
            : Infinity;
        const take = Math.min(Math.floor(left / n), stockCap);
        next[n] = take;
        left -= take * n;
    }

    emit('update:modelValue', next);
}
</script>

<template>
    <section
        class="overflow-hidden rounded-2xl border border-line bg-card"
        :aria-label="label ?? t('component.notesCounted')"
    >
        <header
            v-if="showTitle || !readonly"
            class="flex items-center justify-between border-b border-line px-5 py-3.5"
        >
            <h3 v-if="showTitle" class="text-sm font-bold">
                {{ label ?? t('component.notesCounted') }}
            </h3>
            <div v-if="!readonly" class="flex gap-1.5">
                <button
                    v-if="target !== null"
                    type="button"
                    @click="autoFill"
                    class="bank-button min-h-9 rounded-pill bg-mist px-3.5 py-1.5 text-xs font-bold text-slate hover:text-ink"
                >
                    {{ t('component.fillLargest') }}
                </button>
                <button
                    type="button"
                    @click="emit('update:modelValue', {})"
                    class="bank-button min-h-9 rounded-pill px-3 py-1.5 text-xs font-bold text-slate hover:text-brand"
                >
                    {{ t('common.clear') }}
                </button>
            </div>
        </header>

        <ul
            :class="
                compact ? 'grid grid-cols-1 gap-2 p-3' : 'divide-y divide-line'
            "
        >
            <li
                v-for="n in notes"
                :key="n"
                class="flex items-center gap-3 transition"
                :class="[
                    qty(n) > 0 ? 'bg-mist/50' : '',
                    mismatch(n) ? 'bg-brand-soft' : '',
                    !readonly
                        ? 'cursor-pointer select-none active:bg-mist'
                        : '',
                    compact
                        ? 'grid grid-cols-[minmax(0,1fr)_auto] gap-2 rounded-field border border-line px-3 py-2'
                        : 'px-5 py-2.5',
                ]"
                @click="!readonly && set(n, qty(n) + 1)"
            >
                <div v-if="compact" class="min-w-0">
                    <div class="flex min-w-0 items-center gap-2">
                        <span
                            class="money shrink-0 text-sm font-bold text-ink"
                            >{{ n.toLocaleString() }}</span
                        >
                        <span
                            v-if="stock"
                            class="truncate text-[11px] text-slate"
                            >{{ stock[n] ?? 0 }}
                            {{ t('component.onHand') }}</span
                        >
                        <span
                            v-else-if="expected"
                            class="truncate text-[11px] text-slate"
                            >{{ expected[n] ?? 0 }}
                            {{ t('component.issued') }}</span
                        >
                    </div>
                    <span
                        class="money mt-1 block text-[11px] font-semibold"
                        :class="qty(n) > 0 ? 'text-ink' : 'text-slate/45'"
                    >
                        {{ lineTotal(n).toLocaleString() }} MMK
                    </span>
                </div>
                <template v-else>
                    <span class="money w-20 shrink-0 text-sm font-bold">{{
                        n.toLocaleString()
                    }}</span>
                    <span
                        v-if="stock"
                        class="hidden shrink-0 text-[11px] text-slate sm:block"
                        >{{ stock[n] ?? 0 }} {{ t('component.onHand') }}</span
                    >
                    <span
                        v-else-if="expected"
                        class="hidden shrink-0 text-[11px] text-slate sm:block"
                        >{{ expected[n] ?? 0 }}
                        {{ t('component.issued') }}</span
                    >
                </template>

                <div
                    class="ml-auto flex shrink-0 items-center gap-1"
                    @click.stop
                >
                    <button
                        type="button"
                        :aria-label="`− ${n.toLocaleString()}`"
                        :disabled="readonly || qty(n) === 0"
                        @click="set(n, qty(n) - 1)"
                        class="bank-button grid size-8 min-h-8 place-items-center rounded-full bg-mist p-0 text-slate hover:text-ink disabled:opacity-30"
                    >
                        −
                    </button>
                    <input
                        :id="`${props.idPrefix}-${n}`"
                        :value="qty(n)"
                        :readonly="readonly"
                        inputmode="numeric"
                        autocomplete="off"
                        :aria-label="`${n.toLocaleString()} ${t('component.notesCounted')}`"
                        :aria-invalid="mismatch(n)"
                        @input="
                            set(
                                n,
                                Number(
                                    ($event.target as HTMLInputElement).value,
                                ),
                            )
                        "
                        class="money h-9 w-12 rounded-field border border-line bg-mist px-1 text-center text-sm font-bold text-ink outline-none focus:border-brand focus:bg-card focus:ring-2 focus:ring-brand/25 sm:w-14"
                        :class="
                            mismatch(n) ? 'text-brand ring-2 ring-brand' : ''
                        "
                    />
                    <button
                        type="button"
                        :aria-label="`+ ${n.toLocaleString()}`"
                        :disabled="
                            readonly ||
                            (enforceStock && qty(n) >= (stock?.[n] ?? Infinity))
                        "
                        @click="set(n, qty(n) + 1)"
                        class="bank-button grid size-8 min-h-8 place-items-center rounded-full bg-mist p-0 text-slate hover:text-ink disabled:opacity-30"
                    >
                        +
                    </button>
                </div>

                <span
                    v-if="!compact"
                    class="money w-28 shrink-0 text-right text-sm font-bold"
                    :class="qty(n) > 0 ? 'text-ink' : 'text-slate/40'"
                    >{{ lineTotal(n).toLocaleString() }}</span
                >
            </li>
        </ul>

        <footer
            class="flex flex-wrap items-center justify-between gap-3 border-t px-5 py-3.5"
            :class="
                target === null
                    ? 'border-line bg-mist/40'
                    : balanced
                      ? 'border-balance/30 bg-balance/5'
                      : 'border-brand/30 bg-brand-soft'
            "
        >
            <div>
                <p
                    class="text-[11px] font-bold tracking-wide text-slate uppercase"
                >
                    {{ t('component.counted') }}
                </p>
                <p class="money text-lg font-bold">
                    {{ total.toLocaleString() }}
                    <span class="text-[10px] text-slate">MMK</span>
                </p>
            </div>
            <template v-if="target !== null">
                <div>
                    <p
                        class="text-[11px] font-bold tracking-wide text-slate uppercase"
                    >
                        {{ t('component.required') }}
                    </p>
                    <p class="money text-lg font-bold text-slate">
                        {{ (target ?? 0).toLocaleString() }}
                    </p>
                </div>
                <div class="text-right">
                    <p
                        class="text-[11px] font-bold tracking-wide uppercase"
                        :class="balanced ? 'text-balance' : 'text-brand'"
                    >
                        {{
                            balanced
                                ? t('component.balanced')
                                : difference > 0
                                  ? t('component.overBy')
                                  : t('component.shortBy')
                        }}
                    </p>
                    <p
                        v-if="!balanced"
                        class="money text-lg font-bold text-brand"
                    >
                        {{ Math.abs(difference).toLocaleString() }}
                    </p>
                </div>
            </template>
        </footer>
    </section>
</template>
