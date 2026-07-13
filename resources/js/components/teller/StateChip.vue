<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  status: string
  dark?: boolean
}>()

const map: Record<string, { label: string; tone: string; darkTone: string }> = {
  ACTIVE:                   { label: 'Counter open',   tone: 'bg-credit/10 text-credit border-credit/25', darkTone: 'bg-credit/20 text-white border-credit/50' },
  PENDING_RECEIPT:          { label: 'Float to count', tone: 'bg-held/10 text-held border-held/25',       darkTone: 'bg-held/25 text-white border-held/50' },
  PENDING_RECONCILIATION:   { label: 'With cashier',   tone: 'bg-held/10 text-held border-held/25',       darkTone: 'bg-held/25 text-white border-held/50' },
  CLOSED:                   { label: 'Counter closed', tone: 'bg-ink-100 text-ink-700 border-paper-edge', darkTone: 'bg-ink-800 text-ink-300 border-ink-700' },
  PENDING_CASHIER_CONFIRM:  { label: 'Awaiting cashier', tone: 'bg-held/10 text-held border-held/25',     darkTone: 'bg-held/25 text-white border-held/50' },
  COMPLETED:                { label: 'Completed',      tone: 'bg-credit/10 text-credit border-credit/25', darkTone: 'bg-credit/20 text-white border-credit/50' },
  CANCELLED:                { label: 'Cancelled',      tone: 'bg-debit/10 text-debit border-debit/25',    darkTone: 'bg-debit/25 text-white border-debit/50' },
}

const entry = computed(() => map[props.status] ?? { label: props.status, tone: 'bg-ink-100 text-ink-700 border-paper-edge', darkTone: 'bg-ink-800 text-ink-300 border-ink-700' })
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.08em]"
    :class="dark ? entry.darkTone : entry.tone"
  >
    <span class="size-1.5 rounded-full bg-current" />
    {{ entry.label }}
  </span>
</template>
