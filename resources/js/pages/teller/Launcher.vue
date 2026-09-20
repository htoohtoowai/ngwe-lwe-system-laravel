<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import AppMenuIcon from '@/components/ui/AppMenuIcon.vue';
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
import type { MenuIconName } from '@/lib/menu-icons';
import { startSmartPolling } from '@/lib/smart-polling';

type LauncherAction = {
    icon: MenuIconName;
    href: string;
    labelEn: string;
    labelMm: string;
};

type TellerFloat = {
    id: number;
    holder: string;
    status: string;
    amount: string;
    issued_at: string;
};

type RecentEntry = {
    id: number;
    type: string;
    label: string;
    amount: string;
    direction: 'in' | 'out';
    time: string;
    created_at: string;
};

const props = defineProps<{
    role: 'teller';
    announcement?: string | null;
    notificationCount?: number;
    pendingCashInCount: number;
    floats: TellerFloat[];
    recent: RecentEntry[];
}>();

const { lang } = useLocale();
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

const actions: LauncherAction[] = [
    {
        icon: 'cashIn',
        href: '/transactions/cash-in',
        labelEn: 'Cash In',
        labelMm: 'ငွေသွင်း',
    },
    {
        icon: 'cashOut',
        href: '/transactions/cash-out',
        labelEn: 'Cash Out',
        labelMm: 'ငွေထုတ်',
    },
    {
        icon: 'sendMoney',
        href: '/transactions/send-money',
        labelEn: 'Send Money',
        labelMm: 'ငွေပို့',
    },
    {
        icon: 'receiveMoney',
        href: '/transactions/receive-money',
        labelEn: 'Receive Money',
        labelMm: 'ငွေလက်ခံ',
    },
    {
        icon: 'transfer',
        href: '/transactions/transfer',
        labelEn: 'Transfer',
        labelMm: 'ငွေလွှဲ',
    },
    {
        icon: 'exchange',
        href: '/transactions/exchange',
        labelEn: 'Exchange',
        labelMm: 'ငွေလဲ',
    },
];

const copy = computed(() =>
    lang.value === 'mm'
        ? {
              availableCash: 'အသုံးပြုနိုင်သော ကောင်တာငွေ',
              viewCashFloat: 'ကောင်တာငွေ ကြည့်ရန်',
              recentEntries: 'နောက်ဆုံးစာရင်းများ',
              latestActivity: 'နောက်ဆုံး ကောင်တာလုပ်ဆောင်ချက်',
              pendingCashIn: 'စောင့်ဆိုင်းနေသော ငွေသွင်း',
              waitingCashier: 'ငွေကိုင်အတည်ပြုချက် စောင့်ဆိုင်းနေသည်',
              myFloat: 'ကိုယ်ပိုင်ငွေခွဲ',
              goToFloats: 'ငွေခွဲစာရင်းသို့',
              noFloats: 'အသုံးပြုနေသော ငွေခွဲ မရှိသေးပါ။',
              onHand: 'လက်ထဲရှိငွေ',
              active: 'အသုံးပြုနေသည်',
              pendingReceipt: 'လက်ခံရန် စောင့်ဆိုင်း',
              pendingReconciliation: 'ပြန်အပ်စာရင်းစစ်ရန် စောင့်ဆိုင်း',
              recentHistory: 'နောက်ဆုံးလုပ်ငန်းမှတ်တမ်း',
              noRecentHistory: 'လုပ်ငန်းမှတ်တမ်း မရှိသေးပါ။',
          }
        : {
              availableCash: 'Available Counter Cash',
              viewCashFloat: 'View cash float',
              recentEntries: 'Recent Entries',
              latestActivity: 'Latest counter activity',
              pendingCashIn: 'Pending Cash In',
              waitingCashier: 'Waiting for Cashier confirmation',
              myFloat: 'My Float',
              goToFloats: 'Go to Floats',
              noFloats: 'No active floats right now.',
              onHand: 'On hand',
              active: 'Active',
              pendingReceipt: 'Pending receipt',
              pendingReconciliation: 'Pending reconciliation',
              recentHistory: 'Recent History',
              noRecentHistory: 'No recent transactions yet.',
          },
);

const activeFloat = computed(
    () => props.floats.find((float) => float.status === 'ACTIVE') ?? null,
);
const visibleFloat = computed(() => activeFloat.value ?? props.floats[0] ?? null);
const availableCounterCash = computed(() => activeFloat.value?.amount ?? '0.00');
const recentHistory = computed(() => props.recent.slice(0, 5));

