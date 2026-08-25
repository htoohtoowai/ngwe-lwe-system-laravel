<script setup lang="ts">
import { computed } from 'vue';
import { useLocale } from '@/lib/i18n';

const props = defineProps<{
    notes: number[];
    stock: Record<number, number>;
    received: Record<number, number>;
    change: Record<number, number>;
    amountDue: number;
}>();

const emit = defineEmits<{
    'update:received': [Record<number, number>];
    'update:change': [Record<number, number>];
}>();

const { t } = useLocale();

const receivedQty = (note: number) => Number(props.received[note] ?? 0);
const changeQty = (note: number) => Number(props.change[note] ?? 0);
const vaultQty = (note: number) => Number(props.stock[note] ?? 0);
const availableForChange = (note: number) =>
    Math.max(0, vaultQty(note) + receivedQty(note));

const totalFor = (values: Record<number, number>) =>
    props.notes.reduce(
        (sum, note) => sum + note * Number(values[note] ?? 0),
        0,
    );

const receivedTotal = computed(() => totalFor(props.received));
const changeDue = computed(() =>
    Math.max(0, receivedTotal.value - props.amountDue),
);
const changeTotal = computed(() => totalFor(props.change));
const netReceived = computed(() => receivedTotal.value - changeTotal.value);
const receivedEnough = computed(() => receivedTotal.value >= props.amountDue);
const changeMatched = computed(() => changeTotal.value === changeDue.value);
const stockValid = computed(() =>
    props.notes.every((note) => changeQty(note) <= availableForChange(note)),
);
const allMatched = computed(
    () => receivedEnough.value && changeMatched.value && stockValid.value,
);

function clamp(value: number, max = Infinity): number {
    return Math.max(0, Math.min(Math.floor(Number(value) || 0), max));
}

function correctedChange(
    nextReceived: Record<number, number>,
): Record<number, number> {
    const next = { ...props.change };

    for (const note of props.notes) {
        const available = Math.max(
            0,
            vaultQty(note) + Number(nextReceived[note] ?? 0),
        );
        next[note] = clamp(Number(next[note] ?? 0), available);
    }

    return next;
}

function setReceived(note: number, value: number): void {
    const next = {
        ...props.received,
        [note]: clamp(value),
    };
    emit('update:received', next);
    emit('update:change', correctedChange(next));
}

function setChange(note: number, value: number): void {
    emit('update:change', {
        ...props.change,
        [note]: clamp(value, availableForChange(note)),
    });
}

function setFromInput(
    type: 'received' | 'change',
    note: number,
    event: Event,
): void {
    const input = event.target as HTMLInputElement;
    const value = Number(input.value);

    if (type === 'received') {
        setReceived(note, value);
    } else {
        setChange(note, value);
    }
}

function fillExactDue(): void {
    let remaining = props.amountDue;
    const next: Record<number, number> = {};

    for (const note of [...props.notes].sort((a, b) => b - a)) {
        const quantity = Math.floor(remaining / note);
        next[note] = quantity;
        remaining -= quantity * note;
    }

    emit('update:received', next);
    emit('update:change', {});
}

function fillChange(): void {
    let remaining = changeDue.value;
    const next: Record<number, number> = {};

    for (const note of [...props.notes].sort((a, b) => b - a)) {
        const quantity = Math.min(
            Math.floor(remaining / note),
            availableForChange(note),
        );
        next[note] = quantity;
        remaining -= quantity * note;
    }

    emit('update:change', next);
}

function clearAll(): void {
    emit('update:received', {});
    emit('update:change', {});
}
</script>

