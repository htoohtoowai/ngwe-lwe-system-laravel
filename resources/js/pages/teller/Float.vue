<script setup lang="ts">
import type { RequestPayload } from '@inertiajs/core';
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import DenominationDrawer from '@/components/teller/DenominationDrawer.vue';
import MoneyText from '@/components/teller/MoneyText.vue';
import PinSeal from '@/components/teller/PinSeal.vue';
import StateChip from '@/components/teller/StateChip.vue';
import BankLayout from '@/layouts/BankLayout.vue';
import {
    createNgweLweEcho,
    disconnectNgweLweEcho,
    subscribeToRoleChannel,
    subscribeToUserChannel,
    watchNgweLweEchoConnection,
} from '@/lib/echo';
import type { RealtimeHandlers } from '@/lib/echo';
import { useLocale } from '@/lib/i18n';
import { startSmartPolling } from '@/lib/smart-polling';

type TellerFloat = {
    id: number;
    status: string;
    current_balance: string | null;
    issued_amount?: string;
    total_amount?: string;
} | null;

type FloatDenomination = {
    denomination: number;
    quantity: number;
};

type FloatRow = {
    id: number;
    status: string;
    current_balance: string | null;
    issued_amount: string;
    total_amount: string;
    closing_total: string | null;
    issued_by_name: string | null;
    created_at: string | null;
    received_at: string | null;
    closed_at: string | null;
    note: string | null;
    denominations: FloatDenomination[];
};

type FloatIssueRow = {
    id: number;
    float_id: number;
    status: 'PENDING_RECEIPT' | 'RECEIVED' | 'REJECTED' | string;
    amount: string;
    issued_by_name: string | null;
    created_at: string | null;
    received_at: string | null;
    rejected_at: string | null;
    note: string | null;
    denominations: FloatDenomination[];
};

const props = withDefaults(
    defineProps<{
        view?: 'current' | 'receive' | 'return' | 'history';
        float: TellerFloat;
        floats: FloatRow[];
        floatIssues: FloatIssueRow[];
        notes: number[];
        issued: Record<number, number>;
        onHand: Record<number, number>;
    }>(),
    {
        view: 'current',
        float: null,
        floats: () => [],
        floatIssues: () => [],
    },
);

const returning = ref<Record<number, number>>({});
const reviewFloat = ref<FloatRow | null>(null);
const reviewIssue = ref<FloatIssueRow | null>(null);
const actionFloat = ref<FloatRow | null>(null);
const actionIssue = ref<FloatIssueRow | null>(null);
const pinOpen = ref(false);
const pinBusy = ref(false);
const pinError = ref<string | null>(null);
const intent = ref<
    'receive' | 'return' | 'reject' | 'receive-issue' | 'reject-issue'
>('receive');
const page = usePage<{
    auth?: {
        user?: {
            id: number;
        } | null;
    };
}>();
let unsubscribeTeller: (() => void) | null = null;
let unsubscribeUser: (() => void) | null = null;
let unwatchEchoConnection: (() => void) | null = null;
let stopRealtimeFallback: (() => void) | null = null;
let realtimeConnected = false;

const status = computed(() => props.float?.status ?? 'CLOSED');
const currentOnly = computed(() => props.view === 'current');
const receiveOnly = computed(() => props.view === 'receive');
const returnOnly = computed(() => props.view === 'return');
const historyOnly = computed(() => props.view === 'history');
const rows = computed(() => props.floats);
const historyPerPage = ref(25);
const historyPage = ref(1);
const historyPageCount = computed(() =>
    Math.max(1, Math.ceil(rows.value.length / historyPerPage.value)),
);
const paginatedRows = computed(() => {
    const start = (historyPage.value - 1) * historyPerPage.value;

    return rows.value.slice(start, start + historyPerPage.value);
});
const historyFirstRecord = computed(() =>
    rows.value.length ? (historyPage.value - 1) * historyPerPage.value + 1 : 0,
);
const historyLastRecord = computed(() =>
    Math.min(historyPage.value * historyPerPage.value, rows.value.length),
);

