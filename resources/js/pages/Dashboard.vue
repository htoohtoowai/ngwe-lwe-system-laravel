<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import PinSeal from '@/components/teller/PinSeal.vue';
import BankLayout from '@/layouts/BankLayout.vue';
import { apiRequest } from '@/lib/api';
import { readStoredToken } from '@/lib/auth-token';
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

type DenominationMap = Record<string, number>;
type DenominationRow = {
    denomination: number;
    quantity: number;
    total: number;
};
type PendingCashIn = {
    id: number;
    amount: string;
    customer_name?: string | null;
    employee?: string | null;
    teller?: string | null;
    creator_role?: 'admin' | 'cashier' | 'teller' | string | null;
    settlement_amount?: string | null;
    customer_fee?: string | null;
    fee_payment_method?: string | null;
    received_denominations: DenominationMap;
    handoff_denominations: DenominationMap;
    change_denominations: DenominationMap;
    change_given: string;
    created_at: string;
};

/**
 * Overview — the reference dashboard mapped to money-transfer operations:
 *   Spend vs. Earn  → Cash In vs. Cash Out
 *   My Accounts     → service accounts, tabbed by company, horizontal rail
 *   My Cards        → active employee floats as dark cards
 *   Recent History  → latest transactions, green in / red out
 * Same page for all roles; the controller scopes the data (employee sees own
 * float and own transactions; cashier/owner see the office).
 */
const props = defineProps<{
    role: 'admin' | 'cashier' | 'teller';
    announcement?: string | null;
    notificationCount?: number;
    chart: { labels: string[]; cashIn: number[]; cashOut: number[] };
    range: '1y' | '6m' | '1m' | '1w';
    companies: string[];
    accounts: {
        id: number;
        company: string;
        name: string;
        number?: string;
        balance: string;
        is_fee_account?: boolean;
    }[];
    floats: {
        id: number;
        holder: string;
        status: string;
        amount: string;
        issued_at: string;
    }[];
    recent: {
        id: number;
        type: string;
        label: string;
        amount: string;
        direction: 'in' | 'out';
        time: string;
    }[];
    pendingCashIns: PendingCashIn[];
}>();

const RANGES = [
    { key: '1y', labelKey: 'dashboard.oneYear' },
    { key: '6m', labelKey: 'dashboard.sixMonths' },
    { key: '1m', labelKey: 'dashboard.oneMonth' },
    { key: '1w', labelKey: 'dashboard.oneWeek' },
] as const;

const { t } = useLocale();
const companyTab = ref<string>('All');
const page = usePage<{
    auth?: {
        user?: {
            id: number;
        } | null;
    };
}>();
let unsubscribeRole: (() => void) | null = null;
let unsubscribeUser: (() => void) | null = null;
let unwatchEchoConnection: (() => void) | null = null;
let stopRealtimeFallback: (() => void) | null = null;
let realtimeConnected = false;
const tabs = computed(() => ['All', ...props.companies]);
const visibleAccounts = computed(() =>
    companyTab.value === 'All'
        ? props.accounts
        : props.accounts.filter((a) => a.company === companyTab.value),
);
const visibleAccountTotal = computed(() =>
    visibleAccounts.value.reduce(
        (sum, account) => sum + Number(account.balance),
        0,
    ),
);
const canCreateTransactions = computed(() => props.role === 'teller');
const transactionHref = computed(() =>
    props.role === 'teller' ? '/teller' : '/dashboard',
);
const historyModeLabel = computed(() =>
    props.role === 'cashier'
        ? t('dashboard.reviewMode')
        : t('dashboard.configurationMode'),
);
const floatHref = computed(() =>
    props.role === 'teller' ? '/teller/float' : '/dashboard',
);
const pendingBusy = ref<number | null>(null);
const pendingError = ref('');
const pendingReview = ref<PendingCashIn | null>(null);
const pinOpen = ref(false);
const pinBusy = ref(false);
const pinError = ref<string | null>(null);
const pinAction = ref<'confirm' | 'cancel'>('confirm');

