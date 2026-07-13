<script setup lang="ts">
import { computed, ref } from 'vue'
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
}>()

const accountId = ref<number | null>(null)
const amount = ref<number>(0)
const customerName = ref('')
const customerPhone = ref('')
const received = ref<Record<number, number>>({})
const change = ref<Record<number, number>>({})
const submitting = ref(false)
const errors = ref<Record<string, string>>({})

const activeFloat = computed(() => props.float?.status === 'ACTIVE')
const receivedTotal = computed(() => props.notes.reduce((s, n) => s + n * (received.value[n] ?? 0), 0))
const changeDue = computed(() => Math.max(0, receivedTotal.value - (amount.value || 0)))
const changeTotal = computed(() => props.notes.reduce((s, n) => s + n * (change.value[n] ?? 0), 0))
const account = computed(() => props.accounts.find(a => a.id === accountId.value))
const shortPay = computed(() => receivedTotal.value > 0 && receivedTotal.value < (amount.value || 0))
const changeBalanced = computed(() => changeTotal.value === changeDue.value)
const accountHasFunds = computed(() => !account.value || Number(account.value.balance) >= (amount.value || 0))

const ready = computed(() =>
  activeFloat.value &&
  accountId.value !== null &&
  amount.value > 0 &&
  customerName.value.trim() !== '' &&
  customerPhone.value.trim() !== '' &&
  receivedTotal.value >= amount.value &&
  changeBalanced.value &&
  accountHasFunds.value,
)

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
    await apiRequest('/api/transactions/cash-in', {
      method: 'POST',
      token: readStoredToken(),
      body: {
        account_id: accountId.value,
        amount: amount.value,
        amount_received: receivedTotal.value,
        received_breakdown: received.value,
        change_breakdown: change.value,
        change_denominations: change.value,
        customer_name: customerName.value,
        customer_phone: customerPhone.value,
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
      <h1 class="font-display text-2xl font-semibold tracking-tight">Cash In</h1>
      <p class="mt-1 text-sm text-ink-700/70">
        Take the customer's cash, deduct the account, and hand the slip to the cashier for confirmation.
      </p>
    </header>

    <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
      <div class="space-y-5">
        <section class="rounded-counter border border-paper-edge bg-white p-5">
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="field-label" for="account">Account to deduct</label>
              <select id="account" v-model="accountId" class="field-input mt-1.5">
                <option :value="null" disabled>Choose an account</option>
                <option v-for="a in accounts" :key="a.id" :value="a.id">
                  {{ a.company }} - {{ a.name }} ({{ Number(a.balance).toLocaleString() }} MMK)
                </option>
              </select>
              <p v-if="account && Number(account.balance) < amount" class="mt-1.5 text-sm text-debit">
                This account holds {{ Number(account.balance).toLocaleString() }} MMK. The deposit cannot exceed it.
              </p>
            </div>

            <div>
              <label class="field-label" for="amount">Deposit amount</label>
              <input id="amount" v-model.number="amount" type="number" min="0" step="100"
                     class="field-input money mt-1.5 text-lg" placeholder="0" />
            </div>
            <div>
              <label class="field-label" for="name">Customer name</label>
              <input id="name" v-model="customerName" class="field-input mt-1.5" />
            </div>
            <div class="sm:col-span-2">
              <label class="field-label" for="phone">Customer phone</label>
              <input id="phone" v-model="customerPhone" class="field-input mt-1.5" />
            </div>
          </div>
        </section>

        <DenominationDrawer
          v-model="received"
          :notes="notes"
          :target="null"
          label="Cash received from customer"
        />
        <p v-if="shortPay" class="rounded-counter border border-debit/30 bg-debit/5 px-4 py-2.5 text-sm text-debit">
          The customer has handed over less than the deposit. Count the remaining notes before continuing.
        </p>

        <template v-if="changeDue > 0">
          <div class="rounded-counter border border-held/30 bg-held/5 px-4 py-3 text-sm text-held">
            Overpayment. Return <MoneyText :value="changeDue" class="font-semibold" /> from your own float.
          </div>
          <DenominationDrawer
            v-model="change"
            :notes="notes"
            :target="changeDue"
            :stock="floatStock"
            label="Change paid from your float"
          />
        </template>
      </div>

      <aside class="h-fit rounded-counter border border-ink-800 bg-ink-900 p-5 text-ink-100 lg:sticky lg:top-24">
        <h2 class="font-display text-sm font-semibold uppercase tracking-[0.14em] text-ink-300">Slip</h2>
        <dl class="mt-4 space-y-3 text-sm">
          <div class="flex justify-between">
            <dt class="text-ink-300">Cash received</dt>
            <dd><MoneyText :value="receivedTotal" /></dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-ink-300">Change returned</dt>
            <dd><MoneyText :value="changeTotal" signed="debit" /></dd>
          </div>
          <div class="flex justify-between border-t border-ink-800 pt-3">
            <dt class="font-semibold">Net to vault</dt>
            <dd class="font-semibold"><MoneyText :value="amount || 0" /></dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-ink-300">Account deducted</dt>
            <dd><MoneyText :value="amount || 0" signed="debit" /></dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-ink-300">Float after change</dt>
            <dd><MoneyText :value="Number(float?.current_balance ?? 0) - changeTotal" /></dd>
          </div>
        </dl>

        <button type="button" :disabled="!ready || submitting" @click="submit"
                class="mt-5 w-full rounded-counter bg-seal py-3 text-sm font-semibold text-ink-950 transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-35">
          {{ submitting ? 'Sending...' : 'Send to cashier' }}
        </button>
        <p class="mt-2.5 text-center text-[11px] leading-relaxed text-ink-300">
          Saved as awaiting cashier. The account is debited immediately; the vault is credited when the cashier confirms.
        </p>
        <p v-for="(msg, key) in errors" :key="key" class="mt-2 text-sm text-debit">{{ msg }}</p>
      </aside>
    </div>
  </EmployeeLayout>
</template>