const transactionLabels: Record<
    string,
    { en: string; mm: string }
> = {
    cash_in: { en: 'Cash In', mm: 'ငွေသွင်း' },
    cash_out: { en: 'Cash Out', mm: 'ငွေထုတ်' },
    send_money: { en: 'Send Money', mm: 'ငွေပို့' },
    receive_money: { en: 'Receive Money', mm: 'ငွေလက်ခံ' },
    transfer: { en: 'Transfer', mm: 'ငွေလွှဲ' },
    exchange: { en: 'Exchange', mm: 'ငွေလဲ' },
};

const transactionHistoryRoutes: Record<string, string> = {
    cash_in: '/transactions/cash-in/history',
    cash_out: '/transactions/cash-out/history',
    send_money: '/transactions/send-money/history',
    receive_money: '/transactions/receive-money/history',
    transfer: '/transactions/transfer/history',
    exchange: '/transactions/exchange/history',
};

function actionLabel(action: LauncherAction): string {
    return lang.value === 'mm' ? action.labelMm : action.labelEn;
}

function mmk(value: string | number): string {
    const amount = Number(value);

    return Number.isFinite(amount) ? amount.toLocaleString() : '0';
}

function normalizeTransactionType(type: string): string {
    return type.trim().toLowerCase().replaceAll('-', '_').replaceAll(' ', '_');
}

function transactionTypeLabel(type: string): string {
    const normalized = normalizeTransactionType(type);
    const labels = transactionLabels[normalized];

    if (!labels) {
        return type;
    }

    return lang.value === 'mm' ? labels.mm : labels.en;
}

function transactionHistoryHref(type: string): string {
    return transactionHistoryRoutes[normalizeTransactionType(type)] ?? '/teller';
}