watch([historyPerPage, () => rows.value.length], () => {
    historyPage.value = Math.min(historyPage.value, historyPageCount.value);
});
const pendingAdditionalIssues = computed(() =>
    props.floatIssues.filter((issue) => issue.status === 'PENDING_RECEIPT'),
);
const issuedTotal = computed(() =>
    props.notes.reduce((s, n) => s + n * (props.issued[n] ?? 0), 0),
);
const returnTotal = computed(() =>
    props.notes.reduce((s, n) => s + n * (returning.value[n] ?? 0), 0),
);
const expectedReturn = computed(() =>
    Number(props.float?.current_balance ?? 0),
);
const returnStockMatches = computed(() =>
    props.notes.every(
        (n) => (returning.value[n] ?? 0) === (props.onHand[n] ?? 0),
    ),
);
const reviewIssued = computed(() =>
    denominationsToMap(
        reviewIssue.value?.denominations ??
            reviewFloat.value?.denominations ??
            [],
    ),
);
const reviewIssuedTotal = computed(() => denominationTotal(reviewIssued.value));
const { t } = useLocale();
const pageTitle = computed(() => {
    if (receiveOnly.value) {
        return t('teller.receiveFloatPage', 'Receive float');
    }

    if (returnOnly.value) {
        return t('teller.returnCashPage', 'Return cash');
    }

    if (historyOnly.value) {
        return t('teller.floatHistoryPage', 'Float history');
    }

    return t('teller.float');
});
const pageDescription = computed(() => {
    if (receiveOnly.value) {
        return t(
            'teller.receiveFloatDescription',
            'Review cashier-entered notes, then receive or reject with your PIN.',
        );
    }

    if (returnOnly.value) {
        return t(
            'teller.returnCashDescription',
            'Count the cash you are handing back to the cashier once, then confirm with your PIN.',
        );
    }

    if (historyOnly.value) {
        return t(
            'teller.floatHistoryDescription',
            'Review your float sessions and additional issues.',
        );
    }

    return t('teller.floatDescription');
});

const pinTitle = computed(() => {
    if (intent.value === 'receive' || intent.value === 'receive-issue') {
        return t('teller.confirmCount');
    }

    if (intent.value === 'return') {
        return t('teller.confirmReturn');
    }

    return t('teller.rejectFloatTitle', 'Reject float request');
});

const pinDetail = computed(() => {
    if (intent.value === 'receive' || intent.value === 'receive-issue') {
        return t('teller.pinCount');
    }

    if (intent.value === 'return') {
        return t('teller.pinReturn');
    }

    return t(
        'teller.pinReject',
        'Your PIN rejects this incoming float and returns it to the main vault.',
    );
});

const pinConfirmLabel = computed(() => {
    if (intent.value === 'receive' || intent.value === 'receive-issue') {
        return t('teller.receiveFloatPin');
    }

    if (intent.value === 'return') {
        return t('teller.confirmHandBackPin', 'Confirm hand back with PIN');
    }

    return t('teller.rejectFloatPin', 'Reject with PIN');
});

function open(kind: 'receive' | 'return') {
    intent.value = kind;
    actionFloat.value = null;
    pinError.value = null;
    pinOpen.value = true;
}

function closePin() {
    pinOpen.value = false;
    pinError.value = null;
    actionFloat.value = null;
    actionIssue.value = null;
}

function reviewIncoming(float: FloatRow) {
    reviewIssue.value = null;
    reviewFloat.value = float;
    pinError.value = null;
}

function closeReview() {
    reviewFloat.value = null;
    reviewIssue.value = null;
}

function receiveReviewed() {
    if (reviewIssue.value) {
        intent.value = 'receive-issue';
        actionIssue.value = reviewIssue.value;
        actionFloat.value = null;
        pinError.value = null;
        pinOpen.value = true;

        return;
    }

    if (!reviewFloat.value) {
        return;
    }

    intent.value = 'receive';
    actionFloat.value = reviewFloat.value;
    actionIssue.value = null;
    pinError.value = null;
    pinOpen.value = true;
}

function rejectIncoming(float: FloatRow) {
    intent.value = 'reject';
    actionFloat.value = float;
    pinError.value = null;
    pinOpen.value = true;
}

function rejectCurrentIncoming() {
    intent.value = 'reject';
    actionFloat.value = null;
    actionIssue.value = null;
    pinError.value = null;
    pinOpen.value = true;
}

function reviewAdditionalIssue(issue: FloatIssueRow) {
    reviewFloat.value = null;
    reviewIssue.value = issue;
    pinError.value = null;
}

function rejectAdditionalIssue(issue: FloatIssueRow) {
    intent.value = 'reject-issue';
    actionIssue.value = issue;
    actionFloat.value = null;
    pinError.value = null;
    pinOpen.value = true;
}

function denominationsToMap(
    denominations: FloatDenomination[],
): Record<number, number> {
    const result: Record<number, number> = {};

    for (const line of denominations) {
        result[Number(line.denomination)] = Number(line.quantity ?? 0);
    }

    return result;
}