const pendingReviewRows = computed(() => ({
    received: denominationRows(pendingReview.value?.received_denominations),
    handoff: denominationRows(pendingReview.value?.handoff_denominations),
    change: denominationRows(pendingReview.value?.change_denominations),
}));
const pendingReviewUsesHandoff = computed(
    () => pendingReview.value?.creator_role === 'teller',
);
const pendingReviewSettlementRows = computed(() =>
    pendingReviewUsesHandoff.value
        ? pendingReviewRows.value.handoff
        : pendingReviewRows.value.received,
);
const pendingReviewSettlementTotal = computed(() =>
    denominationTotal(pendingReviewSettlementRows.value),
);
const pendingReviewSettlementLabel = computed(() =>
    pendingReviewUsesHandoff.value
        ? t('dashboard.handoff')
        : t('dashboard.mainVaultCash', 'Main vault cash'),
);
const pendingReviewExpectedSettlement = computed(() => {
    const transaction = pendingReview.value;

    if (!transaction) {
        return 0;
    }

    const settlementAmount =
        transaction.settlement_amount === null ||
        transaction.settlement_amount === undefined
            ? Number.NaN
            : Number(transaction.settlement_amount);

    if (Number.isFinite(settlementAmount)) {
        return settlementAmount;
    }

    const fee =
        transaction.fee_payment_method === 'cash'
            ? Number(transaction.customer_fee ?? 0)
            : 0;

    return Number(transaction.amount ?? 0) + fee;
});
const pendingReviewBalanced = computed(
    () =>
        pendingReviewSettlementTotal.value ===
        pendingReviewExpectedSettlement.value,
);

function authHeaders(): Record<string, string> {
    const token = readStoredToken();

    return token ? { Authorization: `Bearer ${token}` } : {};
}

function setRange(r: string) {
    router.reload({
        only: ['chart', 'range'],
        data: { range: r },
        headers: authHeaders(),
    });
}

const mmk = (v: string | number) => Number(v).toLocaleString();
const denoms = (map: Record<string, number>) =>
    Object.entries(map ?? {})
        .filter(([, quantity]) => Number(quantity) > 0)
        .sort(([a], [b]) => Number(b) - Number(a))
        .map(
            ([denomination, quantity]) =>
                `${Number(denomination).toLocaleString()} × ${quantity}`,
        )
        .join(', ') || '—';

const settlementDenominations = (
    transaction: PendingCashIn,
): DenominationMap =>
    transaction.creator_role === 'teller'
        ? transaction.handoff_denominations
        : transaction.received_denominations;

function denominationRows(
    map: DenominationMap | null | undefined,
): DenominationRow[] {
    return Object.entries(map ?? {})
        .map(([denomination, quantity]) => ({
            denomination: Number(denomination),
            quantity: Number(quantity),
            total: Number(denomination) * Number(quantity),
        }))
        .filter((row) => row.denomination > 0 && row.quantity > 0)
        .sort((a, b) => b.denomination - a.denomination);
}

function denominationTotal(rows: DenominationRow[]): number {
    return rows.reduce((sum, row) => sum + row.total, 0);
}

function openPendingReview(transaction: PendingCashIn) {
    pendingReview.value = transaction;
}

function closePendingReview() {
    if (pendingBusy.value === null && !pinBusy.value) {
        pendingReview.value = null;
    }
}

function requestPendingConfirmation() {
    if (!pendingReviewBalanced.value) {
        return;
    }

    pinAction.value = 'confirm';
    pinError.value = null;
    pinOpen.value = true;
}

function requestPendingCancellation() {
    pinAction.value = 'cancel';
    pinError.value = null;
    pinOpen.value = true;
}

function closePinSeal() {
    if (!pinBusy.value) {
        pinOpen.value = false;
    }
}

async function confirmPendingWithPin(pin: string) {
    if (!pendingReview.value) {
        return;
    }

    pinBusy.value = true;
    pinError.value = null;

    try {
        await reviewCashIn(pendingReview.value.id, pinAction.value, pin, true);
        pinOpen.value = false;
    } catch (error) {
        pinError.value =
            error instanceof Error
                ? error.message
                : t('dashboard.unableUpdate');
    } finally {
        pinBusy.value = false;
    }
}

