<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AccountPicker from '@/components/teller/AccountPicker.vue'
import AmountField from '@/components/teller/AmountField.vue'
import DenominationDrawer from '@/components/teller/DenominationDrawer.vue'
import MoneyText from '@/components/teller/MoneyText.vue'
import ReceiptSlip from '@/components/teller/ReceiptSlip.vue'
import ReviewSheet from '@/components/teller/ReviewSheet.vue'
import type {ReviewLine} from '@/components/teller/ReviewSheet.vue';
import TellerLayout from '@/layouts/TellerLayout.vue'
import { readStoredToken } from '@/lib/auth-token'
import { useLocale } from '@/lib/i18n'

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
  completed?: CompletedTxn | null
}>()

const accountId = ref<number | null>(null)
const amount = ref<number>(0)
const customerName = ref('')
const customerPhone = ref('')
const received = ref<Record<number, number>>({})
const change = ref<Record<number, number>>({})
const reviewing = ref(false)
const submitting = ref(false)
const errors = ref<Record<string, string>>({})
const { t } = useLocale()

const activeFloat = computed(() => props.float?.status === 'ACTIVE')
const account = computed(() => props.accounts.find(a => a.id === accountId.value))
const receivedTotal = computed(() => props.notes.reduce((s, n) => s + n * (received.value[n] ?? 0), 0))
const changeDue = computed(() => Math.max(0, receivedTotal.value - (amount.value || 0)))
const changeTotal = computed(() => props.notes.reduce((s, n) => s + n * (change.value[n] ?? 0), 0))
const shortPay = computed(() => receivedTotal.value > 0 && receivedTotal.value < (amount.value || 0))
const changeBalanced = computed(() => changeTotal.value === changeDue.value)

const ready = computed(() =>
  activeFloat.value &&
  accountId.value !== null &&
  amount.value > 0 &&
  customerName.value.trim() !== '' &&
  customerPhone.value.trim() !== '' &&
  Number(account.value?.balance ?? 0) >= amount.value &&
  receivedTotal.value >= amount.value &&
  changeBalanced.value,
)