<template>
    <section
        class="overflow-hidden rounded-2xl border border-line bg-card"
        aria-label="Cash In cash settlement"
    >
        <header
            class="flex flex-wrap items-start justify-between gap-3 border-b border-line bg-mist/45 px-3 py-3.5 sm:px-4"
        >
            <div>
                <h3 class="text-sm font-black text-ink">
                    {{
                        t(
                            'transaction.cashReceivedCustomer',
                            'Customer cash received',
                        )
                    }}
                </h3>
                <p class="mt-0.5 text-xs font-semibold text-slate">
                    {{
                        t(
                            'transaction.cashInCashierSettlementHint',
                            'Count all cash received together. If the customer overpays, select only the notes returned as change.',
                        )
                    }}
                </p>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button
                    type="button"
                    class="bank-button min-h-9 rounded-pill bg-card px-3 py-1.5 text-xs font-bold text-slate"
                    @click="fillExactDue"
                >
                    {{ t('transaction.fillExactDue', 'Fill exact due') }}
                </button>
                <button
                    v-if="changeDue > 0"
                    type="button"
                    class="bank-button min-h-9 rounded-pill bg-card px-3 py-1.5 text-xs font-bold text-slate"
                    @click="fillChange"
                >
                    {{ t('transaction.fillChange', 'Fill change') }}
                </button>
                <button
                    type="button"
                    class="bank-button min-h-9 rounded-pill px-3 py-1.5 text-xs font-bold text-brand"
                    @click="clearAll"
                >
                    {{ t('common.clear') }}
                </button>
            </div>
        </header>

        <!-- Mobile only (< 640px): cash-out-style stacked cards. -->
        <div class="divide-y divide-line sm:hidden">
            <article
                v-for="note in notes"
                :key="`cash-in-mobile-${note}`"
                class="bg-card px-3 py-3"
            >
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="money text-base font-black text-ink">
                            {{ note.toLocaleString() }}
                        </p>
                        <p
                            class="mt-0.5 text-[10px] font-black tracking-wide text-slate uppercase"
                        >
                            {{ t('component.denomination', 'Denomination') }}
                        </p>
                    </div>
                    <p
                        v-if="changeQty(note) > availableForChange(note)"
                        class="text-[10px] font-black text-debit"
                    >
                        {{
                            t(
                                'transaction.insufficientChangeNotes',
                                'Not enough change notes',
                            )
                        }}
                    </p>
                </div>

                <div class="mt-3 space-y-2">
                    <div
                        class="flex min-h-12 items-center justify-between gap-2 rounded-field bg-credit/5 px-2.5 py-2"
                    >
                        <span class="shrink-0 text-xs font-black text-credit">
                            {{ t('transaction.receivedShort', 'Received +') }}
                        </span>
                        <div class="flex items-center gap-1.5">
                            <button
                                type="button"
                                class="bank-button grid size-9 min-h-9 place-items-center rounded-full bg-card p-0 text-credit disabled:opacity-30"
                                :disabled="receivedQty(note) === 0"
                                @click="
                                    setReceived(note, receivedQty(note) - 1)
                                "
                            >
                                −
                            </button>
                            <input
                                :value="receivedQty(note)"
                                min="0"
                                inputmode="numeric"
                                class="bank-quantity-input money h-9 rounded-field border border-credit/25 bg-card px-2 text-center text-sm font-black text-credit outline-none focus:border-credit focus:ring-2 focus:ring-credit/20"
                                :aria-label="`${note.toLocaleString()} received notes`"
                                @input="setFromInput('received', note, $event)"
                            />
                            <button
                                type="button"
                                class="bank-button grid size-9 min-h-9 place-items-center rounded-full bg-card p-0 text-credit"
                                @click="
                                    setReceived(note, receivedQty(note) + 1)
                                "
                            >
                                +
                            </button>
                        </div>
                    </div>

                    <div
                        class="flex min-h-12 items-center justify-between gap-2 rounded-field bg-debit/5 px-2.5 py-2"
                    >
                        <span class="shrink-0 text-xs font-black text-debit">
                            {{ t('transaction.changeShort', 'Change −') }}
                        </span>
                        <div class="flex items-center gap-1.5">
                            <button
                                type="button"
                                class="bank-button grid size-9 min-h-9 place-items-center rounded-full bg-card p-0 text-debit disabled:opacity-30"
                                :disabled="changeQty(note) === 0"
                                @click="setChange(note, changeQty(note) - 1)"
                            >
                                −
                            </button>
                            <input
                                :value="changeQty(note)"
                                :max="availableForChange(note)"
                                min="0"
                                inputmode="numeric"
                                class="bank-quantity-input money h-9 rounded-field border border-debit/20 bg-card px-2 text-center text-sm font-black text-debit outline-none focus:border-debit focus:ring-2 focus:ring-debit/20"
                                :aria-label="`${note.toLocaleString()} change notes`"
                                @input="setFromInput('change', note, $event)"
                            />
                            <button
                                type="button"
                                class="bank-button grid size-9 min-h-9 place-items-center rounded-full bg-card p-0 text-debit disabled:opacity-30"
                                :disabled="
                                    changeQty(note) >= availableForChange(note)
                                "
                                @click="setChange(note, changeQty(note) + 1)"
                            >
                                +
                            </button>
                        </div>
                    </div>
                </div>
            </article>
        </div>

        <!-- Tablet/Desktop (>=640px): compact canonical table. -->
        <div class="hidden overflow-x-auto sm:block">
            <table class="w-full min-w-[620px] border-collapse text-left">
                <thead
                    class="bg-card text-[10px] font-black tracking-wide text-slate uppercase"
                >
                    <tr class="border-b border-line">
                        <th class="sticky left-0 z-10 bg-card px-3 py-2.5">
                            {{ t('component.denomination', 'Denomination') }}
                        </th>
                        <th class="px-2 py-2.5 text-center text-credit">
                            {{ t('transaction.receivedShort', 'Received +') }}
                        </th>
                        <th class="px-2 py-2.5 text-center text-debit">
                            {{ t('transaction.changeShort', 'Change −') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="note in notes" :key="note">
                        <td
                            class="money sticky left-0 z-10 bg-card px-3 py-2.5 text-sm font-black text-ink"
                        >
                            {{ note.toLocaleString() }}
                        </td>
                        <td class="bg-credit/5 px-2 py-2">
                            <div class="mx-auto flex w-fit items-center gap-1">
                                <button
                                    type="button"
                                    class="bank-button grid size-8 min-h-8 place-items-center rounded-full bg-credit/10 p-0 text-credit disabled:opacity-30"
                                    :disabled="receivedQty(note) === 0"
                                    @click="
                                        setReceived(note, receivedQty(note) - 1)
                                    "
                                >
                                    −
                                </button>
                                <input
                                    :value="receivedQty(note)"
                                    min="0"
                                    inputmode="numeric"
                                    class="bank-quantity-input money h-8 rounded-field border border-credit/25 bg-credit/5 px-2 text-center text-xs font-black text-credit outline-none focus:border-credit focus:bg-card focus:ring-2 focus:ring-credit/20"
                                    :aria-label="`${note.toLocaleString()} received notes`"
                                    @input="
                                        setFromInput('received', note, $event)
                                    "
                                />
                                <button
                                    type="button"
                                    class="bank-button grid size-8 min-h-8 place-items-center rounded-full bg-credit/10 p-0 text-credit"
                                    @click="
                                        setReceived(note, receivedQty(note) + 1)
                                    "
                                >
                                    +
                                </button>
                            </div>
                        </td>
                        <td class="bg-debit/5 px-2 py-2">
                            <div class="mx-auto flex w-fit items-center gap-1">
                                <button
                                    type="button"
                                    class="bank-button grid size-8 min-h-8 place-items-center rounded-full bg-mist p-0 text-debit disabled:opacity-30"
                                    :disabled="changeQty(note) === 0"
                                    @click="
                                        setChange(note, changeQty(note) - 1)
                                    "
                                >
                                    −
                                </button>
                                <input
                                    :value="changeQty(note)"
                                    :max="availableForChange(note)"
                                    min="0"
                                    inputmode="numeric"
                                    class="bank-quantity-input money h-8 rounded-field border border-line bg-mist px-2 text-center text-xs font-black text-debit outline-none focus:border-debit focus:bg-card focus:ring-2 focus:ring-debit/20"
                                    :aria-label="`${note.toLocaleString()} change notes`"
                                    @input="
                                        setFromInput('change', note, $event)
                                    "
                                />
                                <button
                                    type="button"
                                    class="bank-button grid size-8 min-h-8 place-items-center rounded-full bg-mist p-0 text-debit disabled:opacity-30"
                                    :disabled="
                                        changeQty(note) >=
                                        availableForChange(note)
                                    "
                                    @click="
                                        setChange(note, changeQty(note) + 1)
                                    "
                                >
                                    +
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <footer class="border-t border-line bg-mist/35 p-3 sm:p-4">
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    class="rounded-field border border-line bg-card px-3 py-2.5"
                >
                    <p
                        class="text-[10px] font-black tracking-wide text-slate uppercase"
                    >
                        {{ t('transaction.amountDue', 'Amount due') }}
                    </p>
                    <p class="money mt-1 text-sm font-black text-ink">
                        {{ amountDue.toLocaleString() }} MMK
                    </p>
                </div>
                <div
                    class="rounded-field border px-3 py-2.5"
                    :class="
                        receivedEnough
                            ? 'border-credit/25 bg-credit/5'
                            : 'border-debit/25 bg-debit/5'
                    "
                >
                    <p
                        class="text-[10px] font-black tracking-wide text-slate uppercase"
                    >
                        {{ t('transaction.customerPaid', 'Customer paid') }}
                    </p>
                    <p
                        class="money mt-1 text-sm font-black"
                        :class="receivedEnough ? 'text-credit' : 'text-debit'"
                    >
                        {{ receivedTotal.toLocaleString() }} MMK
                    </p>
                </div>
                <div
                    class="rounded-field border px-3 py-2.5"
                    :class="
                        changeMatched
                            ? 'border-balance/25 bg-balance/5'
                            : 'border-debit/25 bg-debit/5'
                    "
                >
                    <p
                        class="text-[10px] font-black tracking-wide text-slate uppercase"
                    >
                        {{ t('transaction.changeDue', 'Change due') }}
                    </p>
                    <p class="money mt-1 text-sm font-black">
                        {{ changeTotal.toLocaleString() }} /
                        {{ changeDue.toLocaleString() }} MMK
                    </p>
                </div>
                <div
                    class="rounded-field border px-3 py-2.5"
                    :class="
                        allMatched
                            ? 'border-balance/25 bg-balance/5'
                            : 'border-debit/25 bg-debit/5'
                    "
                >
                    <p
                        class="text-[10px] font-black tracking-wide text-slate uppercase"
                    >
                        {{ t('transaction.netCashReceived', 'Net received') }}
                    </p>
                    <p
                        class="money mt-1 text-sm font-black"
                        :class="allMatched ? 'text-balance' : 'text-debit'"
                    >
                        {{ netReceived.toLocaleString() }} /
                        {{ amountDue.toLocaleString() }} MMK
                        <span v-if="allMatched">✓</span>
                    </p>
                </div>
            </div>
        </footer>
    </section>
</template>