async function reviewCashIn(
    id: number,
    action: 'confirm' | 'cancel',
    pin?: string,
    rethrow = false,
) {
    pendingBusy.value = id;
    pendingError.value = '';

    try {
        await apiRequest(`/api/transactions/${id}/${action}-cash-in`, {
            method: 'POST',
            token: readStoredToken(),
            body:
                action === 'confirm'
                    ? { pin }
                    : { pin, note: 'Cancelled by cashier during review.' },
        });
        pendingReview.value = null;
        router.reload({
            only: ['pendingCashIns', 'notificationCount', 'recent', 'floats'],
            headers: authHeaders(),
        });
    } catch (error) {
        pendingError.value =
            error instanceof Error
                ? error.message
                : t('dashboard.unableUpdate');

        if (rethrow) {
            throw error;
        }
    } finally {
        pendingBusy.value = null;
    }
}
watch(pendingReview, (review) => {
    document.body.style.overflow = review ? 'hidden' : '';
});
const refreshRealtimeData = () =>
    router.reload({
        only: ['recent', 'floats', 'notificationCount', 'pendingCashIns'],
        headers: authHeaders(),
    });

onMounted(() => {
    const echo = createNgweLweEcho(readStoredToken());

    const handlers: RealtimeHandlers = {
        balance_update: refreshRealtimeData,
        new_transaction: refreshRealtimeData,
        cash_in_pending: refreshRealtimeData,
        float_update: refreshRealtimeData,
        float_status_changed: refreshRealtimeData,
    };

    if (echo) {
        unwatchEchoConnection = watchNgweLweEchoConnection(echo, (state) => {
            realtimeConnected = state === 'connected';
        });
        unsubscribeRole = subscribeToRoleChannel(echo, props.role, handlers);

        if (page.props.auth?.user?.id) {
            unsubscribeUser = subscribeToUserChannel(
                echo,
                page.props.auth.user.id,
                handlers,
            );
        }
    }

    stopRealtimeFallback = startSmartPolling({
        refresh: refreshRealtimeData,
        shouldPoll: () => !realtimeConnected,
        activeIntervalMs: props.role === 'admin' ? 15_000 : 5_000,
        hiddenIntervalMs: 60_000,
    });
});

onBeforeUnmount(() => {
    stopRealtimeFallback?.();
    unwatchEchoConnection?.();
    unsubscribeRole?.();
    unsubscribeUser?.();
    disconnectNgweLweEcho();
    document.body.style.overflow = '';
});
</script>