const reviewLines = computed<ReviewLine[]>(() => [
  { label: `${t('transaction.accountDebit')} - ${account.value?.company ?? ''} ${account.value?.name ?? ''}`, value: amount.value, signed: 'debit' },
  { label: t('transaction.cashReceivedCustomer'), value: receivedTotal.value },
  ...(changeDue.value > 0
    ? [{ label: t('transaction.changeMyVault'), value: changeTotal.value, signed: 'debit' as const }]
    : []),
  { label: t('transaction.mainVaultIncrease'), value: amount.value, emphasize: true },
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

  router.post('/teller/transactions/cash-in', {
    _token: csrfToken(),
    account_id: accountId.value,
    amount: amount.value,
    amount_received: receivedTotal.value,
    received_denominations: received.value,
    change_breakdown: change.value,
    change_denominations: change.value,
    customer_name: customerName.value,
    customer_phone: customerPhone.value,
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
  <TellerLayout :float="float">
    <template v-if="completed">
      <header class="mb-6 text-center">
        <h1 class="font-display text-2xl font-semibold tracking-tight">{{ t('transaction.cashInSubmitted') }}</h1>
        <p class="mt-1 text-sm text-ink-700/70">{{ t('transaction.completedHint') }}</p>
      </header>
      <ReceiptSlip :txn="completed" next-href="/teller/cash-in" :next-label="t('transaction.newCashIn')" />
    </template>

    <template v-else>
      <header class="mb-5">
        <h1 class="font-display text-2xl font-semibold tracking-tight">{{ t('transaction.cashIn') }}</h1>
        <p class="mt-1 text-sm text-ink-700/70">
          {{ t('transaction.cashInDescription') }}
        </p>
      </header>

      <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <div class="space-y-5">
          <section class="rounded-counter border border-paper-edge bg-white p-5">
            <div class="grid gap-5 sm:grid-cols-2">
              <div class="sm:col-span-2">
                <AccountPicker v-model="accountId" :accounts="accounts" :must-cover="amount"
                               :label="t('transaction.accountDeducted')" />
              </div>
              <div class="sm:col-span-2">
                <AmountField v-model="amount" :label="t('transaction.cashInAmount')" />
              </div>
              <div>
                <label class="field-label" for="name">{{ t('transaction.customerName') }}</label>
                <input id="name" v-model="customerName" class="field-input mt-1.5" />
              </div>
              <div>
                <label class="field-label" for="phone">{{ t('transaction.customerPhone') }}</label>
                <input id="phone" v-model="customerPhone" class="field-input mt-1.5" />
              </div>
            </div>
          </section>

          <DenominationDrawer
            v-model="received"
            :notes="notes"
            :target="null"
            :label="t('transaction.cashReceivedCustomer')"
          />
          <p v-if="shortPay" class="rounded-counter border border-debit/30 bg-debit/5 px-4 py-2.5 text-sm text-debit">
              {{ t('transaction.cashShort') }}
          </p>

          <template v-if="changeDue > 0">
            <div class="rounded-counter border border-held/30 bg-held/5 px-4 py-3 text-sm text-held">
              {{ t('transaction.changeNotice') }} <MoneyText :value="changeDue" class="font-semibold" />
            </div>
            <DenominationDrawer
              v-model="change"
              :notes="notes"
              :target="changeDue"
              :stock="floatStock"
              :label="t('transaction.changeMyVault')"
            />
          </template>
        </div>

        <aside class="h-fit rounded-counter border border-ink-800 bg-ink-900 p-5 text-ink-100 lg:sticky lg:top-24">
          <h2 class="font-display text-sm font-semibold uppercase tracking-[0.14em] text-ink-300">{{ t('teller.receipt') }}</h2>
          <dl class="mt-4 space-y-3 text-sm">
            <div class="flex justify-between"><dt class="text-ink-300">{{ t('transaction.cashReceived') }}</dt><dd><MoneyText :value="receivedTotal" /></dd></div>
            <div class="flex justify-between"><dt class="text-ink-300">{{ t('dashboard.change') }}</dt><dd><MoneyText :value="changeTotal" signed="debit" /></dd></div>
            <div class="flex justify-between border-t border-ink-800 pt-3">
              <dt class="font-semibold">{{ t('transaction.mainVaultIncrease') }}</dt><dd class="font-semibold"><MoneyText :value="amount || 0" /></dd>
            </div>
            <div class="flex justify-between"><dt class="text-ink-300">{{ t('transaction.accountDeducted') }}</dt><dd><MoneyText :value="amount || 0" signed="debit" /></dd></div>
            <div class="flex justify-between"><dt class="text-ink-300">{{ t('transaction.floatAfterChange') }}</dt>
              <dd><MoneyText :value="Number(float?.current_balance ?? 0) - changeTotal" /></dd></div>
          </dl>

          <button type="button" :disabled="!ready" @click="reviewing = true"
                  class="mt-5 w-full rounded-counter bg-seal py-3 text-sm font-semibold text-ink-950 transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-35">
            {{ t('common.reviewSlip') }}
          </button>
          <p class="mt-2.5 text-center text-[11px] leading-relaxed text-ink-300">
            {{ t('component.cashierConfirmHint') }}
          </p>
          <p v-for="(msg, key) in errors" :key="key" class="mt-2 text-sm text-debit">{{ msg }}</p>
        </aside>
      </div>

      <ReviewSheet
        :open="reviewing"
        :busy="submitting"
        :title="t('transaction.cashIn')"
        :lines="reviewLines"
        :confirm-label="t('transaction.confirmCashIn')"
        :consequence="t('transaction.cashInConsequence')"
        @confirm="submit"
        @close="reviewing = false"
      />
    </template>
  </TellerLayout>
</template>
