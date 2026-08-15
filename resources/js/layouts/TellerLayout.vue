<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import MoneyText from '@/components/teller/MoneyText.vue';
import StateChip from '@/components/teller/StateChip.vue';
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

const props = defineProps<{
    float?: {
        id: number;
        status: string;
        current_balance: string;
        issued_amount?: string;
    } | null;
}>();

const page = usePage<{
    auth?: {
        user?: {
            id: number;
            username: string;
            full_name: string | null;
        } | null;
    };
}>();
const user = computed(() => page.props.auth?.user);
const active = computed(() => props.float?.status === 'ACTIVE');
const { lang, setLang, t } = useLocale();
let unsubscribeTeller: (() => void) | null = null;
let unsubscribeUser: (() => void) | null = null;
let unwatchEchoConnection: (() => void) | null = null;
let stopRealtimeFallback: (() => void) | null = null;
let realtimeConnected = false;

const nav = [
    { key: 'nav.counter', href: '/teller', icon: 'CT' },
    { key: 'nav.cashIn', href: '/transactions/cash-in', icon: 'CI' },
    { key: 'nav.cashOut', href: '/transactions/cash-out', icon: 'CO' },
    { key: 'nav.transfer', href: '/transactions/transfer', icon: 'TR' },
    { key: 'nav.exchange', href: '/transactions/exchange', icon: 'EX' },
    { key: 'nav.myFloat', href: '/teller/float', icon: 'FL' },
];

const navLabel = (item: { key: string }) => t(item.key);

function authHeaders(): Record<string, string> {
    const token = readStoredToken();

    return token ? { Authorization: `Bearer ${token}` } : {};
}

const refreshTellerData = () =>
    router.reload({ only: ['float', 'recent'], headers: authHeaders() });

onMounted(() => {
    const token = readStoredToken();
    const echo = createNgweLweEcho(token);

    const handlers: RealtimeHandlers = {
        balance_update: refreshTellerData,
        float_status_changed: refreshTellerData,
        cash_in_confirmed: refreshTellerData,
        cash_in_cancelled: refreshTellerData,
    };

    if (echo) {
        unwatchEchoConnection = watchNgweLweEchoConnection(echo, (state) => {
            realtimeConnected = state === 'connected';
        });
        unsubscribeTeller = subscribeToRoleChannel(echo, 'teller', handlers);

        if (user.value?.id) {
            unsubscribeUser = subscribeToUserChannel(
                echo,
                user.value.id,
                handlers,
            );
        }
    }

    stopRealtimeFallback = startSmartPolling({
        refresh: refreshTellerData,
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

function signOut() {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-screen bg-paper font-sans text-ink-900">
        <header
            class="sticky top-0 z-30 border-b border-ink-800 bg-ink-900 text-ink-100"
        >
            <div class="mx-auto flex max-w-7xl items-center gap-4 px-4 py-2.5">
                <div class="flex items-center gap-2.5">
                    <span
                        class="grid size-8 place-items-center rounded-counter bg-seal/90 font-display text-sm font-bold text-ink-950"
                        >ဒ</span
                    >
                    <div class="leading-tight">
                        <p
                            class="font-display text-sm font-semibold tracking-tight"
                        >
                            {{ t('brand.name') }} - {{ t('role.teller') }}
                        </p>
                        <p class="text-[11px] text-ink-300">
                            {{ user?.full_name ?? user?.username }} -
                            {{ t('role.teller') }}
                        </p>
                    </div>
                </div>

                <div class="ml-auto flex items-center gap-4">
                    <div class="hidden text-right sm:block">
                        <p
                            class="text-[10px] tracking-[0.16em] text-ink-300 uppercase"
                        >
                            {{ t('teller.floatOnHand') }}
                        </p>
                        <MoneyText
                            :value="float?.current_balance ?? '0'"
                            class="text-base font-semibold"
                            :class="active ? 'text-white' : 'text-ink-300'"
                        />
                    </div>
                    <StateChip :status="float?.status ?? 'CLOSED'" dark />
                    <div
                        class="hidden items-center rounded-counter border border-ink-700 p-0.5 text-[10px] font-bold sm:flex"
                    >
                        <button
                            type="button"
                            class="rounded px-2 py-1"
                            :class="
                                lang === 'en'
                                    ? 'bg-seal text-ink-950'
                                    : 'text-ink-300'
                            "
                            @click="setLang('en')"
                        >
                            EN
                        </button>
                        <button
                            type="button"
                            class="rounded px-2 py-1"
                            :class="
                                lang === 'mm'
                                    ? 'bg-seal text-ink-950'
                                    : 'text-ink-300'
                            "
                            @click="setLang('mm')"
                        >
                            မြန်မာ
                        </button>
                    </div>
                    <button
                        type="button"
                        class="rounded-counter border border-ink-700 px-2.5 py-1.5 text-xs text-ink-300 transition hover:border-ink-300 hover:text-white"
                        @click="signOut"
                    >
                        {{ t('common.signOut') }}
                    </button>
                </div>
            </div>
        </header>

        <div
            v-if="!active"
            class="border-b border-held/30 bg-held/10 px-4 py-2.5 text-center text-sm text-held"
        >
            <template v-if="float?.status === 'PENDING_RECEIPT'">
                {{ t('teller.pendingReceipt') }}
                <Link
                    href="/teller/float"
                    class="ml-1 font-semibold underline underline-offset-2"
                    >{{ t('teller.receiveFloat') }}</Link
                >
            </template>
            <template v-else-if="float?.status === 'PENDING_RECONCILIATION'">
                {{ t('teller.pendingReconciliation') }}
            </template>
            <template v-else>
                {{ t('teller.noActiveFloat') }}
            </template>
        </div>

        <div class="mx-auto flex max-w-7xl gap-6 px-4 py-6">
            <nav class="hidden w-52 shrink-0 lg:block">
                <ul class="space-y-0.5">
                    <li v-for="item in nav" :key="item.href">
                        <Link
                            :href="item.href"
                            :headers="authHeaders()"
                            class="flex items-center gap-3 rounded-counter px-3 py-2.5 text-sm transition"
                            :class="
                                $page.url.startsWith(item.href) &&
                                (item.href !== '/teller' ||
                                    $page.url === '/teller')
                                    ? 'bg-ink-900 font-semibold text-white'
                                    : 'text-ink-800 hover:bg-ink-100'
                            "
                        >
                            <span class="font-mono text-xs opacity-60">{{
                                item.icon
                            }}</span>
                            {{ navLabel(item) }}
                        </Link>
                    </li>
                </ul>
            </nav>

            <main class="min-w-0 flex-1">
                <slot />
            </main>
        </div>

        <nav
            class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-6 border-t border-paper-edge bg-white lg:hidden"
        >
            <Link
                v-for="item in nav"
                :key="item.href"
                :href="item.href"
                :headers="authHeaders()"
                class="flex flex-col items-center gap-0.5 py-2 text-[10px] font-medium text-ink-800"
            >
                <span class="font-mono text-sm">{{ item.icon }}</span
                >{{ navLabel(item) }}
            </Link>
        </nav>
    </div>
</template>
