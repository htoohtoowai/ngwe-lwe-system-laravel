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
  fee: string
  rate: { buy_rate: string; sell_rate: string }
  completed?: CompletedTxn | null
}>()

const accountId = ref<number | null>(null)
const amount = ref<number>(0)
const currency = ref<'MMK' | 'THB'>('MMK')
const payout = ref<Record<number, number>>({})
const reviewing = ref(false)
const submitting = ref(false)
const errors = ref<Record<string, string>>({})
const { t } = useLocale()

const activeFloat = computed(() => props.float?.status === 'ACTIVE')
const feeNum = computed(() => Number(props.fee ?? 0))
const payoutTotal = computed(() => props.notes.reduce((s, n) => s + n * (payout.value[n] ?? 0), 0))
const floatBalance = computed(() => Number(props.float?.current_balance ?? 0))
const shortFloat = computed(() => (amount.value || 0) > floatBalance.value)

const ready = computed(() =>
  activeFloat.value &&
  accountId.value !== null &&
  amount.value > 0 &&
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
  { label: t('transaction.countedMovement'), value: payoutTotal.value, signed: 'debit' },
  { label: `${t('transaction.fee')} (${t('transaction.commissionTier')})`, value: feeNum.value },
  { label: `${t('transaction.direction')} ${currency.value}`, value: amount.value || 0 },
  { label: t('transaction.accountCredited'), value: amount.value || 0, signed: 'credit', emphasize: true },
  { label: t('transaction.floatAfterExchange'), value: floatBalance.value - payoutTotal.value },
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

  router.post('/teller/transactions/exchange', {
    _token: csrfToken(),
    account_id: accountId.value,
    amount: amount.value,
    currency: currency.value,
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
  <TellerLayout :float="float">
    <template v-if="completed">
      <header class="mb-6 text-center">
        <h1 class="font-display text-2xl font-semibold tracking-tight">{{ t('transaction.exchange') }} ပြီးပါပြီ</h1>
        <p class="mt-1 text-sm text-ink-700/70">{{ t('transaction.completedHint') }}</p>
      </header>
      <ReceiptSlip :txn="completed" next-href="/teller/exchange" :next-label="t('transaction.exchange')" />
    </template>

    <template v-else>
      <header class="mb-5">
        <h1 class="font-display text-2xl font-semibold tracking-tight">{{ t('transaction.exchange') }}</h1>
        <p class="mt-1 text-sm text-ink-700/70">
          {{ t('transaction.exchangeDescription') }}
        </p>
      </header>

      <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <div class="space-y-5">
          <section class="rounded-counter border border-paper-edge bg-white p-5">
            <div class="grid gap-5">
              <AccountPicker v-model="accountId" :accounts="accounts" :label="t('transaction.exchangeAccount')" />
              <AmountField v-model="amount" :label="t('transaction.cashToExchange')" />
              <div>
                <p class="field-label">{{ t('transaction.direction') }}</p>
                <div class="mt-1.5 grid grid-cols-2 overflow-hidden rounded-counter border border-paper-edge">
                  <button
                    type="button"
                    class="px-3 py-2.5 text-sm font-semibold"
                    :class="currency === 'MMK' ? 'bg-ink-900 text-white' : 'bg-white text-ink-800'"
                    @click="currency = 'MMK'"
                  >
                    {{ t('transaction.mmkToThb') }}
                  </button>
                  <button
                    type="button"
                    class="border-l border-paper-edge px-3 py-2.5 text-sm font-semibold"
                    :class="currency === 'THB' ? 'bg-ink-900 text-white' : 'bg-white text-ink-800'"
                    @click="currency = 'THB'"
                  >
                    {{ t('transaction.thbToMmk') }}
                  </button>
                </div>
              </div>
              <div>
                <p class="field-label">{{ t('transaction.fee') }} ({{ t('transaction.commissionTier') }})</p>
                <p class="field-input money mt-1.5 bg-paper text-lg text-ink-700">{{ feeNum.toLocaleString() }}</p>
              </div>
            </div>
          </section>

          <p v-if="shortFloat" class="rounded-counter border border-debit/30 bg-debit/5 px-4 py-2.5 text-sm text-debit">
            {{ t('transaction.floatShort') }}
          </p>

          <DenominationDrawer
            v-model="payout"
            :notes="notes"
            :target="amount || 0"
            :stock="floatStock"
            :label="t('transaction.countedMovement')"
          />
        </div>

        <aside class="h-fit rounded-counter border border-ink-800 bg-ink-900 p-5 text-ink-100 lg:sticky lg:top-24">
          <h2 class="font-display text-sm font-semibold uppercase tracking-[0.14em] text-ink-300">{{ t('transaction.slip') }}</h2>
          <dl class="mt-4 space-y-3 text-sm">
            <div class="flex justify-between"><dt class="text-ink-300">{{ t('transaction.sellRate') }}</dt><dd class="money">{{ rate.sell_rate }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-300">{{ t('transaction.buyRate') }}</dt><dd class="money">{{ rate.buy_rate }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-300">{{ t('transaction.direction') }}</dt><dd>{{ currency }}</dd></div>
            <div class="flex justify-between"><dt class="text-ink-300">{{ t('transaction.countedMovement') }}</dt><dd><MoneyText :value="payoutTotal" /></dd></div>
            <div class="flex justify-between"><dt class="text-ink-300">{{ t('transaction.fee') }}</dt><dd><MoneyText :value="feeNum" /></dd></div>
            <div class="flex justify-between border-t border-ink-800 pt-3">
              <dt class="font-semibold">{{ t('transaction.accountCredited') }}</dt>
              <dd class="font-semibold"><MoneyText :value="amount || 0" signed="credit" /></dd>
            </div>
            <div class="flex justify-between"><dt class="text-ink-300">{{ t('transaction.floatAfterExchange') }}</dt><dd><MoneyText :value="floatBalance - payoutTotal" /></dd></div>
          </dl>

          <button type="button" :disabled="!ready" @click="reviewing = true"
                  class="mt-5 w-full rounded-counter bg-seal py-3 text-sm font-semibold text-ink-950 transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-35">
            {{ t('common.reviewSlip') }}
          </button>
          <p v-for="(msg, key) in errors" :key="key" class="mt-2 text-sm text-debit">{{ msg }}</p>
        </aside>
      </div>

      <ReviewSheet
        :open="reviewing"
        :busy="submitting"
        :title="t('transaction.exchange')"
        :lines="reviewLines"
        :confirm-label="t('transaction.confirmExchange')"
        :consequence="t('transaction.exchangeConsequence')"
        @confirm="submit"
        @close="reviewing = false"
      />
    </template>
  </TellerLayout>
</template>
