<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import EmployeeLayout from '@/layouts/EmployeeLayout.vue'
import MoneyText from '@/components/teller/MoneyText.vue'
import StateChip from '@/components/teller/StateChip.vue'
import { readStoredToken } from '@/lib/auth-token'

type TellerFloat = { id: number; status: string; current_balance: string; issued_amount: string } | null

const props = defineProps<{
  float: TellerFloat
  denominations: { note: number; quantity: number }[]
  today: { cash_in: string; cash_out: string; transfer: string; exchange: string; count: number }
  recent: Array<{ id: number; type: string; amount: string; fee_amount: string; status: string }>
}>()

const actions = [
  { label: 'Cash In', href: '/employee/cash-in', note: 'Customer hands cash, account is debited' },
  { label: 'Cash Out', href: '/employee/cash-out', note: 'Pay cash from your float, account credited' },
  { label: 'Transfer', href: '/employee/transfer', note: 'Move value between accounts' },
  { label: 'Exchange', href: '/employee/exchange', note: "MMK / THB at today's rate" },
]
const locked = computed(() => props.float?.status !== 'ACTIVE')

function authHeaders(): Record<string, string> {
  const token = readStoredToken()

  return token ? { Authorization: `Bearer ${token}` } : {}
}
</script>

<template>
  <EmployeeLayout :float="float">
    <div class="grid gap-6 lg:grid-cols-3">
      <section class="rounded-counter border border-paper-edge bg-white lg:col-span-2">
        <header class="flex items-center justify-between border-b border-paper-edge px-5 py-4">
          <div>
            <h1 class="font-display text-lg font-semibold">Your till</h1>
            <p class="text-xs text-ink-700/65">Float #{{ float?.id ?? '-' }}</p>
          </div>
          <StateChip :status="float?.status ?? 'CLOSED'" />
        </header>

        <div class="grid grid-cols-2 divide-x divide-paper-edge border-b border-paper-edge sm:grid-cols-3">
          <div class="px-5 py-4">
            <p class="field-label">Issued</p>
            <MoneyText :value="float?.issued_amount ?? 0" class="mt-1 block text-xl font-semibold" />
          </div>
          <div class="px-5 py-4">
            <p class="field-label">On hand now</p>
            <MoneyText :value="float?.current_balance ?? 0" class="mt-1 block text-xl font-semibold" />
          </div>
          <div class="px-5 py-4">
            <p class="field-label">Paid out today</p>
            <MoneyText
              :value="Number(float?.issued_amount ?? 0) - Number(float?.current_balance ?? 0)"
              class="mt-1 block text-xl font-semibold text-debit" />
          </div>
        </div>

        <ul class="grid grid-cols-2 gap-x-6 px-5 py-4 sm:grid-cols-3">
          <li v-for="d in denominations" :key="d.note"
              class="flex items-baseline justify-between border-b border-dashed border-paper-edge py-1.5">
            <span class="money text-sm font-semibold tabular-nums text-ink-900">{{ d.note.toLocaleString() }}</span>
            <span class="money text-sm font-semibold" :class="d.quantity ? 'text-ink-900' : 'text-ink-300'">
              x{{ d.quantity }}
            </span>
          </li>
        </ul>
      </section>

      <section class="rounded-counter border border-paper-edge bg-white p-5">
        <h2 class="font-display text-lg font-semibold">Today</h2>
        <p class="text-xs text-ink-700/65">{{ today.count }} transactions entered</p>
        <dl class="mt-4 space-y-2.5 text-sm">
          <div class="flex justify-between"><dt class="text-ink-700">Cash In</dt><dd><MoneyText :value="today.cash_in" /></dd></div>
          <div class="flex justify-between"><dt class="text-ink-700">Cash Out</dt><dd><MoneyText :value="today.cash_out" /></dd></div>
          <div class="flex justify-between"><dt class="text-ink-700">Transfer</dt><dd><MoneyText :value="today.transfer" /></dd></div>
          <div class="flex justify-between"><dt class="text-ink-700">Exchange</dt><dd><MoneyText :value="today.exchange" /></dd></div>
        </dl>
      </section>
    </div>

    <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <Link v-for="a in actions" :key="a.href" :href="locked ? '#' : a.href"
            :aria-disabled="locked"
            :headers="authHeaders()"
            class="group rounded-counter border border-paper-edge bg-white p-4 transition"
            :class="locked ? 'pointer-events-none opacity-45' : 'hover:-translate-y-0.5 hover:border-ink-700 hover:shadow-sm'">
        <p class="font-display font-semibold">{{ a.label }}</p>
        <p class="mt-1 text-xs leading-relaxed text-ink-700/70">{{ a.note }}</p>
      </Link>
    </div>

    <section class="mt-6 rounded-counter border border-paper-edge bg-white">
      <h2 class="border-b border-paper-edge px-5 py-3 font-display font-semibold">Recent entries</h2>
      <p v-if="!recent.length" class="px-5 py-10 text-center text-sm text-ink-700/60">
        Nothing entered yet. Your first transaction of the day appears here.
      </p>
      <table v-else class="w-full text-sm">
        <thead>
          <tr class="border-b border-paper-edge text-left">
            <th class="field-label px-5 py-2">Ref</th>
            <th class="field-label px-5 py-2">Type</th>
            <th class="field-label px-5 py-2 text-right">Amount</th>
            <th class="field-label px-5 py-2 text-right">Fee</th>
            <th class="field-label px-5 py-2 text-right">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-paper-edge">
          <tr v-for="t in recent" :key="t.id" class="hover:bg-ink-100/40">
            <td class="money px-5 py-2.5 text-ink-700">#{{ t.id }}</td>
            <td class="px-5 py-2.5 font-medium">{{ t.type }}</td>
            <td class="px-5 py-2.5 text-right"><MoneyText :value="t.amount" /></td>
            <td class="px-5 py-2.5 text-right text-ink-700"><MoneyText :value="t.fee_amount" /></td>
            <td class="px-5 py-2.5 text-right"><StateChip :status="t.status" /></td>
          </tr>
        </tbody>
      </table>
    </section>
  </EmployeeLayout>
</template>
