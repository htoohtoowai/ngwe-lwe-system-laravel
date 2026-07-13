<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import EmployeeLayout from '@/layouts/EmployeeLayout.vue'
import DenominationDrawer from '@/components/teller/DenominationDrawer.vue'
import MoneyText from '@/components/teller/MoneyText.vue'
import { apiRequest } from '@/lib/api'
import { readStoredToken } from '@/lib/auth-token'

type TellerAccount = { id: number; name: string; company: string; balance: string }
type TellerFloat = { id: number; status: string; current_balance: string } | null

const props = defineProps<{
  float: TellerFloat
  notes: number[]
  floatStock: Record<number, number>
  accounts: TellerAccount[]
  fee: string
}>()

const accountId = ref<number | null>(null)
const amount = ref<number>(0)
const customerName = ref('')
const customerPhone = ref('')
const payout = ref<Record<number, number>>({})
const submitting = ref(false)
const errors = ref<Record<string, string>>({})

const activeFloat = computed(() => props.float?.status === 'ACTIVE')
const feeNum = computed(() => Number(props.fee ?? 0))
const payoutDue = computed(() => Math.max(0, amount.value || 0))
const payoutTotal = computed(() => props.notes.reduce((s, n) => s + n * (payout.value[n] ?? 0), 0))
const floatAfter = computed(() => Number(props.float?.current_balance ?? 0) - payoutTotal.value)
const shortFloat = computed(() => payoutDue.value > Number(props.float?.current_balance ?? 0))
const ready = computed(() =>
  activeFloat.value &&
  accountId.value !== null &&
  amount.value > 0 &&
  customerName.value.trim() !== '' &&
  customerPhone.value.trim() !== '' &&
  !shortFloat.value &&
  payoutTotal.value === payoutDue.value,
)

watch([amount, accountId], ([value, selectedAccount]) => {
  if (value > 0 && selectedAccount) {
    router.reload({ only: ['fee'], data: { amount: value, account_id: selectedAccount }, headers: authHeaders() })
  }
})

function flattenErrors(error: unknown): Record<string, string> {
  const apiError = error as { message?: string; errors?: Record<string, string[]> }

  if (apiError.errors) {
    return Object.fromEntries(
      Object.entries(apiError.errors).map(([key, value]) => [key, value.join(' ')]),
    )
  }

  return { request: apiError.message ?? 'Request failed.' }
}

function authHeaders(): Record<string, string> {
  const token = readStoredToken()

  return token ? { Authorization: `Bearer ${token}` } : {}
}

async function submit() {
  submitting.value = true
  errors.value = {}

  try {
    await apiRequest('/api/transactions/cash-out', {
      method: 'POST',
      token: readStoredToken(),
      body: {
        account_id: accountId.value,
        amount: amount.value,
        customer_name: customerName.value,
        customer_phone: customerPhone.value,
        denominations: payout.value,
      },
    })
    router.reload({ only: ['float', 'recent'], headers: authHeaders() })
  } catch (error) {
    errors.value = flattenErrors(error)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <EmployeeLayout :float="float">
    <header class="mb-5">
      <h1 class="font-display text-2xl font-semibold tracking-tight">Cash Out</h1>
      <p class="mt-1 text-sm text-ink-700/70">
        Pay the customer from your float. The account is credited the moment the notes are counted out.
      </p>
    </header>

    <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
      <div class="space-y-5">
        <section class="rounded-counter border border-paper-edge bg-white p-5">
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="field-label" for="account">Account to credit</label>
              <select id="account" v-model="accountId" class="field-input mt-1.5">
                <option :value="null" disabled>Choose an account</option>
                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.company }} - {{ a.name }}</option>
              </select>
            </div>
            <div>
              <label class="field-label" for="amount">Cash to pay out</label>
              <input id="amount" v-model.number="amount" type="number" min="0" step="100"
                     class="field-input money mt-1.5 text-lg" placeholder="0" />
            </div>
            <div>
              <p class="field-label">Fee from commission tier</p>
              <p class="field-input money mt-1.5 bg-paper text-lg text-ink-700">{{ Number(fee).toLocaleString() }}</p>
            </div>
            <div>
              <label class="field-label" for="name">Customer name</label>
              <input id="name" v-model="customerName" class="field-input mt-1.5" />
            </div>
            <div>
              <label class="field-label" for="phone">Customer phone</label>
              <input id="phone" v-model="customerPhone" class="field-input mt-1.5" />
            </div>
          </div>
        </section>

        <p v-if="shortFloat" class="rounded-counter border border-debit/30 bg-debit/5 px-4 py-2.5 text-sm text-debit">
          Your float holds <MoneyText :value="float?.current_balance ?? 0" class="font-semibold" />. Reduce the amount
          or ask the cashier to top you up.
        </p>

        <DenominationDrawer
          v-model="payout"
          :notes="notes"
          :target="payoutDue"
          :stock="floatStock"
          label="Notes counted out to customer"
        />
      </div>

      <aside class="h-fit rounded-counter border border-ink-800 bg-ink-900 p-5 text-ink-100 lg:sticky lg:top-24">
        <h2 class="font-display text-sm font-semibold uppercase tracking-[0.14em] text-ink-300">Slip</h2>
        <dl class="mt-4 space-y-3 text-sm">
          <div class="flex justify-between"><dt class="text-ink-300">Paid to customer</dt><dd><MoneyText :value="payoutTotal" /></dd></div>
          <div class="flex justify-between"><dt class="text-ink-300">Fee</dt><dd><MoneyText :value="feeNum" /></dd></div>
          <div class="flex justify-between border-t border-ink-800 pt-3">
            <dt class="font-semibold">Account credited</dt>
            <dd class="font-semibold"><MoneyText :value="(amount || 0) + feeNum" signed="credit" /></dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-ink-300">Float after payout</dt>
            <dd><MoneyText :value="floatAfter" :signed="floatAfter < Number(float?.current_balance ?? 0) ? 'debit' : null" /></dd>
          </div>
        </dl>

        <button type="button" :disabled="!ready || submitting" @click="submit"
                class="mt-5 w-full rounded-counter bg-seal py-3 text-sm font-semibold text-ink-950 transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-35">
          {{ submitting ? 'Recording...' : 'Complete cash out' }}
        </button>
        <p class="mt-2.5 text-center text-[11px] leading-relaxed text-ink-300">
          Completes immediately. No cashier confirmation is required for cash out.
        </p>
        <p v-for="(msg, key) in errors" :key="key" class="mt-2 text-sm text-debit">{{ msg }}</p>
      </aside>
    </div>
  </EmployeeLayout>
</template>
