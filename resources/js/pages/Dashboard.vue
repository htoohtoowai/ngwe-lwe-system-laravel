<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import LineChart from '@/components/bank/LineChart.vue'
import BankLayout from '@/layouts/BankLayout.vue'
import { readStoredToken } from '@/lib/auth-token'
import {
  createNgweLweEcho,
  disconnectNgweLweEcho,
  subscribeToRoleChannel,
  subscribeToUserChannel,
} from '@/lib/echo'
import type { RealtimeHandlers } from '@/lib/echo'

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
  role: 'owner' | 'cashier' | 'employee'
  announcement?: string | null
  notificationCount?: number
  chart: { labels: string[]; cashIn: number[]; cashOut: number[] }
  range: '1y' | '6m' | '1m' | '1w'
  companies: string[]
  accounts: { id: number; company: string; name: string; number?: string; balance: string }[]
  floats: { id: number; holder: string; status: string; amount: string; issued_at: string }[]
  recent: { id: number; type: string; label: string; amount: string; direction: 'in' | 'out'; time: string }[]
}>()

const RANGES = [
  { key: '1y', label: '1 Year' },
  { key: '6m', label: '6 Months' },
  { key: '1m', label: '1 Month' },
  { key: '1w', label: '1 Week' },
] as const

const companyTab = ref<string>('All')
const page = usePage<{
  auth?: {
    user?: {
      id: number
    } | null
  }
}>()
let unsubscribeRole: (() => void) | null = null
let unsubscribeUser: (() => void) | null = null
const tabs = computed(() => ['All', ...props.companies])
const visibleAccounts = computed(() =>
  companyTab.value === 'All' ? props.accounts : props.accounts.filter(a => a.company === companyTab.value),
)
const accountHref = computed(() => props.role === 'employee' ? '/dashboard' : '/')
const transactionHref = computed(() => props.role === 'employee' ? '/employee' : '/')
const floatHref = computed(() => props.role === 'employee' ? '/employee/float' : '/')

function authHeaders(): Record<string, string> {
  const token = readStoredToken()

  return token ? { Authorization: `Bearer ${token}` } : {}
}

function setRange(r: string) {
  router.reload({ only: ['chart', 'range'], data: { range: r }, headers: authHeaders() })
}

const mmk = (v: string | number) => Number(v).toLocaleString()
const refreshRealtimeData = () => router.reload({
  only: ['recent', 'floats', 'notificationCount'],
  headers: authHeaders(),
})

onMounted(() => {
  const echo = createNgweLweEcho(readStoredToken())

  if (!echo) {
    return
  }

  const handlers: RealtimeHandlers = {
    new_transaction: refreshRealtimeData,
    cash_in_pending: refreshRealtimeData,
    float_update: refreshRealtimeData,
    float_status_changed: refreshRealtimeData,
  }

  unsubscribeRole = subscribeToRoleChannel(echo, props.role, handlers)

  if (page.props.auth?.user?.id) {
    unsubscribeUser = subscribeToUserChannel(echo, page.props.auth.user.id, handlers)
  }
})

onBeforeUnmount(() => {
  unsubscribeRole?.()
  unsubscribeUser?.()
  disconnectNgweLweEcho()
})
</script>

