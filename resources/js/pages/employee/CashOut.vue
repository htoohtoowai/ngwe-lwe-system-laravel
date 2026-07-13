<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import AccountPicker from '@/components/teller/AccountPicker.vue'
import AmountField from '@/components/teller/AmountField.vue'
import DenominationDrawer from '@/components/teller/DenominationDrawer.vue'
import MoneyText from '@/components/teller/MoneyText.vue'
import ReceiptSlip from '@/components/teller/ReceiptSlip.vue'
import ReviewSheet from '@/components/teller/ReviewSheet.vue'
import type {ReviewLine} from '@/components/teller/ReviewSheet.vue';
import EmployeeLayout from '@/layouts/EmployeeLayout.vue'
import { readStoredToken } from '@/lib/auth-token'

type TellerAccount = { id: number; name: string; company: string; balance: string }
type TellerFloat = { id: number; status: string; current_balance: string } | null
type CompletedTxn = {
  id: number
  type: string
  amount: string
  fee_amount: string
  status: string
  created_at: string
  account_label?: string
  change_given?: string
}

const props = defineProps<{
  float: TellerFloat
  notes: number[]
  floatStock: Record<number, number>
  accounts: TellerAccount[]
  fee: string
  completed?: CompletedTxn | null
}>()

const accountId = ref<number | null>(null)
const amount = ref<number>(0)
const customerName = ref('')
const customerPhone = ref('')
const payout = ref<Record<number, number>>({})
const reviewing = ref(false)
const submitting = ref(false)
const errors = ref<Record<string, string>>({})

const activeFloat = computed(() => props.float?.status === 'ACTIVE')
const feeNum = computed(() => Number(props.fee ?? 0))
const payoutTotal = computed(() => props.notes.reduce((s, n) => s + n * (payout.value[n] ?? 0), 0))
const floatBalance = computed(() => Number(props.float?.current_balance ?? 0))
const shortFloat = computed(() => (amount.value || 0) > floatBalance.value)

const ready = computed(() =>
  activeFloat.value &&
  accountId.value !== null &&
  amount.value > 0 &&
  customerName.value.trim() !== '' &&
  customerPhone.value.trim() !== '' &&
  !shortFloat.value &&
  payoutTotal.value === amount.value,
)

let feeTimer: ReturnType<typeof setTimeout>
watch([amount, accountId], ([a, acc]) => {
  clearTimeout(feeTimer)

  if (a > 0 && acc) {
    feeTimer = setTimeout(() =>
      router.reload({ only: ['fee'], data: { amount: a, account_id: acc }, headers: authHeaders() }), 350)
  }
})

const reviewLines = computed<ReviewLine[]>(() => [
  { label: 'Cash paid to customer', value: payoutTotal.value, signed: 'debit' },
  { label: 'Fee from commission tier', value: feeNum.value },
  { label: 'Account credited', value: (amount.value || 0) + feeNum.value, signed: 'credit', emphasize: true },
  { label: 'Your float after payout', value: floatBalance.value - payoutTotal.value },
])

function authHeaders(): Record<string, string> {
  const token = readStoredToken()

  return token ? { Authorization: `Bearer ${token}` } : {}
}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

function submit() {
  submitting.value = true
  errors.value = {}

  router.post('/employee/transactions/cash-out', {
    _token: csrfToken(),
    account_id: accountId.value,
    amount: amount.value,
    customer_name: customerName.value,
    customer_phone: customerPhone.value,
    denominations: payout.value,
  }, {
    headers: authHeaders(),
    onError: e => {
 errors.value = e as Record<string, string>; reviewing.value = false 
},
    onFinish: () => (submitting.value = false),
  })
}
</script>

<template>
  <EmployeeLayout :float="float">
    <template v-if="completed">
      <header class="mb-6 text-center">
        <h1 class="font-display text-2xl font-semibold tracking-tight">Cash Out completed</h1>
        <p class="mt-1 text-sm text-ink-700/70">Count the notes across the counter with the customer watching.</p>
      </header>
      <ReceiptSlip :txn="completed" next-href="/employee/cash-out" next-label="Next Cash Out" />
    </template>

    <template v-else>
      <header class="mb-5">
        <h1 class="font-display text-2xl font-semibold tracking-tight">Cash Out</h1>
        <p class="mt-1 text-sm text-ink-700/70">
          Pay the customer from your float. The account is credited the moment the notes are counted out.
        </p>
      </header>

      <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <div class="space-y-5">
          <section class="rounded-counter border border-paper-edge bg-white p-5">
            <div class="grid gap-5 sm:grid-cols-2">
              <div class="sm:col-span-2">
                <AccountPicker v-model="accountId" :accounts="accounts" label="Account to credit" />
              </div>
              <div class="sm:col-span-2">
                <AmountField v-model="amount" label="Cash to pay out" />
              </div>
              <div>
                <label class="field-label" for="name">Customer name</label>
                <input id="name" v-model="customerName" class="field-input mt-1.5" />
              </div>
              <div>
                <label class="field-label" for="phone">Customer phone</label>
                <input id="phone" v-model="customerPhone" class="field-input mt-1.5" />
              </div>
              <div class="sm:col-span-2">
                <p class="field-label">Fee from commission tier</p>
                <p class="field-input money mt-1.5 bg-paper text-lg text-ink-700">{{ feeNum.toLocaleString() }}</p>
                <p class="mt-1 text-xs text-ink-700/55">
                  Set by commission tiers and MMK rounding rules. It cannot be edited here.
                </p>
              </div>
            </div>
          </section>

          <p v-if="shortFloat" class="rounded-counter border border-debit/30 bg-debit/5 px-4 py-2.5 text-sm text-debit">
            Your float holds <MoneyText :value="floatBalance" class="font-semibold" />. Reduce the amount or ask the
            cashier for a top-up.
          </p>

          <DenominationDrawer
            v-model="payout"
            :notes="notes"
            :target="amount || 0"
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
            <div class="flex justify-between"><dt class="text-ink-300">Float after payout</dt>
              <dd><MoneyText :value="floatBalance - payoutTotal" /></dd></div>
          </dl>

          <button type="button" :disabled="!ready" @click="reviewing = true"
                  class="mt-5 w-full rounded-counter bg-seal py-3 text-sm font-semibold text-ink-950 transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-35">
            Review slip
          </button>
          <p class="mt-2.5 text-center text-[11px] leading-relaxed text-ink-300">
            Cash Out completes immediately on confirm. No cashier step.
          </p>
          <p v-for="(msg, key) in errors" :key="key" class="mt-2 text-sm text-debit">{{ msg }}</p>
        </aside>
      </div>

      <ReviewSheet
        :open="reviewing"
        :busy="submitting"
        title="Cash Out"
        :lines="reviewLines"
        confirm-label="Pay out cash"
        consequence="On confirm, the account is credited and these exact notes are deducted from your float."
        @confirm="submit"
        @close="reviewing = false"
      />
    </template>
  </EmployeeLayout>
</template>
