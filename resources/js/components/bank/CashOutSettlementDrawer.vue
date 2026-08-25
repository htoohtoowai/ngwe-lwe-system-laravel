<script setup lang="ts">
import { computed } from 'vue';
import { useLocale } from '@/lib/i18n';

const props = defineProps<{
    notes: number[];
    stock: Record<number, number>;
    payout: Record<number, number>;
    feeReceived: Record<number, number>;
    change: Record<number, number>;
    payoutTarget: number;
    feeDue: number;
    cashFee: boolean;
}>();

const emit = defineEmits<{
    'update:payout': [Record<number, number>];
    'update:feeReceived': [Record<number, number>];
    'update:change': [Record<number, number>];
}>();

const { t } = useLocale();

const payoutQty = (note: number) => Number(props.payout[note] ?? 0);
const feeQty = (note: number) => Number(props.feeReceived[note] ?? 0);
const changeQty = (note: number) => Number(props.change[note] ?? 0);
const onHandQty = (note: number) => Number(props.stock[note] ?? 0);
const availableForChange = (note: number) =>
    Math.max(0, onHandQty(note) - payoutQty(note) + feeQty(note));
const projectedQty = (note: number) =>
    onHandQty(note) - payoutQty(note) + feeQty(note) - changeQty(note);

const totalFor = (values: Record<number, number>) =>
    props.notes.reduce(
        (sum, note) => sum + note * Number(values[note] ?? 0),
        0,
    );

const payoutTotal = computed(() => totalFor(props.payout));
const feeReceivedTotal = computed(() =>
    props.cashFee ? totalFor(props.feeReceived) : 0,
);
const changeDue = computed(() =>
    props.cashFee ? Math.max(0, feeReceivedTotal.value - props.feeDue) : 0,
);
const changeTotal = computed(() =>
    props.cashFee ? totalFor(props.change) : 0,
);
const netTellerMovement = computed(
    () => -payoutTotal.value + feeReceivedTotal.value - changeTotal.value,
);
const payoutMatched = computed(() => payoutTotal.value === props.payoutTarget);
const feeMatched = computed(
    () => !props.cashFee || feeReceivedTotal.value >= props.feeDue,
);
const changeMatched = computed(
    () => !props.cashFee || changeTotal.value === changeDue.value,
);
const projectedValid = computed(() =>
    props.notes.every((note) => projectedQty(note) >= 0),
);
const allMatched = computed(
    () =>
        payoutMatched.value &&
        feeMatched.value &&
        changeMatched.value &&
        projectedValid.value,
);

function clamp(value: number, max = Infinity): number {
    return Math.max(0, Math.min(Math.floor(Number(value) || 0), max));
}

function correctedChange(
    nextPayout: Record<number, number>,
    nextFee: Record<number, number>,
): Record<number, number> {
    const next = { ...props.change };

    for (const note of props.notes) {
        const available = Math.max(
            0,
            onHandQty(note) -
                Number(nextPayout[note] ?? 0) +
                Number(nextFee[note] ?? 0),
        );
        next[note] = clamp(Number(next[note] ?? 0), available);
    }

    return next;
}

function setPayout(note: number, value: number): void {
    const nextPayout = {
        ...props.payout,
        [note]: clamp(value, onHandQty(note)),
    };
    emit('update:payout', nextPayout);
    emit('update:change', correctedChange(nextPayout, props.feeReceived));
}

function setFee(note: number, value: number): void {
    const nextFee = {
        ...props.feeReceived,
        [note]: clamp(value),
    };
    emit('update:feeReceived', nextFee);
    emit('update:change', correctedChange(props.payout, nextFee));
}

function setChange(note: number, value: number): void {
    emit('update:change', {
        ...props.change,
        [note]: clamp(value, availableForChange(note)),
    });
}

function setFromInput(
    type: 'payout' | 'fee' | 'change',
    note: number,
    event: Event,
): void {
    const input = event.target as HTMLInputElement;
    const value = Number(input.value);

    if (type === 'payout') {
        setPayout(note, value);
    } else if (type === 'fee') {
        setFee(note, value);
    } else {
        setChange(note, value);
    }
}

function fillPayout(): void {
    let remaining = props.payoutTarget;
    const next: Record<number, number> = {};

    for (const note of [...props.notes].sort((a, b) => b - a)) {
        const quantity = Math.min(
            Math.floor(remaining / note),
            onHandQty(note),
        );
        next[note] = quantity;
        remaining -= quantity * note;
    }

    emit('update:payout', next);
    emit('update:change', correctedChange(next, props.feeReceived));
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
    emit('update:payout', {});
    emit('update:feeReceived', {});
    emit('update:change', {});
}
</script>