<template>
  <BankLayout :role="role" :announcement="announcement" :notification-count="notificationCount">
    <p class="text-[13px] font-semibold text-slate">Overview</p>

    <!-- ===== Cash In vs. Cash Out ===== -->
    <section class="mt-3 rounded-2xl border border-line bg-card p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-center gap-3">
        <h2 class="text-lg font-bold tracking-tight sm:text-xl">Cash In vs. Cash Out</h2>
        <a href="/reports/daily/pdf" target="_blank"
           class="ml-auto flex items-center gap-1.5 rounded-pill border border-line px-3.5 py-1.5 text-xs font-bold text-slate transition hover:border-brand hover:text-brand">
          ↓ Download PDF
        </a>
      </div>

      <div class="mt-4 flex flex-wrap items-center gap-3">
        <!-- range tabs -->
        <div class="ml-auto flex items-center gap-1 overflow-x-auto">
          <button v-for="r in RANGES" :key="r.key" type="button" @click="setRange(r.key)"
                  class="whitespace-nowrap rounded-pill px-3.5 py-1.5 text-xs font-bold transition"
                  :class="range === r.key ? 'bg-brand text-white' : 'text-slate hover:bg-mist'">
            {{ r.label }}
          </button>
        </div>
      </div>

      <div class="mt-4">
        <LineChart
          :labels="chart.labels"
          :series="[
            { name: 'Cash In',  color: 'var(--color-balance)', points: chart.cashIn },
            { name: 'Cash Out', color: 'var(--color-brand)',   points: chart.cashOut },
          ]"
        />
      </div>
    </section>

    <!-- ===== Accounts ===== -->
    <section class="mt-7">
      <div class="flex items-center justify-between">
        <h2 class="text-base font-bold sm:text-lg">Accounts</h2>
        <Link :href="accountHref" :headers="authHeaders()" class="text-xs font-bold text-slate underline underline-offset-2 transition hover:text-brand">
          Go to Accounts
        </Link>
      </div>

      <!-- company tabs -->
      <div class="mt-3 flex gap-1 overflow-x-auto pb-1">
        <button v-for="tab in tabs" :key="tab" type="button" @click="companyTab = tab"
                class="whitespace-nowrap rounded-pill px-3.5 py-1.5 text-xs font-bold transition"
                :class="companyTab === tab ? 'bg-card text-brand shadow-sm ring-1 ring-line' : 'text-slate hover:bg-mist'">
          {{ tab }}
        </button>
      </div>

      <!-- horizontal rail with scroll snap, exactly like the reference cards -->
      <div class="mt-3 flex snap-x snap-mandatory gap-3 overflow-x-auto pb-2">
        <article v-for="a in visibleAccounts" :key="a.id"
                 class="w-64 shrink-0 snap-start rounded-2xl border border-line bg-card p-4 shadow-sm">
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-bold leading-tight">{{ a.name }}</p>
            <span class="shrink-0 rounded-pill bg-brand px-2.5 py-0.5 text-[10px] font-bold uppercase text-white">
              {{ a.company }}
            </span>
          </div>
          <p v-if="a.number" class="money mt-1 text-[11px] text-slate">{{ a.number }}</p>
          <p class="money mt-3 text-xl font-bold">
            {{ mmk(a.balance) }} <span class="text-[11px] font-semibold text-slate">MMK</span>
          </p>
        </article>
        <p v-if="!visibleAccounts.length" class="py-8 text-sm text-slate">No accounts under this company yet.</p>
      </div>
    </section>

    <!-- ===== Floats as dark cards ===== -->
    <section class="mt-7">
      <div class="flex items-center justify-between">
        <h2 class="text-base font-bold sm:text-lg">{{ role === 'employee' ? 'My Float' : 'Active Floats' }}</h2>
        <Link :href="floatHref" :headers="authHeaders()" class="text-xs font-bold text-slate underline underline-offset-2 transition hover:text-brand">
          Go to Floats
        </Link>
      </div>

      <div class="mt-3 flex snap-x snap-mandatory gap-3 overflow-x-auto pb-2">
        <article v-for="f in floats" :key="f.id"
                 class="relative w-72 shrink-0 snap-start overflow-hidden rounded-2xl bg-ink p-4 text-white shadow-md">
          <!-- card sheen -->
          <div class="pointer-events-none absolute -right-10 -top-14 size-40 rounded-full bg-white/10" />
          <div class="flex items-center justify-between">
            <span class="grid size-7 place-items-center rounded-full bg-brand text-[10px] font-bold">NL</span>
            <span class="rounded-pill px-2.5 py-0.5 text-[10px] font-bold uppercase"
                  :class="f.status === 'ACTIVE' ? 'bg-balance/90' : 'bg-white/20'">
              {{ f.status === 'ACTIVE' ? 'Active' : f.status.replaceAll('_', ' ').toLowerCase() }}
            </span>
          </div>
          <p class="money mt-5 text-lg font-bold tracking-wider">FLOAT •• {{ String(f.id).padStart(4, '0') }}</p>
          <div class="mt-4 flex items-end justify-between">
            <div>
              <p class="text-[10px] uppercase tracking-wide text-white/60">Holder</p>
              <p class="text-sm font-semibold">{{ f.holder }}</p>
            </div>
            <div class="text-right">
              <p class="text-[10px] uppercase tracking-wide text-white/60">On hand</p>
              <p class="money text-base font-bold">{{ mmk(f.amount) }} <span class="text-[10px]">MMK</span></p>
            </div>
          </div>
        </article>
        <p v-if="!floats.length" class="py-8 text-sm text-slate">No active floats right now.</p>
      </div>
    </section>

    <!-- ===== Recent history ===== -->
    <section class="mt-7 rounded-2xl border border-line bg-card shadow-sm">
      <div class="flex items-center justify-between px-4 py-3.5 sm:px-6">
        <h2 class="text-base font-bold sm:text-lg">Recent History</h2>
        <Link :href="transactionHref" :headers="authHeaders()" class="text-xs font-bold text-slate underline underline-offset-2 transition hover:text-brand">
          See All
        </Link>
      </div>
      <ul class="divide-y divide-line">
        <li v-for="txn in recent" :key="txn.id">
          <Link :href="transactionHref" :headers="authHeaders()"
                class="flex items-center gap-3 px-4 py-3 transition hover:bg-mist/50 sm:px-6">
            <span class="grid size-9 shrink-0 place-items-center rounded-full text-sm"
                  :class="txn.direction === 'in' ? 'bg-balance/10 text-balance' : 'bg-brand-soft text-brand'">
              {{ txn.direction === 'in' ? '↓' : '↑' }}
            </span>
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-semibold">{{ txn.label }}</p>
              <p class="text-[11px] text-slate">{{ txn.type }} · {{ txn.time }}</p>
            </div>
            <p class="money shrink-0 text-sm font-bold"
               :class="txn.direction === 'in' ? 'text-balance' : 'text-brand'">
              {{ txn.direction === 'in' ? '+' : '−' }}{{ mmk(txn.amount) }} <span class="text-[10px] font-semibold text-slate">MMK</span>
            </p>
          </Link>
        </li>
        <li v-if="!recent.length" class="px-6 py-10 text-center text-sm text-slate">
          No transactions yet today.
        </li>
      </ul>
    </section>
  </BankLayout>
</template>
