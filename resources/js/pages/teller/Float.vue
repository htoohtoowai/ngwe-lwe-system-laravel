<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import DenominationDrawer from '@/components/teller/DenominationDrawer.vue';
import MoneyText from '@/components/teller/MoneyText.vue';
import PinSeal from '@/components/teller/PinSeal.vue';
import StateChip from '@/components/teller/StateChip.vue';
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

type TellerFloat = {
    id: number;
    status: string;
    current_balance: string;
} | null;

const props = defineProps<{
    float: TellerFloat;
    notes: number[];
    issued: Record<number, number>;
    onHand: Record<number, number>;
}>();

const counted = ref<Record<number, number>>({});
const returning = ref<Record<number, number>>({});
const pinOpen = ref(false);
const pinBusy = ref(false);
const pinError = ref<string | null>(null);
const intent = ref<'receive' | 'return'>('receive');
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
const issuedTotal = computed(() =>
    props.notes.reduce((s, n) => s + n * (props.issued[n] ?? 0), 0),
);
const countMatches = computed(() =>
    props.notes.every(
        (n) => (counted.value[n] ?? 0) === (props.issued[n] ?? 0),
    ),
);
const returnTotal = computed(() =>
    props.notes.reduce((s, n) => s + n * (returning.value[n] ?? 0), 0),
);
const expectedReturn = computed(() =>
    Number(props.float?.current_balance ?? 0),
);
const returnMatches = computed(
    () => returnTotal.value === expectedReturn.value,
);
const { t } = useLocale();

function open(kind: 'receive' | 'return') {
    intent.value = kind;
    pinError.value = null;
    pinOpen.value = true;
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
    const token = readStoredToken();

    return token ? { Authorization: `Bearer ${token}` } : {};
}

const refreshFloatPage = () =>
    router.reload({
        only: ['float', 'issued', 'onHand'],
        headers: authHeaders(),
    });

async function confirm(pin: string) {
    if (!props.float) {
        return;
    }

    pinBusy.value = true;
    pinError.value = null;

    const url =
        intent.value === 'receive'
            ? `/api/cash-floats/${props.float.id}/activate`
            : `/api/cash-floats/${props.float.id}/initiate-return`;
    const data =
        intent.value === 'receive'
            ? { pin, verified_denominations: counted.value }
            : { pin, denominations: returning.value };

    try {
        await apiRequest(url, {
            method: 'POST',
            token: readStoredToken(),
            body: data,
        });
        pinOpen.value = false;
        refreshFloatPage();
    } catch (error) {
        pinError.value = firstError(error);
    } finally {
        pinBusy.value = false;
    }
}

onMounted(() => {
    const echo = createNgweLweEcho(readStoredToken());
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
        <header class="mb-5 flex items-start justify-between">
            <div>
                <h1 class="font-display text-2xl font-semibold tracking-tight">
                    {{ t('teller.float') }}
                </h1>
                <p class="mt-1 text-sm text-ink-700/70">
                    {{ t('teller.floatDescription') }}
                </p>
            </div>
            <StateChip :status="status" />
        </header>

        <div
            v-if="!float || status === 'CLOSED'"
            class="rounded-counter border border-dashed border-paper-edge bg-white px-6 py-16 text-center"
        >
            <p class="font-display text-lg font-semibold">
                {{ t('teller.noFloat') }}
            </p>
            <p class="mx-auto mt-1.5 max-w-sm text-sm text-ink-700/70">
                {{ t('teller.askCashier') }}
            </p>
        </div>

        <div
            v-else-if="status === 'PENDING_RECEIPT'"
            class="grid gap-6 lg:grid-cols-[1fr_20rem]"
        >
            <div class="space-y-4">
                <div
                    class="rounded-counter border border-held/30 bg-held/5 px-4 py-3 text-sm text-held"
                >
                    {{ t('teller.countIssued') }}
                </div>
                <DenominationDrawer
                    v-model="counted"
                    :notes="notes"
                    :target="issuedTotal"
                    :expected="issued"
                    :label="t('component.notesCounted')"
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
                    <div class="flex justify-between">
                        <dt class="text-ink-300">
                            {{ t('teller.youCounted') }}
                        </dt>
                        <dd>
                            <MoneyText
                                :value="
                                    notes.reduce(
                                        (s, n) => s + n * (counted[n] ?? 0),
                                        0,
                                    )
                                "
                            />
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="!countMatches"
                    class="mt-3 text-xs leading-relaxed text-held"
                >
                    {{ t('teller.countMatch') }}
                </p>
                <button
                    type="button"
                    :disabled="!countMatches"
                    @click="open('receive')"
                    class="mt-5 w-full rounded-counter bg-seal py-3 text-sm font-semibold text-ink-950 transition hover:brightness-110 disabled:opacity-35"
                >
                    {{ t('teller.receiveFloatPin') }}
                </button>
            </aside>
        </div>

        <div
            v-else-if="status === 'ACTIVE'"
            class="grid gap-6 lg:grid-cols-[1fr_20rem]"
        >
            <DenominationDrawer
                v-model="returning"
                :notes="notes"
                :target="expectedReturn"
                :stock="onHand"
                :label="t('component.notesCounted')"
            />
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
                        <dt class="text-ink-300">
                            {{ t('teller.systemOnHand') }}
                        </dt>
                        <dd><MoneyText :value="expectedReturn" /></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-ink-300">
                            {{ t('teller.youCounted') }}
                        </dt>
                        <dd><MoneyText :value="returnTotal" /></dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs leading-relaxed text-ink-300">
                    {{ t('teller.returnCloses') }}
                </p>
                <button
                    type="button"
                    :disabled="!returnMatches"
                    @click="open('return')"
                    class="mt-5 w-full rounded-counter border border-seal py-3 text-sm font-semibold text-seal transition hover:bg-seal hover:text-ink-950 disabled:opacity-35"
                >
                    {{ t('teller.handBackCashier') }}
                </button>
            </aside>
        </div>

        <div
            v-else
            class="rounded-counter border border-paper-edge bg-white px-6 py-16 text-center"
        >
            <p class="font-display text-lg font-semibold">
                {{ t('teller.waitingCashier') }}
            </p>
            <p class="mx-auto mt-1.5 max-w-sm text-sm text-ink-700/70">
                {{ t('teller.waitingCashier') }}
            </p>
        </div>

        <PinSeal
            :open="pinOpen"
            :busy="pinBusy"
            :error="pinError"
            :title="
                intent === 'receive'
                    ? t('teller.confirmCount')
                    : t('teller.confirmReturn')
            "
            :detail="
                intent === 'receive'
                    ? t('teller.pinCount')
                    : t('teller.pinReturn')
            "
            @confirm="confirm"
            @close="pinOpen = false"
        />
    </BankLayout>
</template>
