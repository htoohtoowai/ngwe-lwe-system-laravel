<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import MoneyText from '@/components/teller/MoneyText.vue';
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
import { transactionTone } from '@/lib/transaction-tone';

type TellerFloat = {
    id: number;
    status: string;
    current_balance: string;
    issued_amount: string;
} | null;

const props = defineProps<{
    float: TellerFloat;
    denominations: { note: number; quantity: number }[];
    today: {
        cash_in: string;
        cash_out: string;
        transfer: string;
        exchange: string;
        count: number;
    };
    recent: Array<{
        id: number;
        type: string;
        amount: string;
        fee_amount: string;
        status: string;
    }>;
}>();

const { t } = useLocale();
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
const actions = computed(() => [
    {
        label: t('nav.cashIn'),
        href: '/transactions/cash-in',
        note: t('teller.cashInNote'),
        cardTone:
            'border-credit/25 bg-credit/5 hover:border-credit/55 hover:bg-credit/10',
        labelTone: 'text-credit',
        dotTone: 'bg-credit',
    },
    {
        label: t('nav.cashOut'),
        href: '/transactions/cash-out',
        note: t('teller.cashOutNote'),
        cardTone:
            'border-debit/25 bg-debit/5 hover:border-debit/55 hover:bg-debit/10',
        labelTone: 'text-debit',
        dotTone: 'bg-debit',
    },
    {
        label: t('nav.transfer'),
        href: '/transactions/transfer',
        note: t('teller.transferNote'),
        cardTone:
            'border-ink-700/20 bg-ink-100/40 hover:border-ink-700/45 hover:bg-ink-100/70',
        labelTone: 'text-ink-700',
        dotTone: 'bg-ink-700',
    },
    {
        label: t('nav.exchange'),
        href: '/transactions/exchange',
        note: t('teller.exchangeNote'),
        cardTone:
            'border-held/25 bg-held/5 hover:border-held/50 hover:bg-held/10',
        labelTone: 'text-held',
        dotTone: 'bg-held',
    },
]);
const locked = computed(() => props.float?.status !== 'ACTIVE');
const paidOutToday = computed(() =>
    Math.max(
        0,
        Number(props.today.cash_out) +
            Number(props.today.transfer) +
            Number(props.today.exchange),
    ),
);

function authHeaders(): Record<string, string> {
    return {};
}

const refreshTellerCounter = () =>
    router.reload({
        only: ['float', 'denominations', 'today', 'recent'],
        headers: authHeaders(),
    });