<template>
    <BankLayout
        :role="role"
        :announcement="announcement"
        :notification-count="notificationCount"
    >
        <header class="flex flex-col gap-3 border-b border-line pb-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-xl font-black text-ink">{{ t('dashboard.title') }}</h1>
                <p class="mt-1 text-sm font-semibold text-slate">Your counter balance and daily work at a glance.</p>
            </div>
            <Link
                href="/teller"
                :headers="authHeaders()"
                class="rounded-xl bg-brand px-4 py-2.5 text-center text-sm font-black text-white shadow-sm"
            >
                New transaction
            </Link>
        </header>

        <section class="mt-5 grid gap-3 sm:grid-cols-3">
            <Link href="/teller/float" :headers="authHeaders()" class="rounded-2xl border border-line bg-card p-5 shadow-sm transition hover:border-brand/30">
                <p class="text-xs font-black text-slate uppercase">Available counter cash</p>
                <p class="money mt-2 text-2xl font-black text-ink">{{ mmk(floats.reduce((sum, item) => sum + Number(item.amount), 0)) }} MMK</p>
                <p class="mt-2 text-xs font-bold text-brand">View cash float →</p>
            </Link>
            <div class="rounded-2xl border border-line bg-card p-5 shadow-sm">
                <p class="text-xs font-black text-slate uppercase">Recent entries</p>
                <p class="mt-2 text-2xl font-black text-ink">{{ recent.length }}</p>
                <p class="mt-2 text-xs font-semibold text-slate">Latest counter activity</p>
            </div>
            <div class="rounded-2xl border border-line bg-card p-5 shadow-sm">
                <p class="text-xs font-black text-slate uppercase">Pending Cash In</p>
                <p class="mt-2 text-2xl font-black text-ink">{{ notificationCount ?? 0 }}</p>
                <p class="mt-2 text-xs font-semibold text-slate">Waiting for Cashier confirmation</p>
            </div>
        </section>

        <!-- ===== Floats as dark cards ===== -->
        <section class="mt-7">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-bold sm:text-lg">
                    {{
                        role === 'teller'
                            ? t('dashboard.myFloat')
                            : t('dashboard.activeFloats')
                    }}
                </h2>
                <Link
                    v-if="role === 'teller'"
                    :href="floatHref"
                    :headers="authHeaders()"
                    class="text-xs font-bold text-slate underline underline-offset-2 transition hover:text-brand"
                >
                    {{ t('dashboard.goToFloats') }}
                </Link>
                <span v-else class="text-xs font-bold text-slate">{{
                    t('dashboard.officeWide')
                }}</span>
            </div>

            <div
                class="mt-3 flex snap-x snap-mandatory gap-3 overflow-x-auto pb-2"
            >
                <article
                    v-for="f in floats"
                    :key="f.id"
                    class="relative w-72 shrink-0 snap-start overflow-hidden rounded-2xl bg-ink p-4 text-white shadow-md"
                >
                    <!-- card sheen -->
                    <div
                        class="pointer-events-none absolute -top-14 -right-10 size-40 rounded-full bg-white/10"
                    />
                    <div class="flex items-center justify-between">
                        <span
                            class="grid size-7 place-items-center rounded-full bg-brand text-[10px] font-bold"
                            >NL</span
                        >
                        <span
                            class="rounded-pill px-2.5 py-0.5 text-[10px] font-bold uppercase"
                            :class="
                                f.status === 'ACTIVE'
                                    ? 'bg-balance/90'
                                    : 'bg-white/20'
                            "
                        >
                            {{
                                f.status === 'ACTIVE'
                                    ? 'Active'
                                    : f.status
                                          .replaceAll('_', ' ')
                                          .toLowerCase()
                            }}
                        </span>
                    </div>
                    <p class="money mt-5 text-lg font-bold tracking-wider">
                        FLOAT •• {{ String(f.id).padStart(4, '0') }}
                    </p>
                    <div class="mt-4 flex items-end justify-between">
                        <div>
                            <p
                                class="text-[10px] tracking-wide text-white/60 uppercase"
                            >
                                {{ t('dashboard.holder') }}
                            </p>
                            <p class="text-sm font-semibold">{{ f.holder }}</p>
                        </div>
                        <div class="text-right">
                            <p
                                class="text-[10px] tracking-wide text-white/60 uppercase"
                            >
                                {{ t('dashboard.onHand') }}
                            </p>
                            <p class="money text-base font-bold">
                                {{ mmk(f.amount) }}
                                <span class="text-[10px]">MMK</span>
                            </p>
                        </div>
                    </div>
                </article>
                <p v-if="!floats.length" class="py-8 text-sm text-slate">
                    {{ t('dashboard.noFloats') }}
                </p>
            </div>
        </section>

        <!-- ===== Recent history ===== -->
        <section class="mt-7 rounded-2xl border border-line bg-card shadow-sm">
            <div class="flex items-center justify-between px-4 py-3.5 sm:px-6">
                <h2 class="text-base font-bold sm:text-lg">
                    {{ t('dashboard.recentHistory') }}
                </h2>
                <Link
                    v-if="canCreateTransactions"
                    :href="transactionHref"
                    :headers="authHeaders()"
                    class="text-xs font-bold text-slate underline underline-offset-2 transition hover:text-brand"
                >
                    {{ t('dashboard.newEntry') }}
                </Link>
                <span v-else class="text-xs font-bold text-slate">{{
                    historyModeLabel
                }}</span>
            </div>
            <ul class="divide-y divide-line">
                <li v-for="txn in recent" :key="txn.id">
                    <component
                        :is="canCreateTransactions ? Link : 'div'"
                        v-bind="
                            canCreateTransactions
                                ? {
                                      href: transactionHref,
                                      headers: authHeaders(),
                                  }
                                : {}
                        "
                        class="flex items-center gap-3 px-4 py-3 transition sm:px-6"
                        :class="canCreateTransactions ? 'hover:bg-mist/50' : ''"
                    >
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-full text-sm"
                            :class="
                                txn.direction === 'in'
                                    ? 'bg-balance/10 text-balance'
                                    : 'bg-brand-soft text-brand'
                            "
                        >
                            {{ txn.direction === 'in' ? '↓' : '↑' }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold">
                                {{ txn.label }}
                            </p>
                            <p class="text-[11px] text-slate">
                                {{ txn.type }} · {{ txn.time }}
                            </p>
                        </div>
                        <p
                            class="money shrink-0 text-sm font-bold"
                            :class="
                                txn.direction === 'in'
                                    ? 'text-balance'
                                    : 'text-brand'
                            "
                        >
                            {{ txn.direction === 'in' ? '+' : '−'
                            }}{{ mmk(txn.amount) }}
                            <span class="text-[10px] font-semibold text-slate"
                                >MMK</span
                            >
                        </p>
                    </component>
                </li>
                <li
                    v-if="!recent.length"
                    class="px-6 py-10 text-center text-sm text-slate"
                >
                    {{ t('common.noTransactions') }}
                </li>
            </ul>
        </section>

        <section
            v-if="role === 'cashier'"
            class="mt-7 rounded-2xl border border-line bg-card shadow-sm"
        >
            <div class="flex items-center justify-between px-4 py-3.5 sm:px-6">
                <div>
                    <h2 class="text-base font-bold sm:text-lg">
                        {{ t('dashboard.pendingCashIn') }}
                    </h2>
                    <p class="text-xs text-slate">
                        {{ t('dashboard.pendingReviewHint') }}
                    </p>
                </div>
                <span
                    class="rounded-pill bg-brand-soft px-3 py-1 text-xs font-bold text-brand"
                    >{{ pendingCashIns.length }}
                    {{ t('dashboard.pending') }}</span
                >
            </div>
            <p
                v-if="pendingError"
                class="mx-4 mb-3 rounded-field bg-brand-soft px-3 py-2 text-sm font-semibold text-brand sm:mx-6"
            >
                {{ pendingError }}
            </p>
            <div v-if="pendingCashIns.length" class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead
                        class="border-y border-line bg-mist/50 text-xs tracking-wide text-slate uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 sm:px-6">
                                {{ t('transaction.cashIn') }}
                            </th>
                            <th class="px-4 py-3">
                                {{ t('dashboard.received') }}
                            </th>
                            <th class="px-4 py-3">
                                {{
                                    t(
                                        'dashboard.settlementCash',
                                        'Settlement cash',
                                    )
                                }}
                            </th>
                            <th class="px-4 py-3">
                                {{ t('dashboard.change') }}
                            </th>
                            <th class="px-4 py-3 sm:px-6">
                                {{ t('dashboard.action') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="txn in pendingCashIns" :key="txn.id">
                            <td class="px-4 py-3 sm:px-6">
                                <p class="font-bold">
                                    #{{ txn.id }} · {{ mmk(txn.amount) }} MMK
                                </p>
                                <p class="text-xs text-slate">
                                    {{ txn.teller || txn.employee || 'Teller' }}
                                    · {{ txn.customer_name || 'Customer' }}
                                </p>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate">
                                {{ denoms(txn.received_denominations) }}
                            </td>
                            <td class="px-4 py-3 text-xs font-semibold">
                                {{ denoms(settlementDenominations(txn)) }}
                            </td>
                            <td class="px-4 py-3 text-xs text-brand">
                                {{
                                    txn.change_given === '0.00'
                                        ? '—'
                                        : `${mmk(txn.change_given)} MMK · ${denoms(txn.change_denominations)}`
                                }}
                            </td>
                            <td class="px-4 py-3 sm:px-6">
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        :disabled="pendingBusy === txn.id"
                                        class="rounded-pill bg-ink px-3 py-1.5 text-xs font-bold text-white disabled:opacity-40"
                                        @click="openPendingReview(txn)"
                                    >
                                        {{ t('dashboard.confirm') }}</button
                                    ><button
                                        type="button"
                                        :disabled="pendingBusy === txn.id"
                                        class="rounded-pill border border-line px-3 py-1.5 text-xs font-bold text-brand disabled:opacity-40"
                                        @click="
                                            openPendingReview(txn);
                                            requestPendingCancellation();
                                        "
                                    >
                                        {{ t('dashboard.cancel') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="px-6 py-10 text-center text-sm text-slate">
                {{ t('dashboard.noPending') }}
            </p>
        </section>

        <div
            v-if="pendingReview"
            class="fixed inset-0 z-50 grid place-items-center bg-ink/55 p-3 backdrop-blur-sm sm:p-6"
            role="presentation"
            @click.self="closePendingReview"
        >
            <section
                class="max-h-[calc(100vh-1.5rem)] w-full max-w-4xl overflow-y-auto rounded-3xl border border-line bg-card shadow-2xl sm:max-h-[calc(100vh-3rem)]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="cashier-denomination-review-title"
            >
                <header
                    class="flex items-start justify-between gap-4 border-b border-line px-5 py-5 sm:px-7"
                >
                    <div>
                        <p
                            class="text-[11px] font-black tracking-[0.14em] text-brand uppercase"
                        >
                            {{ t('dashboard.denominationReview') }}
                        </p>
                        <h2
                            id="cashier-denomination-review-title"
                            class="mt-1 text-lg font-black tracking-tight sm:text-xl"
                        >
                            {{ t('dashboard.verifyBeforeConfirm') }}
                        </h2>
                        <p class="mt-1 max-w-2xl text-xs leading-5 text-slate">
                            {{ t('dashboard.denominationReviewHint') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="grid size-9 shrink-0 place-items-center rounded-full border border-line bg-mist text-xl leading-none text-slate transition hover:text-ink disabled:opacity-40"
                        aria-label="Close denomination review"
                        :disabled="pendingBusy !== null"
                        @click="closePendingReview"
                    >
                        ×
                    </button>
                </header>

                <div
                    class="grid gap-2 border-b border-line bg-mist/45 p-4 sm:grid-cols-3 sm:gap-3 sm:px-7"
                >
                    <div
                        class="rounded-xl border border-line bg-card px-3 py-2.5"
                    >
                        <p
                            class="text-[10px] font-bold tracking-wide text-slate uppercase"
                        >
                            {{ t('component.reference') }}
                        </p>
                        <p class="mt-1 font-bold">#{{ pendingReview.id }}</p>
                    </div>
                    <div
                        class="rounded-xl border border-line bg-card px-3 py-2.5"
                    >
                        <p
                            class="text-[10px] font-bold tracking-wide text-slate uppercase"
                        >
                            {{ t('transaction.cashIn') }}
                        </p>
                        <p class="money mt-1 font-black">
                            {{ mmk(pendingReview.amount) }} MMK
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-line bg-card px-3 py-2.5"
                    >
                        <p
                            class="text-[10px] font-bold tracking-wide text-slate uppercase"
                        >
                            {{
                                t(
                                    'dashboard.expectedSettlement',
                                    'Expected settlement',
                                )
                            }}
                        </p>
                        <p class="money mt-1 font-black">
                            {{ mmk(pendingReviewExpectedSettlement) }} MMK
                        </p>
                    </div>
                </div>

                <div class="grid gap-3 p-4 sm:grid-cols-2 sm:p-7">
                    <section
                        class="overflow-hidden rounded-2xl border border-brand/25 bg-card"
                    >
                        <header
                            class="flex items-center justify-between gap-3 border-b border-brand/15 bg-brand-soft/50 px-4 py-3"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="grid size-7 place-items-center rounded-lg bg-brand text-[10px] font-black text-white"
                                    >01</span
                                >
                                <h3 class="text-sm font-bold">
                                    {{ t('dashboard.received') }}
                                </h3>
                            </div>
                            <strong class="money text-sm"
                                >{{
                                    mmk(
                                        denominationTotal(
                                            pendingReviewRows.received,
                                        ),
                                    )
                                }}
                                MMK</strong
                            >
                        </header>
                        <div class="grid gap-1.5 p-3">
                            <div
                                v-for="row in pendingReviewRows.received"
                                :key="`review-received-${row.denomination}`"
                                class="flex items-center justify-between rounded-lg bg-mist/60 px-3 py-2 text-xs"
                            >
                                <span class="money font-semibold"
                                    >{{ mmk(row.denomination) }} ×
                                    {{ row.quantity }}</span
                                ><strong class="money">{{
                                    mmk(row.total)
                                }}</strong>
                            </div>
                            <p
                                v-if="!pendingReviewRows.received.length"
                                class="px-2 py-3 text-xs text-slate"
                            >
                                {{ t('dashboard.noDenomination') }}
                            </p>
                        </div>
                    </section>

                    <section
                        v-if="pendingReviewUsesHandoff"
                        class="overflow-hidden rounded-2xl border border-balance/30 bg-card shadow-sm ring-2 ring-balance/5"
                    >
                        <header
                            class="flex items-center justify-between gap-3 border-b border-balance/15 bg-balance/5 px-4 py-3"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="grid size-7 place-items-center rounded-lg bg-ink text-[10px] font-black text-white"
                                    >02</span
                                >
                                <h3 class="text-sm font-bold">
                                    {{ pendingReviewSettlementLabel }}
                                </h3>
                            </div>
                            <strong class="money text-sm"
                                >{{
                                    mmk(pendingReviewSettlementTotal)
                                }}
                                MMK</strong
                            >
                        </header>
                        <div class="grid gap-1.5 p-3">
                            <div
                                v-for="row in pendingReviewRows.handoff"
                                :key="`review-handoff-${row.denomination}`"
                                class="flex items-center justify-between rounded-lg bg-mist/60 px-3 py-2 text-xs"
                            >
                                <span class="money font-semibold"
                                    >{{ mmk(row.denomination) }} ×
                                    {{ row.quantity }}</span
                                ><strong class="money">{{
                                    mmk(row.total)
                                }}</strong>
                            </div>
                            <p
                                v-if="!pendingReviewRows.handoff.length"
                                class="px-2 py-3 text-xs text-slate"
                            >
                                {{ t('dashboard.noDenomination') }}
                            </p>
                        </div>
                    </section>

                    <section
                        v-if="pendingReviewRows.change.length"
                        class="overflow-hidden rounded-2xl border border-held/30 bg-card sm:col-span-2"
                    >
                        <header
                            class="flex items-center justify-between gap-3 border-b border-held/15 bg-held/5 px-4 py-3"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="grid size-7 place-items-center rounded-lg bg-held text-[10px] font-black text-white"
                                    >03</span
                                >
                                <h3 class="text-sm font-bold">
                                    {{ t('dashboard.change') }}
                                </h3>
                            </div>
                            <strong class="money text-sm text-held"
                                >{{
                                    mmk(
                                        denominationTotal(
                                            pendingReviewRows.change,
                                        ),
                                    )
                                }}
                                MMK</strong
                            >
                        </header>
                        <div class="grid gap-1.5 p-3 sm:grid-cols-3">
                            <div
                                v-for="row in pendingReviewRows.change"
                                :key="`review-change-${row.denomination}`"
                                class="flex items-center justify-between rounded-lg bg-held/5 px-3 py-2 text-xs"
                            >
                                <span class="money font-semibold"
                                    >{{ mmk(row.denomination) }} ×
                                    {{ row.quantity }}</span
                                ><strong class="money">{{
                                    mmk(row.total)
                                }}</strong>
                            </div>
                        </div>
                    </section>
                </div>

                <div
                    class="mx-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border px-4 py-3 text-xs sm:mx-7"
                    :class="
                        pendingReviewBalanced
                            ? 'border-balance/25 bg-balance/5 text-balance'
                            : 'border-brand/25 bg-brand-soft text-brand'
                    "
                >
                    <strong>{{
                        pendingReviewBalanced
                            ? t('dashboard.denominationBalanced')
                            : t('dashboard.denominationMismatch')
                    }}</strong>
                    <span class="money"
                        >{{ pendingReviewSettlementLabel }}
                        {{ mmk(pendingReviewSettlementTotal) }} /
                        {{ mmk(pendingReviewExpectedSettlement) }} MMK</span
                    >
                </div>

                <footer
                    class="flex justify-end gap-2 px-4 py-4 sm:px-7 sm:py-5"
                >
                    <button
                        type="button"
                        class="rounded-pill border border-line px-4 py-2 text-xs font-bold text-slate transition hover:bg-mist disabled:opacity-40"
                        :disabled="pendingBusy !== null"
                        @click="closePendingReview"
                    >
                        {{ t('dashboard.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-pill border border-brand px-4 py-2 text-xs font-bold text-brand transition hover:bg-brand-soft disabled:opacity-40"
                        :disabled="pendingBusy !== null"
                        @click="requestPendingCancellation"
                    >
                        Reject Cash In
                    </button>
                    <button
                        type="button"
                        class="rounded-pill bg-ink px-4 py-2 text-xs font-bold text-white transition hover:bg-brand disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="
                            pendingBusy !== null || !pendingReviewBalanced
                        "
                        @click="requestPendingConfirmation"
                    >
                        {{ t('common.authorise') }}
                    </button>
                </footer>
            </section>
        </div>

        <PinSeal
            :open="pinOpen"
            :title="
                pinAction === 'confirm'
                    ? t('dashboard.confirmCashInWithPin')
                    : 'Reject Cash In'
            "
            :detail="
                pinAction === 'confirm'
                    ? t('dashboard.confirmCashInWithPinHint')
                    : 'Enter your Cashier PIN to reverse this pending Cash In.'
            "
            :busy="pinBusy"
            :error="pinError"
            @close="closePinSeal"
            @confirm="confirmPendingWithPin"
        />
    </BankLayout>
</template>
