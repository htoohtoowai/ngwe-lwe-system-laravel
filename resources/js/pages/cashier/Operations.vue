<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import DenomDrawer from '@/components/bank/DenomDrawer.vue'
import PinSeal from '@/components/teller/PinSeal.vue'
import BankLayout from '@/layouts/BankLayout.vue'
import { apiRequest } from '@/lib/api'
import { readStoredToken } from '@/lib/auth-token'
import {
  createNgweLweEcho,
  disconnectNgweLweEcho,
  subscribeToRoleChannel,
} from '@/lib/echo'
import type { RealtimeHandlers } from '@/lib/echo'

type Denoms = Record<number, number>
type Teller = { id: number; name: string }
type FloatRow = {
  id: number
  employee_id: number
  employee_name: string
  status: string
  total_amount: string
  current_balance: string
  closing_total: string
  return_denominations_json: Denoms | null
  created_at: string | null
  received_at: string | null
  closed_at: string | null
  denominations: { denomination: number; quantity: number }[]
}
type VaultLog = {
  id: number
  type: string
  float_id: number | null
  denomination: number
  quantity: number
  note: string | null
  performed_by: string | null
  created_at: string | null
}
type RecentTransaction = {
  id: number
  type: string
  amount: string
  fee: string
  status: string
  customer: string | null
  teller: string
  created_at: string | null
}
type PendingCashIn = {
  id: number
  amount: string
  customer_name: string | null
  teller: string
  received_denominations: Denoms
  handoff_denominations: Denoms
  change_denominations: Denoms
  change_given: string
  created_at: string | null
}

const props = defineProps<{
  role: 'cashier'
  announcement?: string | null
  notificationCount?: number
  notes: number[]
  mainVault: Record<string, number>
  availableVault: Record<string, number>
  vaultTotal: number
  vaultLogs: VaultLog[]
  floats: FloatRow[]
  tellers: Teller[]
  transactions: RecentTransaction[]
  pendingCashIns: PendingCashIn[]
}>()

const vaultEntryOpen = ref(false)
const vaultEntryType = ref<'vault_in' | 'adjustment'>('vault_in')
const vaultEntryDenoms = ref<Denoms>({})
const issueEmployeeId = ref<number | null>(null)
const issueDenoms = ref<Denoms>({})
const issueNote = ref('')
const busy = ref(false)
const error = ref('')
const notice = ref('')
const returnFloat = ref<FloatRow | null>(null)
const returnPinOpen = ref(false)
const returnPinBusy = ref(false)
const returnPinError = ref<string | null>(null)
const transactionSearch = ref('')
const transactionType = ref('all')
const transactionDateFrom = ref('')
const transactionDateTo = ref('')
let unsubscribeRole: (() => void) | null = null

const availableByNumber = computed(() => {
  const result: Denoms = {}
  props.notes.forEach(note => {
 result[note] = Number(props.availableVault[String(note)] ?? 0) 
})

  return result
})
const vaultEntryTotal = computed(() => denominationTotal(vaultEntryDenoms.value))
const issueTotal = computed(() => denominationTotal(issueDenoms.value))
const issueShortages = computed(() => props.notes.filter(note => Number(issueDenoms.value[note] ?? 0) > Number(availableByNumber.value[note] ?? 0)))
const canIssue = computed(() => issueEmployeeId.value !== null && issueTotal.value > 0 && issueShortages.value.length === 0)
const returnDenoms = computed(() => returnFloat.value?.return_denominations_json ?? {})
const returnTotal = computed(() => denominationTotal(returnDenoms.value))
const filteredTransactions = computed(() => {
  const query = transactionSearch.value.trim().toLowerCase()

  return props.transactions.filter(transaction => {
    const matchesType = transactionType.value === 'all' || transaction.type === transactionType.value
    const haystack = [String(transaction.id), transaction.type, transaction.status, transaction.customer ?? ''].join(' ').toLowerCase()
    const day = transaction.created_at ? transaction.created_at.slice(0, 10) : ''
    const matchesFrom = !transactionDateFrom.value || day >= transactionDateFrom.value
    const matchesTo = !transactionDateTo.value || day <= transactionDateTo.value

    return matchesType && matchesFrom && matchesTo && (!query || haystack.includes(query))
  })
})