onMounted(() => {
    const echo = createNgweLweEcho();
    const handlers: RealtimeHandlers = {
        balance_update: refreshTellerCounter,
        new_transaction: refreshTellerCounter,
        float_update: refreshTellerCounter,
        float_status_changed: refreshTellerCounter,
        cash_in_confirmed: refreshTellerCounter,
        cash_in_cancelled: refreshTellerCounter,
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
        refresh: refreshTellerCounter,
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
        <div
            v-if="locked"
            class="mb-5 rounded-field bg-brand-soft px-4 py-3 text-sm font-semibold text-brand-deep"
        >
            {{ t('transaction.floatLocked') }}
            <Link
                href="/teller/float"
                :headers="authHeaders()"
                class="underline underline-offset-2"
                >{{ t('dashboard.goToFloats') }}</Link
            >
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <section
                class="rounded-counter border border-paper-edge bg-white lg:col-span-2"
            >
                <header
                    class="flex items-center justify-between gap-3 border-b border-paper-edge px-4 py-4 sm:px-5"
                >
                    <div>
                        <h1 class="font-display text-lg font-semibold">
                            {{ t('teller.till') }}
                        </h1>
                        <p class="text-xs text-ink-700/65">
                            {{ t('teller.floatNumber') }} #{{
                                float?.id ?? '-'
                            }}
                        </p>
                    </div>
                    <StateChip :status="float?.status ?? 'CLOSED'" />
                </header>

                <div
                    class="grid grid-cols-3 divide-x divide-paper-edge border-b border-paper-edge"
                >
                    <div class="min-w-0 px-3 py-4 sm:px-5">
                        <p class="field-label">{{ t('teller.issued') }}</p>
                        <MoneyText
                            :value="float?.issued_amount ?? 0"
                            class="mt-1 block text-base font-semibold sm:text-xl"
                        />
                    </div>
                    <div class="min-w-0 px-3 py-4 sm:px-5">
                        <p class="field-label">{{ t('teller.onHandNow') }}</p>
                        <MoneyText
                            :value="float?.current_balance ?? 0"
                            class="mt-1 block text-base font-semibold sm:text-xl"
                        />
                    </div>
                    <div class="min-w-0 px-3 py-4 sm:px-5">
                        <p class="field-label">
                            {{ t('teller.paidOutToday') }}
                        </p>
                        <MoneyText
                            :value="paidOutToday"
                            class="mt-1 block text-base font-semibold text-debit sm:text-xl"
                        />
                    </div>
                </div>

                <ul
                    class="grid grid-cols-2 gap-x-4 px-4 py-4 sm:grid-cols-3 sm:gap-x-6 sm:px-5"
                >
                    <li
                        v-for="d in denominations"
                        :key="d.note"
                        class="flex items-baseline justify-between border-b border-dashed border-paper-edge py-1.5"
                    >
                        <span
                            class="money text-sm font-semibold text-ink-900 tabular-nums"
                            >{{ d.note.toLocaleString() }}</span
                        >
                        <span
                            class="money text-sm font-semibold"
                            :class="
                                d.quantity ? 'text-ink-900' : 'text-ink-300'
                            "
                        >
                            x{{ d.quantity }}
                        </span>
                    </li>
                </ul>
            </section>

            <section
                class="rounded-counter border border-paper-edge bg-white p-5"
            >
                <h2 class="font-display text-lg font-semibold">
                    {{ t('teller.today') }}
                </h2>
                <p class="text-xs text-ink-700/65">
                    {{ today.count }} {{ t('teller.transactionsEntered') }}
                </p>
                <dl class="mt-4 space-y-2.5 text-sm">
                    <div class="flex justify-between">
                        <dt
                            class="font-semibold"
                            :class="transactionTone('cash_in').text"
                        >
                            {{ t('nav.cashIn') }}
                        </dt>
                        <dd>
                            <MoneyText
                                :value="today.cash_in"
                                :class="transactionTone('cash_in').text"
                            />
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt
                            class="font-semibold"
                            :class="transactionTone('cash_out').text"
                        >
                            {{ t('nav.cashOut') }}
                        </dt>
                        <dd>
                            <MoneyText
                                :value="today.cash_out"
                                :class="transactionTone('cash_out').text"
                            />
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt
                            class="font-semibold"
                            :class="transactionTone('transfer').text"
                        >
                            {{ t('nav.transfer') }}
                        </dt>
                        <dd>
                            <MoneyText
                                :value="today.transfer"
                                :class="transactionTone('transfer').text"
                            />
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt
                            class="font-semibold"
                            :class="transactionTone('exchange').text"
                        >
                            {{ t('nav.exchange') }}
                        </dt>
                        <dd>
                            <MoneyText
                                :value="today.exchange"
                                :class="transactionTone('exchange').text"
                            />
                        </dd>
                    </div>
                </dl>
            </section>
        </div>

        <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Link
                v-for="a in actions"
                :key="a.href"
                :href="locked ? '#' : a.href"
                :aria-disabled="locked"
                :headers="authHeaders()"
                class="group min-h-24 rounded-counter border p-3 transition sm:p-4"
                :class="[
                    a.cardTone,
                    locked
                        ? 'pointer-events-none opacity-45'
                        : 'hover:-translate-y-0.5 hover:shadow-sm',
                ]"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="size-2.5 shrink-0 rounded-full"
                        :class="a.dotTone"
                        aria-hidden="true"
                    ></span>
                    <p class="font-display font-semibold" :class="a.labelTone">
                        {{ a.label }}
                    </p>
                </div>
                <p class="mt-1.5 text-xs leading-relaxed text-ink-700/75">
                    {{ a.note }}
                </p>
            </Link>
        </div>

        <section class="mt-6 rounded-counter border border-paper-edge bg-white">
            <h2
                class="border-b border-paper-edge px-5 py-3 font-display font-semibold"
            >
                {{ t('teller.recentEntries') }}
            </h2>
            <p
                v-if="!recent.length"
                class="px-5 py-10 text-center text-sm text-ink-700/60"
            >
                {{ t('teller.noRecentEntries') }}
            </p>
            <template v-else>
                <ul class="divide-y divide-paper-edge sm:hidden">
                    <li
                        v-for="entry in recent"
                        :key="entry.id"
                        class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-1 px-4 py-3"
                    >
                        <div class="min-w-0">
                            <div class="flex min-w-0 items-center gap-2">
                                <span
                                    class="money text-sm font-semibold text-ink-900"
                                    >#{{ entry.id }}</span
                                >
                                <span
                                    class="truncate rounded-pill border px-2 py-0.5 text-[10px] font-black uppercase"
                                    :class="transactionTone(entry.type).badge"
                                >
                                    {{ entry.type.replaceAll('_', ' ') }}
                                </span>
                            </div>
                            <StateChip :status="entry.status" class="mt-1" />
                        </div>
                        <div class="text-right">
                            <MoneyText
                                :value="entry.amount"
                                class="font-semibold"
                            />
                            <p class="mt-1 text-xs text-ink-700/60">
                                {{ t('teller.fee') }}:
                                <MoneyText :value="entry.fee_amount" />
                            </p>
                        </div>
                    </li>
                </ul>
                <div class="hidden overflow-x-auto sm:block">
                    <table class="w-full min-w-[40rem] text-sm">
                        <thead>
                            <tr class="border-b border-paper-edge text-left">
                                <th class="field-label px-5 py-2">
                                    {{ t('teller.ref') }}
                                </th>
                                <th class="field-label px-5 py-2">
                                    {{ t('teller.type') }}
                                </th>
                                <th class="field-label px-5 py-2 text-right">
                                    {{ t('teller.amount') }}
                                </th>
                                <th class="field-label px-5 py-2 text-right">
                                    {{ t('teller.fee') }}
                                </th>
                                <th class="field-label px-5 py-2 text-right">
                                    {{ t('teller.status') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-paper-edge">
                            <tr
                                v-for="t in recent"
                                :key="t.id"
                                class="hover:bg-ink-100/40"
                            >
                                <td class="money px-5 py-2.5 text-ink-700">
                                    #{{ t.id }}
                                </td>
                                <td class="px-5 py-2.5 font-medium">
                                    <span
                                        class="rounded-pill border px-2.5 py-1 text-[10px] font-black uppercase"
                                        :class="transactionTone(t.type).badge"
                                    >
                                        {{ t.type.replaceAll('_', ' ') }}
                                    </span>
                                </td>
                                <td class="px-5 py-2.5 text-right">
                                    <MoneyText :value="t.amount" />
                                </td>
                                <td class="px-5 py-2.5 text-right text-ink-700">
                                    <MoneyText :value="t.fee_amount" />
                                </td>
                                <td class="px-5 py-2.5 text-right">
                                    <StateChip :status="t.status" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>
    </BankLayout>
</template>