<template>
    <section
        class="overflow-hidden rounded-2xl border border-line bg-card"
        aria-label="Cash Out cash settlement"
    >
        <header
            class="flex flex-wrap items-start justify-between gap-3 border-b border-line bg-mist/45 px-3 py-3.5 sm:px-4"
        >
            <div>
                <h3 class="text-sm font-black text-ink">
                    {{ t('transaction.cashSettlement', 'Cash settlement') }}
                </h3>
                <p class="mt-0.5 text-xs font-semibold text-slate">
                    {{
                        t(
                            'transaction.cashSettlementHint',
                            'Use one sheet for payout, cash fee received and any change returned.',
                        )
                    }}
                </p>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button
                    type="button"
                    class="bank-button min-h-9 rounded-pill bg-card px-3 py-1.5 text-xs font-bold text-slate"
                    @click="fillPayout"
                >
                    {{ t('transaction.fillPayout', 'Fill payout') }}
                </button>
                <button
                    v-if="cashFee && changeDue > 0"
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

        <div class="overflow-x-auto">
            <table
                class="w-full border-collapse text-left"
                :class="cashFee ? 'min-w-[900px]' : 'min-w-[560px]'"
            >
                <thead
                    class="bg-card text-[10px] font-black tracking-wide text-slate uppercase"
                >
                    <tr class="border-b border-line">
                        <th class="sticky left-0 z-10 bg-card px-3 py-2.5">
                            {{ t('component.denomination', 'Denomination') }}
                        </th>
                        <th class="px-2 py-2.5 text-center">
                            {{ t('component.onHand', 'On hand') }}
                        </th>
                        <th class="px-2 py-2.5 text-center text-debit">
                            {{
                                t('transaction.customerPayoutShort', 'Payout −')
                            }}
                        </th>
                        <th
                            v-if="cashFee"
                            class="px-2 py-2.5 text-center text-credit"
                        >
                            {{ t('transaction.feeReceivedShort', 'Fee +') }}
                        </th>
                        <th
                            v-if="cashFee"
                            class="px-2 py-2.5 text-center text-debit"
                        >
                            {{ t('transaction.changeShort', 'Change −') }}
                        </th>
                        <th class="px-3 py-2.5 text-right">
                            {{ t('transaction.projected', 'Projected') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr
                        v-for="note in notes"
                        :key="note"
                        :class="projectedQty(note) < 0 ? 'bg-brand-soft' : ''"
                    >
                        <td
                            class="money sticky left-0 z-10 bg-card px-3 py-2.5 text-sm font-black text-ink"
                        >
                            {{ note.toLocaleString() }}
                        </td>
                        <td
                            class="money px-2 py-2.5 text-center text-sm font-bold text-slate"
                        >
                            {{ onHandQty(note).toLocaleString() }}
                        </td>
                        <td class="bg-debit/5 px-2 py-2">
                            <div class="mx-auto flex w-fit items-center gap-1">
                                <button
                                    type="button"
                                    class="bank-button grid size-8 min-h-8 place-items-center rounded-full bg-mist p-0 text-debit disabled:opacity-30"
                                    :disabled="payoutQty(note) === 0"
                                    @click="
                                        setPayout(note, payoutQty(note) - 1)
                                    "
                                >
                                    −
                                </button>
                                <input
                                    :value="payoutQty(note)"
                                    :max="onHandQty(note)"
                                    min="0"
                                    inputmode="numeric"
                                    class="money h-8 w-12 rounded-field border border-line bg-mist px-1 text-center text-xs font-black text-debit outline-none focus:border-debit focus:bg-card focus:ring-2 focus:ring-debit/20"
                                    :aria-label="`${note.toLocaleString()} payout notes`"
                                    @input="
                                        setFromInput('payout', note, $event)
                                    "
                                />
                                <button
                                    type="button"
                                    class="bank-button grid size-8 min-h-8 place-items-center rounded-full bg-mist p-0 text-debit disabled:opacity-30"
                                    :disabled="
                                        payoutQty(note) >= onHandQty(note)
                                    "
                                    @click="
                                        setPayout(note, payoutQty(note) + 1)
                                    "
                                >
                                    +
                                </button>
                            </div>
                        </td>
                        <td v-if="cashFee" class="bg-credit/5 px-2 py-2">
                            <div class="mx-auto flex w-fit items-center gap-1">
                                <button
                                    type="button"
                                    class="bank-button grid size-8 min-h-8 place-items-center rounded-full bg-credit/10 p-0 text-credit disabled:opacity-30"
                                    :disabled="feeQty(note) === 0"
                                    @click="setFee(note, feeQty(note) - 1)"
                                >
                                    −
                                </button>
                                <input
                                    :value="feeQty(note)"
                                    min="0"
                                    inputmode="numeric"
                                    class="money h-8 w-12 rounded-field border border-credit/25 bg-credit/5 px-1 text-center text-xs font-black text-credit outline-none focus:border-credit focus:bg-card focus:ring-2 focus:ring-credit/20"
                                    :aria-label="`${note.toLocaleString()} fee received notes`"
                                    @input="setFromInput('fee', note, $event)"
                                />
                                <button
                                    type="button"
                                    class="bank-button grid size-8 min-h-8 place-items-center rounded-full bg-credit/10 p-0 text-credit"
                                    @click="setFee(note, feeQty(note) + 1)"
                                >
                                    +
                                </button>
                            </div>
                        </td>
                        <td v-if="cashFee" class="bg-debit/5 px-2 py-2">
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
                                    class="money h-8 w-12 rounded-field border border-line bg-mist px-1 text-center text-xs font-black text-debit outline-none focus:border-debit focus:bg-card focus:ring-2 focus:ring-debit/20"
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
                        <td
                            class="money px-3 py-2.5 text-right text-sm font-black"
                            :class="
                                projectedQty(note) < 0
                                    ? 'text-debit'
                                    : 'text-ink'
                            "
                        >
                            {{ projectedQty(note).toLocaleString() }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <footer class="border-t border-line bg-mist/35 p-3 sm:p-4">
            <div
                class="grid gap-2 sm:grid-cols-2"
                :class="cashFee ? 'xl:grid-cols-4' : ''"
            >
                <div
                    class="rounded-field border px-3 py-2.5"
                    :class="
                        payoutMatched
                            ? 'border-balance/25 bg-balance/5'
                            : 'border-brand/25 bg-brand-soft'
                    "
                >
                    <p
                        class="text-[10px] font-black tracking-wide text-slate uppercase"
                    >
                        {{ t('transaction.customerPayout', 'Customer payout') }}
                    </p>
                    <p class="money mt-1 text-sm font-black">
                        {{ payoutTotal.toLocaleString() }} /
                        {{ payoutTarget.toLocaleString() }} MMK
                        <span
                            :class="
                                payoutMatched ? 'text-balance' : 'text-brand'
                            "
                            >{{ payoutMatched ? '✓' : '' }}</span
                        >
                    </p>
                </div>

                <template v-if="cashFee">
                    <div
                        class="rounded-field border px-3 py-2.5"
                        :class="
                            feeMatched
                                ? 'border-balance/25 bg-balance/5'
                                : 'border-brand/25 bg-brand-soft'
                        "
                    >
                        <p
                            class="text-[10px] font-black tracking-wide text-slate uppercase"
                        >
                            {{
                                t(
                                    'transaction.feeReceived',
                                    'Fee cash received',
                                )
                            }}
                        </p>
                        <p class="money mt-1 text-sm font-black">
                            {{ feeReceivedTotal.toLocaleString() }} / ≥
                            {{ feeDue.toLocaleString() }} MMK
                            <span
                                :class="
                                    feeMatched ? 'text-balance' : 'text-brand'
                                "
                                >{{ feeMatched ? '✓' : '' }}</span
                            >
                        </p>
                    </div>
                    <div
                        class="rounded-field border px-3 py-2.5"
                        :class="
                            changeMatched
                                ? 'border-balance/25 bg-balance/5'
                                : 'border-brand/25 bg-brand-soft'
                        "
                    >
                        <p
                            class="text-[10px] font-black tracking-wide text-slate uppercase"
                        >
                            {{
                                t(
                                    'transaction.changeToCustomer',
                                    'Change to customer',
                                )
                            }}
                        </p>
                        <p class="money mt-1 text-sm font-black">
                            {{ changeTotal.toLocaleString() }} /
                            {{ changeDue.toLocaleString() }} MMK
                            <span
                                :class="
                                    changeMatched
                                        ? 'text-balance'
                                        : 'text-brand'
                                "
                                >{{ changeMatched ? '✓' : '' }}</span
                            >
                        </p>
                    </div>
                </template>

                <div
                    class="rounded-field border px-3 py-2.5"
                    :class="
                        allMatched
                            ? 'border-balance/25 bg-balance/5'
                            : 'border-line bg-card'
                    "
                >
                    <p
                        class="text-[10px] font-black tracking-wide text-slate uppercase"
                    >
                        {{ t('transaction.netTellerCash', 'Net teller cash') }}
                    </p>
                    <p
                        class="money mt-1 text-sm font-black"
                        :class="
                            netTellerMovement < 0
                                ? 'text-brand'
                                : 'text-balance'
                        "
                    >
                        {{ netTellerMovement > 0 ? '+' : ''
                        }}{{ netTellerMovement.toLocaleString() }} MMK
                    </p>
                </div>
            </div>

            <p v-if="!projectedValid" class="mt-2 text-xs font-bold text-brand">
                {{
                    t(
                        'transaction.projectedStockError',
                        'A denomination would go below zero. Adjust payout or change notes.',
                    )
                }}
            </p>
            <p
                v-else-if="allMatched"
                class="mt-2 text-xs font-black text-balance"
            >
                ✓
                {{
                    t(
                        'transaction.cashSettlementMatched',
                        'Cash settlement matched',
                    )
                }}
            </p>
        </footer>
    </section>
</template>