function denominationTotal(denoms: Denoms): number {
  return Object.entries(denoms).reduce((sum, [denomination, quantity]) => sum + Number(denomination) * Number(quantity), 0)
}

function formatMoney(value: string | number): string {
  return Number(value ?? 0).toLocaleString()
}

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleString() : '—'
}

function floatReturnTotal(float: FloatRow): number {
  return denominationTotal(float.return_denominations_json ?? {})
}

function denominationSummary(denoms: Denoms): string {
  return Object.entries(denoms ?? {})
    .filter(([, quantity]) => Number(quantity) > 0)
    .sort(([left], [right]) => Number(right) - Number(left))
    .map(([denomination, quantity]) => formatMoney(denomination) + ' × ' + quantity)
    .join(', ') || '—'
}

function authHeaders(): Record<string, string> {
  const token = readStoredToken()

  return token ? { Authorization: 'Bearer ' + token } : {}
}

function firstError(value: unknown): string {
  const data = value as { message?: string; errors?: Record<string, string[]> }
  const validation = data.errors ? Object.values(data.errors)[0]?.[0] : null

  return validation ?? data.message ?? 'Request failed.'
}

function reload() {
  router.reload({
    only: ['mainVault', 'availableVault', 'vaultTotal', 'vaultLogs', 'floats', 'transactions', 'pendingCashIns', 'notificationCount'],
    headers: authHeaders(),
  })
}

const refreshCashierData = () => reload()

onMounted(() => {
  const echo = createNgweLweEcho(readStoredToken())

  if (!echo) {
return
}

  const handlers: RealtimeHandlers = {
    new_transaction: refreshCashierData,
    cash_in_pending: refreshCashierData,
    float_update: refreshCashierData,
    float_status_changed: refreshCashierData,
  }

  unsubscribeRole = subscribeToRoleChannel(echo, 'cashier', handlers)
})

onBeforeUnmount(() => {
  unsubscribeRole?.()
  disconnectNgweLweEcho()
})

function openVaultEntry(entryType: 'vault_in' | 'adjustment') {
  vaultEntryType.value = entryType
  vaultEntryDenoms.value = {}
  vaultEntryOpen.value = true
}

async function recordVaultEntry() {
  if (vaultEntryTotal.value <= 0) {
return
}

  busy.value = true
  error.value = ''
  notice.value = ''

  try {
    await apiRequest('/api/vault/entries', {
      method: 'POST',
      token: readStoredToken(),
      body: { entry_type: vaultEntryType.value, denominations: vaultEntryDenoms.value, note: vaultEntryType.value === 'vault_in' ? 'Cash received into main vault.' : 'Cashier vault adjustment.' },
    })
    vaultEntryDenoms.value = {}
    vaultEntryOpen.value = false
    notice.value = 'Cash received into the main vault.'
    reload()
  } catch (exception) {
    error.value = firstError(exception)
  } finally {
    busy.value = false
  }
}

async function issueFloat() {
  if (!canIssue.value || issueEmployeeId.value === null) {
return
}

  busy.value = true
  error.value = ''
  notice.value = ''

  try {
    await apiRequest('/api/cash-floats', {
      method: 'POST',
      token: readStoredToken(),
      body: {
        employee_id: issueEmployeeId.value,
        denominations: issueDenoms.value,
        note: issueNote.value || null,
      },
    })
    issueDenoms.value = {}
    issueNote.value = ''
    notice.value = 'Teller float issued. Teller must count and receive it before use.'
    reload()
  } catch (exception) {
    error.value = firstError(exception)
  } finally {
    busy.value = false
  }
}

function openReturn(float: FloatRow) {
  returnFloat.value = float
  returnPinError.value = null
  returnPinOpen.value = true
}

