<script setup lang="ts">
import { computed } from 'vue';
import { useLocale } from '@/lib/i18n';

const props = defineProps<{
    status: string;
    dark?: boolean;
}>();

const map: Record<string, { key: string; tone: string; darkTone: string }> = {
    ACTIVE: {
        key: 'status.active',
        tone: 'bg-credit/10 text-credit border-credit/25',
        darkTone: 'bg-credit/20 text-white border-credit/50',
    },
    PENDING_RECEIPT: {
        key: 'status.floatToCount',
        tone: 'bg-held/10 text-held border-held/25',
        darkTone: 'bg-held/25 text-white border-held/50',
    },
    PENDING_RECONCILIATION: {
        key: 'status.withCashier',
        tone: 'bg-held/10 text-held border-held/25',
        darkTone: 'bg-held/25 text-white border-held/50',
    },
    CLOSED: {
        key: 'status.counterClosed',
        tone: 'bg-ink-100 text-ink-700 border-paper-edge',
        darkTone: 'bg-ink-800 text-ink-300 border-ink-700',
    },
    PENDING_CASHIER_CONFIRM: {
        key: 'status.awaitingCashier',
        tone: 'bg-held/10 text-held border-held/25',
        darkTone: 'bg-held/25 text-white border-held/50',
    },
    COMPLETED: {
        key: 'status.completed',
        tone: 'bg-credit/10 text-credit border-credit/25',
        darkTone: 'bg-credit/20 text-white border-credit/50',
    },
    CANCELLED: {
        key: 'status.cancelled',
        tone: 'bg-debit/10 text-debit border-debit/25',
        darkTone: 'bg-debit/25 text-white border-debit/50',
    },
};

const { t } = useLocale();
const entry = computed(
    () =>
        map[props.status] ?? {
            key: props.status,
            tone: 'bg-ink-100 text-ink-700 border-paper-edge',
            darkTone: 'bg-ink-800 text-ink-300 border-ink-700',
        },
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold tracking-[0.08em] uppercase"
        :class="dark ? entry.darkTone : entry.tone"
    >
        <span class="size-1.5 rounded-full bg-current" />
        {{ map[props.status] ? t(entry.key) : entry.key }}
    </span>
</template>