function historyTime(entry: RecentEntry): string {
    if (!entry.created_at) {
        return entry.time;
    }

    const date = new Date(entry.created_at);

    if (Number.isNaN(date.getTime())) {
        return entry.time;
    }

    return new Intl.DateTimeFormat(lang.value === 'mm' ? 'my-MM' : 'en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}

function floatStatusLabel(status: string): string {
    if (status === 'ACTIVE') {
        return copy.value.active;
    }

    if (status === 'PENDING_RECEIPT') {
        return copy.value.pendingReceipt;
    }

    if (status === 'PENDING_RECONCILIATION') {
        return copy.value.pendingReconciliation;
    }

    return status.replaceAll('_', ' ').toLowerCase();
}

function authHeaders(): Record<string, string> {
    return {};
}

const refreshLauncher = () =>
    router.reload({
        only: [
            'floats',
            'recent',
            'notificationCount',
            'pendingCashInCount',
        ],
        headers: authHeaders(),
    });

onMounted(() => {
    const echo = createNgweLweEcho();
    const handlers: RealtimeHandlers = {
        balance_update: refreshLauncher,
        new_transaction: refreshLauncher,
        cash_in_pending: refreshLauncher,
        cash_in_confirmed: refreshLauncher,
        cash_in_cancelled: refreshLauncher,
        float_update: refreshLauncher,
        float_status_changed: refreshLauncher,
    };

    if (echo) {
        unwatchEchoConnection = watchNgweLweEchoConnection(echo, (state) => {
            realtimeConnected = state === 'connected';
        });
        unsubscribeRole = subscribeToRoleChannel(echo, 'teller', handlers);

        if (page.props.auth?.user?.id) {
            unsubscribeUser = subscribeToUserChannel(
                echo,
                page.props.auth.user.id,
                handlers,
            );
        }
    }

    stopRealtimeFallback = startSmartPolling({
        refresh: refreshLauncher,
        shouldPoll: () => !realtimeConnected,
        activeIntervalMs: 5_000,
        hiddenIntervalMs: 60_000,
    });
});

onBeforeUnmount(() => {
    stopRealtimeFallback?.();
    unwatchEchoConnection?.();
    unsubscribeRole?.();
    unsubscribeUser?.();
    disconnectNgweLweEcho();
});
</script>

<template>
    <BankLayout
        :role="props.role"
        :announcement="props.announcement"
        :notification-count="props.notificationCount"
    >
        <div class="mx-auto w-full max-w-5xl">
            <!-- Primary teller actions -->
            <section
                class="mx-auto w-full max-w-4xl px-1 pt-2 pb-8 sm:px-4 sm:pt-6 lg:pt-8"
                aria-label="Teller actions"
            >
                <div
                    class="grid grid-cols-2 gap-x-4 gap-y-7 sm:grid-cols-3 sm:gap-x-8 sm:gap-y-10 md:gap-x-12"
                >
                    <Link
                        v-for="action in actions"
                        :key="action.href"
                        :href="action.href"
                        class="group flex min-h-36 min-w-0 flex-col items-center justify-center rounded-[1.75rem] px-3 py-4 text-center outline-none transition duration-200 hover:bg-white/75 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-brand/70 focus-visible:ring-offset-2 active:scale-[0.98] sm:min-h-40"
                    >
                        <span
                            class="transition duration-200 group-hover:-translate-y-1 group-hover:scale-[1.04]"
                        >
                            <AppMenuIcon
                                :name="action.icon"
                                size="launcher"
                            />
                        </span>

                        <span
                            class="mt-3 w-full truncate text-sm font-bold tracking-tight text-ink sm:text-[15px]"
                        >
                            {{ actionLabel(action) }}
                        </span>
                    </Link>
                </div>
            </section>

            <!-- Mobile-first teller summary -->
            <section
                class="border-t border-line pt-6 sm:pt-7"
                aria-label="Teller summary"
            >
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4">
                    <Link
                        href="/teller/float"
                        :headers="authHeaders()"
                        class="col-span-2 rounded-3xl border border-line bg-card p-4 shadow-sm transition hover:border-brand/30 hover:shadow-md focus-visible:ring-2 focus-visible:ring-brand/70 focus-visible:outline-none sm:col-span-1 sm:p-5"
                    >
                        <p
                            class="text-[11px] font-black tracking-[0.08em] text-slate uppercase sm:text-xs"
                        >
                            {{ copy.availableCash }}
                        </p>
                        <p
                            class="money mt-2 text-2xl font-black tracking-tight text-ink sm:text-3xl"
                        >
                            {{ mmk(availableCounterCash) }}
                            <span class="text-sm font-black">MMK</span>
                        </p>
                        <p class="mt-3 text-xs font-bold text-brand">
                            {{ copy.viewCashFloat }} →
                        </p>
                    </Link>

                    <div
                        class="rounded-3xl border border-line bg-card p-4 shadow-sm sm:p-5"
                    >
                        <p
                            class="text-[11px] font-black tracking-[0.08em] text-slate uppercase sm:text-xs"
                        >
                            {{ copy.recentEntries }}
                        </p>
                        <p
                            class="mt-2 text-2xl font-black tracking-tight text-ink sm:text-3xl"
                        >
                            {{ props.recent.length }}
                        </p>
                        <p
                            class="mt-3 text-xs font-semibold leading-5 text-slate"
                        >
                            {{ copy.latestActivity }}
                        </p>
                    </div>

                    <div
                        class="rounded-3xl border border-line bg-card p-4 shadow-sm sm:p-5"
                    >
                        <p
                            class="text-[11px] font-black tracking-[0.08em] text-slate uppercase sm:text-xs"
                        >
                            {{ copy.pendingCashIn }}
                        </p>
                        <p
                            class="mt-2 text-2xl font-black tracking-tight text-ink sm:text-3xl"
                        >
                            {{ props.pendingCashInCount }}
                        </p>
                        <p
                            class="mt-3 text-xs font-semibold leading-5 text-slate"
                        >
                            {{ copy.waitingCashier }}
                        </p>
                    </div>
                </div>
            </section>

            <!-- My Float -->
            <section class="mt-6 sm:mt-7">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-lg font-black tracking-tight text-ink">
                        {{ copy.myFloat }}
                    </h2>

                    <Link
                        href="/teller/float"
                        :headers="authHeaders()"
                        class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-full border border-line bg-card px-4 text-xs font-bold text-slate transition hover:bg-mist hover:text-ink focus-visible:ring-2 focus-visible:ring-brand/70 focus-visible:outline-none"
                    >
                        {{ copy.goToFloats }}
                    </Link>
                </div>

                <Link
                    v-if="visibleFloat"
                    href="/teller/float"
                    :headers="authHeaders()"
                    class="mt-3 block rounded-3xl border border-line bg-card p-4 shadow-sm transition hover:border-brand/30 hover:shadow-md focus-visible:ring-2 focus-visible:ring-brand/70 focus-visible:outline-none sm:p-5"
                >
                    <div
                        class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="grid size-8 shrink-0 place-items-center rounded-full bg-brand text-[10px] font-black text-white"
                                >
                                    DP
                                </span>
                                <p
                                    class="money text-sm font-black tracking-wide text-ink"
                                >
                                    FLOAT ••
                                    {{
                                        String(visibleFloat.id).padStart(4, '0')
                                    }}
                                </p>
                                <span
                                    class="rounded-full px-2.5 py-1 text-[10px] font-black uppercase"
                                    :class="
                                        visibleFloat.status === 'ACTIVE'
                                            ? 'bg-balance/15 text-balance'
                                            : 'bg-mist text-slate'
                                    "
                                >
                                    {{ floatStatusLabel(visibleFloat.status) }}
                                </span>
                            </div>

                            <p class="mt-3 truncate text-sm font-semibold text-slate">
                                {{ visibleFloat.holder }}
                            </p>
                        </div>

                        <div class="sm:text-right">
                            <p
                                class="text-[10px] font-black tracking-[0.1em] text-slate uppercase"
                            >
                                {{ copy.onHand }}
                            </p>
                            <p
                                class="money mt-1 text-xl font-black tracking-tight text-ink"
                            >
                                {{
                                    visibleFloat.status === 'ACTIVE'
                                        ? mmk(visibleFloat.amount)
                                        : '0'
                                }}
                                <span class="text-xs">MMK</span>
                            </p>
                        </div>
                    </div>
                </Link>

                <div
                    v-else
                    class="mt-3 rounded-3xl border border-dashed border-line bg-card/60 px-5 py-8 text-center"
                >
                    <p class="text-sm font-semibold text-slate">
                        {{ copy.noFloats }}
                    </p>
                </div>
            </section>

            <!-- Recent History: maximum 5 rows -->
            <section class="mt-6 pb-8 sm:mt-7">
                <h2 class="text-lg font-black tracking-tight text-ink">
                    {{ copy.recentHistory }}
                </h2>

                <div
                    class="mt-3 overflow-hidden rounded-3xl border border-line bg-card shadow-sm"
                >
                    <Link
                        v-for="entry in recentHistory"
                        :key="entry.id"
                        :href="transactionHistoryHref(entry.type)"
                        :headers="authHeaders()"
                        class="flex min-h-[72px] items-center gap-3 border-b border-line px-4 py-3 transition last:border-b-0 hover:bg-mist/60 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/70 focus-visible:outline-none sm:px-5"
                    >
                        <span
                            class="size-2.5 shrink-0 rounded-full"
                            :class="
                                entry.direction === 'in'
                                    ? 'bg-credit'
                                    : 'bg-debit'
                            "
                            aria-hidden="true"
                        />

                        <div class="min-w-0 flex-1">
                            <div class="flex min-w-0 items-center gap-2">
                                <p
                                    class="truncate text-sm font-black text-ink"
                                >
                                    {{ transactionTypeLabel(entry.type) }}
                                </p>
                                <span
                                    class="shrink-0 text-[10px] font-bold text-slate"
                                >
                                    #{{ entry.id }}
                                </span>
                            </div>

                            <p
                                class="mt-0.5 truncate text-xs font-semibold text-slate"
                            >
                                {{ entry.label }}
                            </p>

                            <p class="mt-1 text-[11px] font-medium text-slate/80">
                                {{ historyTime(entry) }}
                            </p>
                        </div>

                        <p
                            class="money shrink-0 text-right text-sm font-black sm:text-base"
                            :class="
                                entry.direction === 'in'
                                    ? 'text-credit'
                                    : 'text-debit'
                            "
                        >
                            {{ entry.direction === 'in' ? '+' : '−'
                            }}{{ mmk(entry.amount) }}
                            <span class="text-[10px]">MMK</span>
                        </p>
                    </Link>

                    <div
                        v-if="recentHistory.length === 0"
                        class="px-5 py-8 text-center"
                    >
                        <p class="text-sm font-semibold text-slate">
                            {{ copy.noRecentHistory }}
                        </p>
                    </div>
                </div>
            </section>
        </div>
    </BankLayout>
</template>