async function confirmReturn(pin: string) {
  if (!returnFloat.value) {
return
}

  returnPinBusy.value = true
  returnPinError.value = null

  try {
    await apiRequest('/api/cash-floats/' + returnFloat.value.id + '/confirm-return', {
      method: 'POST',
      token: readStoredToken(),
      body: { closing_total: returnTotal.value, pin },
    })
    returnPinOpen.value = false
    returnFloat.value = null
    notice.value = 'Teller float return confirmed and added back to the main vault.'
    reload()
  } catch (exception) {
    returnPinError.value = firstError(exception)
  } finally {
    returnPinBusy.value = false
  }
}

function statusLabel(status: string): string {
  return status.replaceAll('_', ' ')
}
</script>

<template>
  <BankLayout :role="role" :announcement="announcement" :notification-count="notificationCount">
    <header class="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-black uppercase tracking-[0.18em] text-brand">Cashier operations</p>
        <h1 class="mt-1 text-2xl font-black tracking-tight sm:text-3xl">Main vault & Teller floats</h1>
        <p class="mt-1 max-w-2xl text-sm text-slate">Issue morning cash, review Cash In handoffs, and reconcile every Teller at closing.</p>
      </div>
      <div class="rounded-2xl border border-line bg-card px-4 py-3 text-right shadow-sm">
        <p class="text-[10px] font-black uppercase tracking-wide text-slate">Main vault</p>
        <p class="money mt-1 text-2xl font-black">{{ formatMoney(vaultTotal) }} <span class="text-xs text-slate">MMK</span></p>
      </div>
    </header>

    <div v-if="error" class="mb-4 rounded-xl border border-brand/20 bg-brand-soft px-4 py-3 text-sm font-semibold text-brand" role="alert">{{ error }}</div>
    <div v-if="notice" class="mb-4 rounded-xl border border-balance/25 bg-balance/5 px-4 py-3 text-sm font-semibold text-balance" role="status">{{ notice }}</div>

    <section class="mb-6 overflow-hidden rounded-2xl border border-brand/25 bg-card shadow-sm">
      <header class="flex flex-wrap items-center justify-between gap-3 border-b border-brand/15 bg-brand-soft/45 px-4 py-4 sm:px-6">
        <div>
          <div class="flex items-center gap-2">
            <span class="grid size-8 place-items-center rounded-full bg-brand text-xs font-black text-white">{{ pendingCashIns.length }}</span>
            <h2 class="text-lg font-black">Teller entry notifications</h2>
          </div>
          <p class="mt-1 text-xs text-slate">New Teller Cash In entries stay here until the Cashier reviews the exact handoff.</p>
        </div>
        <span class="rounded-pill bg-brand px-3 py-1.5 text-xs font-black text-white">{{ pendingCashIns.length ? 'Action required' : 'All clear' }}</span>
      </header>
      <div v-if="pendingCashIns.length" class="overflow-x-auto">
        <table class="min-w-[980px] w-full text-left text-sm">
          <thead class="border-b border-line bg-mist/45 text-[11px] uppercase tracking-wide text-slate">
            <tr>
              <th class="px-4 py-3 sm:px-6">Reference</th>
              <th class="px-4 py-3">Teller</th>
              <th class="px-4 py-3 text-right">Cash In</th>
              <th class="px-4 py-3">Customer received</th>
              <th class="px-4 py-3">Cashier handoff</th>
              <th class="px-4 py-3">Change</th>
              <th class="px-4 py-3">Time</th>
              <th class="px-4 py-3 sm:px-6">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="entry in pendingCashIns" :key="entry.id" class="hover:bg-mist/35">
              <td class="px-4 py-3 font-black sm:px-6">#{{ entry.id }}<p class="text-xs font-normal text-slate">{{ entry.customer_name || 'Customer' }}</p></td>
              <td class="px-4 py-3 font-semibold">{{ entry.teller }}</td>
              <td class="money px-4 py-3 text-right font-black">{{ formatMoney(entry.amount) }} MMK</td>
              <td class="px-4 py-3 text-xs text-slate">{{ denominationSummary(entry.received_denominations) }}</td>
              <td class="px-4 py-3 text-xs font-bold">{{ denominationSummary(entry.handoff_denominations) }}</td>
              <td class="px-4 py-3 text-xs text-held">{{ entry.change_given === '0.00' ? '—' : formatMoney(entry.change_given) + ' MMK · ' + denominationSummary(entry.change_denominations) }}</td>
              <td class="px-4 py-3 text-xs text-slate">{{ formatDate(entry.created_at) }}</td>
              <td class="px-4 py-3 sm:px-6">
                <Link href="/dashboard" :headers="authHeaders()" class="whitespace-nowrap rounded-pill bg-ink px-3 py-2 text-xs font-bold text-white hover:bg-brand">Open review</Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="px-6 py-8 text-center text-sm font-semibold text-balance">No Teller entry is waiting for Cashier action.</p>
    </section>

    <section class="rounded-2xl border border-line bg-card shadow-sm">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-4 sm:px-6">
        <div>
          <h2 class="text-lg font-black">Main vault denomination stock</h2>
          <p class="mt-1 text-xs text-slate">Available stock excludes cash already reserved for pending Teller floats.</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="rounded-pill bg-ink px-4 py-2 text-xs font-bold text-white transition hover:bg-brand" @click="openVaultEntry('vault_in')">Record cash received</button>
          <button type="button" class="rounded-pill border border-line px-4 py-2 text-xs font-bold text-slate transition hover:border-brand hover:text-brand" @click="openVaultEntry('adjustment')">Record adjustment</button>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-4 sm:p-6">
        <div v-for="note in notes" :key="note" class="rounded-xl border border-line bg-mist/45 px-3 py-3">
          <p class="money text-lg font-black">{{ formatMoney(note) }}</p>
          <p class="mt-1 text-xs text-slate">{{ availableByNumber[note] ?? 0 }} available · {{ formatMoney((availableByNumber[note] ?? 0) * note) }} MMK</p>
        </div>
      </div>
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
      <section class="rounded-2xl border border-line bg-card shadow-sm">
        <header class="border-b border-line px-4 py-4 sm:px-6">
          <p class="text-xs font-black uppercase tracking-wide text-brand">Morning issue</p>
          <h2 class="mt-1 text-lg font-black">Issue Teller float</h2>
          <p class="mt-1 text-xs text-slate">Select one Teller and count notes from the available main vault stock.</p>
        </header>
        <div class="space-y-4 p-4 sm:p-6">
          <label class="block text-sm font-bold" for="cashier-teller">Teller</label>
          <select id="cashier-teller" v-model="issueEmployeeId" class="h-11 w-full rounded-xl border border-line bg-mist px-3 text-sm font-semibold outline-none focus:border-brand focus:ring-2 focus:ring-brand/20">
            <option :value="null">Choose a Teller</option>
            <option v-for="teller in tellers" :key="teller.id" :value="teller.id">{{ teller.name }}</option>
          </select>
          <DenomDrawer v-model="issueDenoms" :notes="notes" :stock="availableByNumber" compact label="Cash float notes" />
          <input v-model="issueNote" type="text" maxlength="2000" placeholder="Optional note" class="h-11 w-full rounded-xl border border-line bg-mist px-3 text-sm outline-none focus:border-brand focus:ring-2 focus:ring-brand/20" />
          <p v-if="issueShortages.length" class="rounded-xl bg-brand-soft px-3 py-2 text-xs font-semibold text-brand">Not enough stock for: {{ issueShortages.map(formatMoney).join(', ') }} MMK</p>
          <button type="button" :disabled="!canIssue || busy" class="w-full rounded-xl bg-brand px-4 py-3 text-sm font-black text-white transition hover:bg-ink disabled:cursor-not-allowed disabled:opacity-40" @click="issueFloat">
            {{ busy ? 'Issuing…' : 'Issue ' + formatMoney(issueTotal) + ' MMK to Teller' }}
          </button>
        </div>
      </section>

      <section class="rounded-2xl border border-line bg-card shadow-sm">
        <header class="border-b border-line px-4 py-4 sm:px-6">
          <p class="text-xs font-black uppercase tracking-wide text-balance">End-of-day</p>
          <h2 class="mt-1 text-lg font-black">Teller reconciliation</h2>
          <p class="mt-1 text-xs text-slate">Confirm only the denomination total physically received back.</p>
        </header>
        <div class="divide-y divide-line">
          <div v-for="float in floats" :key="float.id" class="flex flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6">
            <div class="min-w-0">
              <p class="truncate font-bold">{{ float.employee_name }} <span class="text-xs font-normal text-slate">· Float #{{ float.id }}</span></p>
              <p class="mt-1 text-xs uppercase tracking-wide text-slate">{{ statusLabel(float.status) }} · issued {{ formatMoney(float.total_amount) }} MMK</p>
              <p v-if="float.status === 'PENDING_RECONCILIATION'" class="mt-1 text-xs font-semibold text-balance">Returned: {{ formatMoney(floatReturnTotal(float)) }} MMK</p>
              <details class="mt-2 text-xs text-slate">
                <summary class="cursor-pointer font-bold text-ink">View issued notes</summary>
                <p class="mt-1">{{ denominationSummary(Object.fromEntries(float.denominations.map(line => [line.denomination, line.quantity]))) }}</p>
              </details>
            </div>
            <button v-if="float.status === 'PENDING_RECONCILIATION'" type="button" class="rounded-pill border border-ink px-3 py-2 text-xs font-bold text-ink hover:bg-ink hover:text-white" @click="openReturn(float)">Verify return</button>
          </div>
          <p v-if="!floats.length" class="px-6 py-10 text-center text-sm text-slate">No Teller floats recorded yet.</p>
        </div>
      </section>
    </section>

    <section class="mt-6 rounded-2xl border border-line bg-card shadow-sm">
      <header class="flex flex-wrap items-end justify-between gap-3 border-b border-line px-4 py-4 sm:px-6">
        <div>
          <h2 class="text-lg font-black">Teller entry history</h2>
          <p class="mt-1 text-xs text-slate">Read-only table; Teller creates the transaction and Cashier monitors the record.</p>
        </div>
        <div class="grid w-full gap-2 sm:w-auto sm:grid-cols-[16rem_auto_auto_auto]">
          <input v-model="transactionSearch" type="search" placeholder="Search reference, customer, status" class="h-10 min-w-0 flex-1 rounded-xl border border-line bg-mist px-3 text-xs outline-none focus:border-brand focus:ring-2 focus:ring-brand/20 sm:w-64" />
          <select v-model="transactionType" class="h-10 rounded-xl border border-line bg-mist px-3 text-xs font-bold outline-none focus:border-brand focus:ring-2 focus:ring-brand/20">
            <option value="all">All types</option>
            <option value="cash_in">Cash In</option>
            <option value="cash_out">Cash Out</option>
            <option value="transfer">Transfer</option>
            <option value="exchange">Exchange</option>
          </select>
          <input v-model="transactionDateFrom" type="date" aria-label="From date" class="h-10 rounded-xl border border-line bg-mist px-3 text-xs outline-none focus:border-brand focus:ring-2 focus:ring-brand/20" />
          <input v-model="transactionDateTo" type="date" aria-label="To date" class="h-10 rounded-xl border border-line bg-mist px-3 text-xs outline-none focus:border-brand focus:ring-2 focus:ring-brand/20" />
        </div>
      </header>
      <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
          <thead class="border-b border-line bg-mist/45 text-[11px] uppercase tracking-wide text-slate">
            <tr><th class="px-4 py-3 sm:px-6">Reference</th><th class="px-4 py-3">Teller</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Amount</th><th class="px-4 py-3">Fee</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 sm:px-6">Time</th></tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="transaction in filteredTransactions" :key="transaction.id">
              <td class="px-4 py-3 font-bold sm:px-6">#{{ transaction.id }}<p class="text-xs font-normal text-slate">{{ transaction.customer || 'Customer' }}</p></td>
              <td class="px-4 py-3 font-semibold">{{ transaction.teller }}</td>
              <td class="px-4 py-3">{{ transaction.type.replaceAll('_', ' ') }}</td>
              <td class="money px-4 py-3 font-bold">{{ formatMoney(transaction.amount) }} MMK</td>
              <td class="money px-4 py-3 text-xs text-slate">{{ formatMoney(transaction.fee) }} MMK</td>
              <td class="px-4 py-3 text-xs font-bold uppercase text-slate">{{ statusLabel(transaction.status) }}</td>
              <td class="px-4 py-3 text-xs text-slate sm:px-6">{{ formatDate(transaction.created_at) }}</td>
            </tr>
            <tr v-if="!filteredTransactions.length"><td colspan="7" class="px-6 py-10 text-center text-sm text-slate">No matching Teller entry.</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="mt-6 rounded-2xl border border-line bg-card shadow-sm">
      <header class="border-b border-line px-4 py-4 sm:px-6">
        <h2 class="text-lg font-black">Main vault audit log</h2>
        <p class="mt-1 text-xs text-slate">Every note movement is recorded with its operator and reason.</p>
      </header>
      <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
          <thead class="border-b border-line bg-mist/45 text-[11px] uppercase tracking-wide text-slate">
            <tr><th class="px-4 py-3 sm:px-6">Time</th><th class="px-4 py-3">Movement</th><th class="px-4 py-3">Note</th><th class="px-4 py-3">Quantity</th><th class="px-4 py-3 sm:px-6">Operator</th></tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="log in vaultLogs" :key="log.id">
              <td class="px-4 py-3 text-xs text-slate sm:px-6">{{ formatDate(log.created_at) }}</td>
              <td class="px-4 py-3 font-bold">{{ statusLabel(log.type) }}</td>
              <td class="money px-4 py-3">{{ formatMoney(log.denomination) }}</td>
              <td class="px-4 py-3">{{ log.quantity }}</td>
              <td class="px-4 py-3 text-xs text-slate sm:px-6">{{ log.performed_by || 'Cashier' }}</td>
            </tr>
            <tr v-if="!vaultLogs.length"><td colspan="5" class="px-6 py-10 text-center text-sm text-slate">No vault movements yet.</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <div v-if="vaultEntryOpen" class="fixed inset-0 z-40 grid place-items-center bg-ink/55 p-4" @click.self="vaultEntryOpen = false">
      <section class="max-h-[calc(100vh-2rem)] w-full max-w-lg overflow-y-auto rounded-2xl border border-line bg-card shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="vault-entry-title">
        <header class="flex items-start justify-between border-b border-line px-5 py-4">
          <div><h2 id="vault-entry-title" class="text-lg font-black">{{ vaultEntryType === 'vault_in' ? 'Record cash received' : 'Record vault adjustment' }}</h2><p class="mt-1 text-xs text-slate">{{ vaultEntryType === 'vault_in' ? 'Add physical cash to the shared Cashier main vault.' : 'Use only for an approved physical cash correction.' }}</p></div>
          <button type="button" class="text-xl text-slate" @click="vaultEntryOpen = false">×</button>
        </header>
        <div class="p-4 sm:p-5">
          <DenomDrawer v-model="vaultEntryDenoms" :notes="notes" label="Cash received" />
        </div>
        <footer class="flex justify-end gap-2 border-t border-line px-5 py-4">
          <button type="button" class="rounded-pill border border-line px-4 py-2 text-xs font-bold text-slate" @click="vaultEntryOpen = false">Cancel</button>
          <button type="button" :disabled="vaultEntryTotal <= 0 || busy" class="rounded-pill bg-ink px-4 py-2 text-xs font-bold text-white disabled:opacity-40" @click="recordVaultEntry">{{ busy ? 'Saving…' : 'Save ' + formatMoney(vaultEntryTotal) + ' MMK' }}</button>
        </footer>
      </section>
    </div>

    <PinSeal
      :open="returnPinOpen"
      title="Confirm Teller return"
      detail="Enter your Cashier PIN to add the returned denominations back to the main vault."
      :busy="returnPinBusy"
      :error="returnPinError"
      @close="returnPinOpen = false"
      @confirm="confirmReturn"
    />
  </BankLayout>
</template>