function denominationTotal(denominations: Record<number, number>): number {
    return props.notes.reduce(
        (sum, note) => sum + note * Number(denominations[note] ?? 0),
        0,
    );
}

function rowBalance(float: FloatRow): string | number {
    if (float.status === 'CLOSED') {
        return float.closing_total ?? 0;
    }

    if (float.status === 'PENDING_RECEIPT') {
        return float.total_amount;
    }

    return float.current_balance ?? 0;
}

function rowDate(float: FloatRow): string {
    const value = float.closed_at ?? float.received_at ?? float.created_at;

    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

function issueDate(issue: FloatIssueRow): string {
    const value = issue.received_at ?? issue.rejected_at ?? issue.created_at;

    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

function statusText(value: string): string {
    const labels: Record<string, string> = {
        PENDING_RECEIPT: 'Pending receipt',
        ACTIVE: 'Active',
        PENDING_RECONCILIATION: 'With cashier',
        CLOSED: 'Closed',
        RECEIVED: 'Received',
        REJECTED: 'Rejected',
    };

    return labels[value] ?? value;
}

function firstError(error: unknown): string {
    const apiError = error as {
        message?: string;
        errors?: Record<string, string[]>;
    };
    const validation = apiError.errors
        ? Object.values(apiError.errors)[0]?.[0]
        : null;

    return validation ?? apiError.message ?? 'Request failed.';
}

function authHeaders(): Record<string, string> {
    return {};
}

const refreshFloatPage = () =>
    router.reload({
        only: ['float', 'floats', 'floatIssues', 'issued', 'onHand'],
        headers: authHeaders(),
    });

async function confirm(pin: string) {
    const isIssueAction =
        intent.value === 'receive-issue' || intent.value === 'reject-issue';
    const resourceId = isIssueAction
        ? actionIssue.value?.id
        : (actionFloat.value?.id ?? props.float?.id);

    if (!resourceId) {
        return;
    }

    pinBusy.value = true;
    pinError.value = null;

    let url: string;
    let data: RequestPayload;

    if (intent.value === 'receive-issue') {
        url = `/teller/floats/issues/${resourceId}/receive`;
        data = { pin };
    } else if (intent.value === 'reject-issue') {
        url = `/teller/floats/issues/${resourceId}/reject`;
        data = {
            pin,
            note: `Rejected additional float issue #${resourceId} by Teller.`,
        };
    } else if (intent.value === 'receive') {
        url = `/teller/floats/${resourceId}/activate`;
        data = { pin };
    } else if (intent.value === 'return') {
        url = `/teller/floats/${resourceId}/initiate-return`;
        data = { pin, return_denominations: returning.value };
    } else {
        url = `/teller/floats/${resourceId}/reject`;
        data = {
            pin,
            note: `Rejected by Teller from My Float page for float #${resourceId}.`,
        };
    }

    router.post(url, data, {
        preserveScroll: true,
        onSuccess: () => {
            pinOpen.value = false;
            actionFloat.value = null;
            actionIssue.value = null;
            closeReview();
        },
        onError: (errors) => {
            pinError.value = firstError({ errors });
        },
        onFinish: () => {
            pinBusy.value = false;
        },
    });
}

onMounted(() => {
    const echo = createNgweLweEcho();
    const handlers: RealtimeHandlers = {
        balance_update: refreshFloatPage,
        new_transaction: refreshFloatPage,
        float_update: refreshFloatPage,
        float_status_changed: refreshFloatPage,
        cash_in_confirmed: refreshFloatPage,
        cash_in_cancelled: refreshFloatPage,
    };

    if (echo) {
        unwatchEchoConnection = watchNgweLweEchoConnection(echo, (state) => {
            realtimeConnected = state === 'connected';
        });
        unsubscribeTeller = subscribeToRoleChannel(echo, 'teller', handlers);

        if (page.props.auth?.user?.id) {
            unsubscribeUser = subscribeToUserChannel(
                echo,
                page.props.auth.user.id,
                handlers,
            );
        }
    }

    stopRealtimeFallback = startSmartPolling({
        refresh: refreshFloatPage,
        shouldPoll: () => !realtimeConnected,
        activeIntervalMs: 5_000,
        hiddenIntervalMs: 60_000,
    });
});

onBeforeUnmount(() => {
    stopRealtimeFallback?.();
    unwatchEchoConnection?.();
    unsubscribeTeller?.();
    unsubscribeUser?.();
    disconnectNgweLweEcho();
});
</script>

<template>
    <BankLayout role="teller">
        <header class="mb-5 flex items-start justify-between gap-4">
            <div>
                <h1 class="font-display text-2xl font-semibold tracking-tight">
                    {{ pageTitle }}
                </h1>
                <p class="mt-1 max-w-2xl text-sm text-ink-700/70">
                    {{ pageDescription }}
                </p>
            </div>
            <StateChip :status="status" />
        </header>

        <!-- My Float: read-only operational snapshot. -->
        <template v-if="currentOnly">
            <div
                v-if="!float || status === 'CLOSED'"
                class="rounded-counter border border-dashed border-paper-edge bg-white px-6 py-16 text-center"
            >
                <p class="font-display text-lg font-semibold">
                    {{ t('teller.noFloat') }}
                </p>
                <p class="mx-auto mt-1.5 max-w-md text-sm text-ink-700/70">
                    {{ t('teller.askCashier') }}
                </p>
            </div>

            <div
                v-else-if="status === 'PENDING_RECEIPT'"
                class="grid gap-6 lg:grid-cols-[1fr_20rem]"
            >
                <DenominationDrawer
                    :model-value="issued"
                    :notes="notes"
                    :target="issuedTotal"
                    :readonly="true"
                    :label="t('teller.cashierIssuedNotes', 'Cashier issued notes')"
                />
                <aside
                    class="h-fit rounded-counter border border-held/30 bg-held/5 p-5"
                >
                    <h2 class="font-display text-base font-semibold text-held">
                        {{ t('teller.pendingReceiptTitle', 'Float waiting to be received') }}
                    </h2>
                    <p class="mt-2 text-sm leading-relaxed text-ink-700/70">
                        {{ t('teller.pendingReceipt') }}
                    </p>
                    <button
                        type="button"
                        class="bank-button mt-5 w-full rounded-counter bg-seal py-3 text-sm font-semibold text-ink-950"
                        @click="router.visit('/teller/float/receive')"
                    >
                        {{ t('teller.openReceiveFloat', 'Open Receive Float') }}
                    </button>
                </aside>
            </div>

            <div
                v-else-if="status === 'ACTIVE'"
                class="grid gap-6 lg:grid-cols-[1fr_20rem]"
            >
                <div class="space-y-4">
                    <div
                        v-if="pendingAdditionalIssues.length"
                        class="rounded-counter border border-held/30 bg-held/5 px-4 py-3 text-sm font-semibold text-held"
                    >
                        {{ pendingAdditionalIssues.length }}
                        {{
                            t(
                                'teller.pendingAdditionalFloatNotice',
                                'additional float issue(s) are waiting for your review.',
                            )
                        }}
                        <button
                            type="button"
                            class="ml-2 underline underline-offset-2"
                            @click="router.visit('/teller/float/receive')"
                        >
                            {{ t('teller.reviewNow', 'Review now') }}
                        </button>
                    </div>
                    <DenominationDrawer
                        :model-value="onHand"
                        :notes="notes"
                        :target="expectedReturn"
                        :readonly="true"
                        :label="t('teller.onHandBreakdown', 'Current note breakdown')"
                    />
                </div>

                <aside
                    class="h-fit rounded-counter border border-ink-800 bg-ink-900 p-5 text-ink-100 lg:sticky lg:top-24"
                >
                    <h2
                        class="font-display text-sm font-semibold tracking-[0.14em] text-ink-300 uppercase"
                    >
                        {{ t('teller.floatOnHand') }}
                    </h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-300">
                                {{ t('teller.onHandNow') }}
                            </dt>
                            <dd class="font-semibold">
                                <MoneyText :value="expectedReturn" />
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-300">
                                {{ t('teller.status') }}
                            </dt>
                            <dd>{{ statusText(status) }}</dd>
                        </div>
                    </dl>
                    <p class="mt-4 text-xs leading-relaxed text-ink-300">
                        {{
                            t(
                                'teller.myFloatReadOnlyHint',
                                'This page is read-only. Use Return Cash when you hand money back to the cashier.',
                            )
                        }}
                    </p>
                </aside>
            </div>

            <div
                v-else
                class="rounded-counter border border-paper-edge bg-white px-6 py-16 text-center"
            >
                <p class="font-display text-lg font-semibold">
                    {{ t('teller.waitingCashier') }}
                </p>
                <p class="mx-auto mt-1.5 max-w-md text-sm text-ink-700/70">
                    {{ t('teller.pendingReconciliation') }}
                </p>
            </div>
        </template>

        <!-- Receive Float: receiver reviews sender-entered denominations and confirms with PIN. -->
        <template v-else-if="receiveOnly">
            <div
                v-if="status === 'PENDING_RECEIPT'"
                class="grid gap-6 lg:grid-cols-[1fr_20rem]"
            >
                <div class="space-y-4">
                    <div
                        class="rounded-counter border border-held/30 bg-held/5 px-4 py-3 text-sm text-held"
                    >
                        {{ t('teller.countIssued') }}
                    </div>
                    <DenominationDrawer
                        :model-value="issued"
                        :notes="notes"
                        :target="issuedTotal"
                        :readonly="true"
                        :label="t('teller.cashierIssuedNotes', 'Cashier issued notes')"
                    />
                </div>

                <aside
                    class="h-fit rounded-counter border border-ink-800 bg-ink-900 p-5 text-ink-100 lg:sticky lg:top-24"
                >
                    <h2
                        class="font-display text-sm font-semibold tracking-[0.14em] text-ink-300 uppercase"
                    >
                        {{ t('teller.receipt') }}
                    </h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-ink-300">{{ t('teller.issued') }}</dt>
                            <dd><MoneyText :value="issuedTotal" /></dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs leading-relaxed text-ink-300">
                        {{ t('teller.countMatch') }}
                    </p>
                    <div class="mt-5 grid gap-2">
                        <button
                            type="button"
                            @click="open('receive')"
                            class="w-full rounded-counter bg-seal py-3 text-sm font-semibold text-ink-950 transition hover:brightness-110"
                        >
                            {{ t('teller.receiveFloatPin') }}
                        </button>
                        <button
                            type="button"
                            @click="rejectCurrentIncoming"
                            class="bank-button bank-button-danger w-full rounded-counter py-3 text-sm font-semibold"
                        >
                            {{ t('common.reject', 'Reject / mismatch') }}
                        </button>
                    </div>
                </aside>
            </div>

            <div
                v-else-if="pendingAdditionalIssues.length === 0"
                class="rounded-counter border border-dashed border-paper-edge bg-white px-6 py-14 text-center"
            >
                <p class="font-display text-lg font-semibold">
                    {{ t('teller.noPendingFloat', 'No float is waiting for receipt') }}
                </p>
                <p class="mx-auto mt-1.5 max-w-md text-sm text-ink-700/70">
                    {{
                        t(
                            'teller.noPendingFloatDescription',
                            'When the cashier issues an initial or additional float, it will appear here for review and PIN confirmation.',
                        )
                    }}
                </p>
            </div>

            <section
                v-if="pendingAdditionalIssues.length"
                class="mt-6 overflow-hidden rounded-counter border border-paper-edge bg-white"
            >
                <header
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-paper-edge px-4 py-3"
                >
                    <div>
                        <h2 class="font-display text-base font-semibold">
                            {{ t('teller.additionalFloatIssues', 'Additional float issues') }}
                        </h2>
                        <p class="mt-0.5 text-xs text-ink-700/60">
                            {{
                                t(
                                    'teller.additionalReceiveHint',
                                    'Review the cashier-entered breakdown. Do not re-enter note quantities.',
                                )
                            }}
                        </p>
                    </div>
                    <span
                        class="rounded-full bg-held/10 px-3 py-1 text-xs font-bold text-held"
                    >
                        {{ pendingAdditionalIssues.length }} pending
                    </span>
                </header>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[44rem] text-left text-sm">
                        <thead class="bg-paper text-xs text-ink-700/60">
                            <tr>
                                <th class="px-4 py-2 font-semibold">Issue</th>
                                <th class="px-4 py-2 font-semibold">Float</th>
                                <th class="px-4 py-2 text-right font-semibold">Amount</th>
                                <th class="px-4 py-2 font-semibold">Cashier</th>
                                <th class="px-4 py-2 font-semibold">Time</th>
                                <th class="px-4 py-2 text-right font-semibold">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-paper-edge">
                            <tr
                                v-for="issue in pendingAdditionalIssues"
                                :key="issue.id"
                                class="bg-held/5"
                            >
                                <td class="px-4 py-3 font-semibold">#{{ issue.id }}</td>
                                <td class="px-4 py-3">#{{ issue.float_id }}</td>
                                <td class="px-4 py-3 text-right font-semibold">
                                    <MoneyText :value="issue.amount" />
                                </td>
                                <td class="px-4 py-3 text-ink-700/70">
                                    {{ issue.issued_by_name ?? '-' }}
                                </td>
                                <td class="px-4 py-3 text-ink-700/70">
                                    {{ issueDate(issue) }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            class="bank-button rounded-counter bg-seal px-3 py-1.5 text-xs font-semibold text-ink-950"
                                            @click="reviewAdditionalIssue(issue)"
                                        >
                                            {{ t('teller.reviewReceive', 'Review & receive') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="bank-button bank-button-danger rounded-counter px-3 py-1.5 text-xs"
                                            @click="rejectAdditionalIssue(issue)"
                                        >
                                            {{ t('common.reject', 'Reject') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>

        <!-- Return Cash: teller enters the hand-back count once; cashier later reviews it read-only. -->
        <template v-else-if="returnOnly">
            <div
                v-if="status === 'ACTIVE'"
                class="grid gap-6 lg:grid-cols-[1fr_20rem]"
            >
                <div class="space-y-4">
                    <div
                        v-if="pendingAdditionalIssues.length"
                        class="rounded-counter border border-held/30 bg-held/5 px-4 py-3 text-sm font-semibold text-held"
                    >
                        {{
                            t(
                                'teller.receiveBeforeReturn',
                                'Receive or reject all pending additional float issues before returning cash.',
                            )
                        }}
                        <button
                            type="button"
                            class="ml-2 underline underline-offset-2"
                            @click="router.visit('/teller/float/receive')"
                        >
                            {{ t('teller.openReceiveFloat', 'Open Receive Float') }}
                        </button>
                    </div>
                    <DenominationDrawer
                        v-model="returning"
                        :notes="notes"
                        :target="expectedReturn"
                        :stock="onHand"
                        :expected="onHand"
                        :label="t('component.notesCounted')"
                    />
                </div>

                <aside
                    class="h-fit rounded-counter border border-ink-800 bg-ink-900 p-5 text-ink-100 lg:sticky lg:top-24"
                >
                    <h2
                        class="font-display text-sm font-semibold tracking-[0.14em] text-ink-300 uppercase"
                    >
                        {{ t('teller.return') }}
                    </h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-ink-300">{{ t('teller.systemOnHand') }}</dt>
                            <dd><MoneyText :value="expectedReturn" /></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-ink-300">{{ t('teller.youCounted') }}</dt>
                            <dd><MoneyText :value="returnTotal" /></dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs leading-relaxed text-ink-300">
                        {{ t('teller.returnCloses') }}
                    </p>
                    <p class="mt-2 text-xs leading-relaxed text-seal">
                        {{
                            t(
                                'teller.returnPinHint',
                                'Confirm with your PIN after handing the counted cash to the cashier.',
                            )
                        }}
                    </p>
                    <button
                        type="button"
                        :disabled="!returnStockMatches || pendingAdditionalIssues.length > 0"
                        @click="open('return')"
                        class="mt-5 w-full rounded-counter bg-seal py-3 text-sm font-semibold text-ink-950 transition hover:brightness-110 disabled:opacity-35"
                    >
                        {{ t('teller.confirmHandBackPin', 'Confirm hand back with PIN') }}
                    </button>
                </aside>
            </div>

            <div
                v-else-if="status === 'PENDING_RECEIPT'"
                class="rounded-counter border border-held/30 bg-held/5 px-6 py-14 text-center"
            >
                <p class="font-display text-lg font-semibold">
                    {{ t('teller.receiveBeforeReturnTitle', 'Receive your float first') }}
                </p>
                <p class="mx-auto mt-1.5 max-w-md text-sm text-ink-700/70">
                    {{ t('teller.pendingReceipt') }}
                </p>
                <button
                    type="button"
                    class="bank-button mt-5 rounded-counter bg-seal px-5 py-2.5 text-sm font-semibold text-ink-950"
                    @click="router.visit('/teller/float/receive')"
                >
                    {{ t('teller.openReceiveFloat', 'Open Receive Float') }}
                </button>
            </div>

            <div
                v-else-if="status === 'PENDING_RECONCILIATION'"
                class="rounded-counter border border-paper-edge bg-white px-6 py-16 text-center"
            >
                <p class="font-display text-lg font-semibold">
                    {{ t('teller.waitingCashier') }}
                </p>
                <p class="mx-auto mt-1.5 max-w-md text-sm text-ink-700/70">
                    {{ t('teller.pendingReconciliation') }}
                </p>
            </div>

            <div
                v-else
                class="rounded-counter border border-dashed border-paper-edge bg-white px-6 py-16 text-center"
            >
                <p class="font-display text-lg font-semibold">
                    {{ t('teller.noActiveFloat') }}
                </p>
                <p class="mx-auto mt-1.5 max-w-md text-sm text-ink-700/70">
                    {{ t('teller.askCashier') }}
                </p>
            </div>
        </template>

        <!-- Float History: read-only records only; no operational actions. -->
        <template v-else-if="historyOnly">
            <section
                v-if="floatIssues.length"
                class="mb-6 overflow-hidden rounded-counter border border-paper-edge bg-white"
            >
                <header class="border-b border-paper-edge px-4 py-3">
                    <h2 class="font-display text-base font-semibold">
                        {{ t('teller.additionalIssueHistory', 'Additional issue history') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-ink-700/60">
                        {{
                            t(
                                'teller.additionalIssueHistoryHint',
                                'Read-only record of additional float issues and their final status.',
                            )
                        }}
                    </p>
                </header>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[42rem] text-left text-sm">
                        <thead class="bg-paper text-xs text-ink-700/60">
                            <tr>
                                <th class="px-4 py-2 font-semibold">Issue</th>
                                <th class="px-4 py-2 font-semibold">Float</th>
                                <th class="px-4 py-2 font-semibold">Status</th>
                                <th class="px-4 py-2 text-right font-semibold">Amount</th>
                                <th class="px-4 py-2 font-semibold">Cashier</th>
                                <th class="px-4 py-2 font-semibold">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-paper-edge">
                            <tr v-for="issue in floatIssues" :key="issue.id">
                                <td class="px-4 py-3 font-semibold">#{{ issue.id }}</td>
                                <td class="px-4 py-3">#{{ issue.float_id }}</td>
                                <td class="px-4 py-3">{{ statusText(issue.status) }}</td>
                                <td class="px-4 py-3 text-right font-semibold">
                                    <MoneyText :value="issue.amount" />
                                </td>
                                <td class="px-4 py-3 text-ink-700/70">
                                    {{ issue.issued_by_name ?? '-' }}
                                </td>
                                <td class="px-4 py-3 text-ink-700/70">
                                    {{ issueDate(issue) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section
                class="overflow-hidden rounded-counter border border-paper-edge bg-white"
            >
                <header
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-paper-edge px-4 py-3"
                >
                    <div>
                        <h2 class="font-display text-base font-semibold">
                            {{ t('teller.floatTransactions', 'Float sessions') }}
                        </h2>
                        <p class="mt-0.5 text-xs text-ink-700/60">
                            {{
                                t(
                                    'teller.floatHistoryReadOnly',
                                    'Historical float sessions are read-only. Receive and return actions live in their own menus.',
                                )
                            }}
                        </p>
                    </div>
                    <button
                        type="button"
                        @click="refreshFloatPage"
                        class="bank-button bank-button-secondary min-h-9 rounded-counter px-3 py-1.5 text-xs"
                    >
                        {{ t('common.refresh', 'Refresh') }}
                    </button>
                </header>

                <div
                    v-if="rows.length === 0"
                    class="px-4 py-8 text-sm text-ink-700/65"
                >
                    {{ t('teller.noFloatTransactions', 'No float transaction yet.') }}
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[42rem] text-left text-sm">
                        <thead class="bg-paper text-xs text-ink-700/60">
                            <tr>
                                <th class="px-4 py-2 font-semibold">{{ t('teller.floatNumber') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ t('teller.status') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ t('teller.issued') }}</th>
                                <th class="px-4 py-2 text-right font-semibold">{{ t('teller.onHandNow') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ t('role.cashier') }}</th>
                                <th class="px-4 py-2 font-semibold">{{ t('component.time') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-paper-edge">
                            <tr
                                v-for="floatRow in paginatedRows"
                                :key="floatRow.id"
                                class="align-middle"
                            >
                                <td class="px-4 py-3 font-semibold">#{{ floatRow.id }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <StateChip :status="floatRow.status" />
                                        <span class="text-xs text-ink-700/60">
                                            {{ statusText(floatRow.status) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right font-semibold">
                                    <MoneyText :value="floatRow.total_amount" />
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <MoneyText :value="rowBalance(floatRow)" />
                                </td>
                                <td class="px-4 py-3 text-ink-700/70">
                                    {{ floatRow.issued_by_name ?? '-' }}
                                </td>
                                <td class="px-4 py-3 text-ink-700/70">
                                    {{ rowDate(floatRow) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <footer
                    v-if="rows.length"
                    class="mt-4 grid items-center gap-3 px-4 pb-4 text-sm font-semibold text-slate md:grid-cols-3"
                >
                    <span>
                        Showing {{ historyFirstRecord }} to {{ historyLastRecord }} of
                        {{ rows.length }} entries
                    </span>
                    <label class="bank-page-size justify-self-center">
                        {{ t('common.show', 'Show') }}
                        <select
                            v-model.number="historyPerPage"
                            class="bank-page-size-select"
                            @change="historyPage = 1"
                        >
                            <option :value="10">10</option>
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                        </select>
                        {{ t('common.entries', 'entries') }}
                    </label>
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            class="bank-button bank-button-secondary px-3 py-2"
                            :disabled="historyPage === 1"
                            @click="historyPage--"
                        >
                            {{ t('common.previous', 'Previous') }}
                        </button>
                        <span class="self-center">{{ historyPage }} / {{ historyPageCount }}</span>
                        <button
                            type="button"
                            class="bank-button bank-button-secondary px-3 py-2"
                            :disabled="historyPage === historyPageCount"
                            @click="historyPage++"
                        >
                            {{ t('common.next', 'Next') }}
                        </button>
                    </div>
                </footer>
            </section>
        </template>

        <div
            v-if="receiveOnly && (reviewFloat || reviewIssue)"
            class="fixed inset-0 z-40 grid place-items-center bg-ink-950/55 p-4"
            @keydown.esc="closeReview"
        >
            <div
                class="max-h-[90vh] w-full max-w-5xl overflow-y-auto rounded-counter border border-paper-edge bg-paper shadow-2xl"
                role="dialog"
                aria-modal="true"
            >
                <header
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-paper-edge bg-white px-5 py-4"
                >
                    <div>
                        <p class="field-label">
                            {{ reviewIssue ? 'Additional issue' : t('teller.floatNumber') }}
                            #{{ reviewIssue?.id ?? reviewFloat?.id }}
                        </p>
                        <h2 class="font-display text-lg font-semibold">
                            {{
                                t(
                                    'teller.countIncomingFloat',
                                    reviewIssue
                                        ? 'Review additional float issue'
                                        : 'Review incoming float',
                                )
                            }}
                        </h2>
                    </div>
                    <button
                        type="button"
                        @click="closeReview"
                        class="bank-button bank-button-secondary rounded-counter px-3 py-1.5 text-xs"
                    >
                        {{ t('common.close') }}
                    </button>
                </header>

                <div class="grid gap-5 p-5 lg:grid-cols-[1fr_19rem]">
                    <DenominationDrawer
                        :model-value="reviewIssued"
                        :notes="notes"
                        :target="reviewIssuedTotal"
                        :readonly="true"
                        :label="t('teller.cashierIssuedNotes', 'Cashier issued notes')"
                    />

                    <aside
                        class="h-fit rounded-counter border border-ink-800 bg-ink-900 p-5 text-ink-100"
                    >
                        <h3
                            class="font-display text-sm font-semibold tracking-[0.14em] text-ink-300 uppercase"
                        >
                            {{ t('teller.receipt') }}
                        </h3>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-ink-300">{{ t('teller.issued') }}</dt>
                                <dd><MoneyText :value="reviewIssuedTotal" /></dd>
                            </div>
                        </dl>
                        <p class="mt-3 text-xs leading-relaxed text-ink-300">
                            {{ t('teller.countMatch') }}
                        </p>
                        <div class="mt-5 grid gap-2">
                            <button
                                type="button"
                                @click="receiveReviewed"
                                class="bank-button rounded-counter bg-seal py-3 text-sm font-semibold text-ink-950 transition hover:brightness-110"
                            >
                                {{ t('teller.receiveFloatPin') }}
                            </button>
                            <button
                                type="button"
                                @click="reviewIssue && rejectAdditionalIssue(reviewIssue)"
                                class="bank-button bank-button-danger rounded-counter py-3 text-sm font-semibold"
                            >
                                {{ t('common.reject', 'Reject') }}
                            </button>
                        </div>
                    </aside>
                </div>
            </div>
        </div>

        <PinSeal
            :open="pinOpen"
            :busy="pinBusy"
            :error="pinError"
            :title="pinTitle"
            :detail="pinDetail"
            :confirm-label="pinConfirmLabel"
            @confirm="confirm"
            @close="closePin"
        />
    </BankLayout>
</template>
