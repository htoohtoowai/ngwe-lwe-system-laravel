<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import DenominationDrawer from '@/components/teller/DenominationDrawer.vue'
import MoneyText from '@/components/teller/MoneyText.vue'
import PinSeal from '@/components/teller/PinSeal.vue'
import StateChip from '@/components/teller/StateChip.vue'
import EmployeeLayout from '@/layouts/EmployeeLayout.vue'
import { apiRequest } from '@/lib/api'
import { readStoredToken } from '@/lib/auth-token'

type TellerFloat = { id: number; status: string; current_balance: string } | null

const props = defineProps<{
  float: TellerFloat
  notes: number[]
  issued: Record<number, number>
  onHand: Record<number, number>
}>()

const counted = ref<Record<number, number>>({})
const returning = ref<Record<number, number>>({})
const pinOpen = ref(false)
const pinBusy = ref(false)
const pinError = ref<string | null>(null)
const intent = ref<'receive' | 'return'>('receive')

const status = computed(() => props.float?.status ?? 'CLOSED')
const issuedTotal = computed(() => props.notes.reduce((s, n) => s + n * (props.issued[n] ?? 0), 0))
const countMatches = computed(() =>
  props.notes.every(n => (counted.value[n] ?? 0) === (props.issued[n] ?? 0)),
)
const returnTotal = computed(() => props.notes.reduce((s, n) => s + n * (returning.value[n] ?? 0), 0))
const expectedReturn = computed(() => Number(props.float?.current_balance ?? 0))
const returnMatches = computed(() => returnTotal.value === expectedReturn.value)

function open(kind: 'receive' | 'return') {
  intent.value = kind
  pinError.value = null
  pinOpen.value = true
}

function firstError(error: unknown): string {
  const apiError = error as { message?: string; errors?: Record<string, string[]> }
  const validation = apiError.errors ? Object.values(apiError.errors)[0]?.[0] : null

  return validation ?? apiError.message ?? 'Request failed.'
}

function authHeaders(): Record<string, string> {
  const token = readStoredToken()

  return token ? { Authorization: `Bearer ${token}` } : {}
}

async function confirm(pin: string) {
  if (!props.float) {
    return
  }

  pinBusy.value = true
  pinError.value = null

  const url = intent.value === 'receive'
    ? `/api/cash-floats/${props.float.id}/activate`
    : `/api/cash-floats/${props.float.id}/initiate-return`
  const data = intent.value === 'receive'
    ? { pin, verified_denominations: counted.value }
    : { pin, denominations: returning.value }

  try {
    await apiRequest(url, {
      method: 'POST',
      token: readStoredToken(),
      body: data,
    })
    pinOpen.value = false
    router.reload({ only: ['float', 'recent'], headers: authHeaders() })
  } catch (error) {
    pinError.value = firstError(error)
  } finally {
    pinBusy.value = false
  }
}
</script>

<template>
  <EmployeeLayout :float="float">
    <header class="mb-5 flex items-start justify-between">
      <div>
        <h1 class="font-display text-2xl font-semibold tracking-tight">My float</h1>
        <p class="mt-1 text-sm text-ink-700/70">Cash you are personally accountable for until the cashier signs it back in.</p>
      </div>
      <StateChip :status="status" />
    </header>

    <div v-if="!float || status === 'CLOSED'"
         class="rounded-counter border border-dashed border-paper-edge bg-white px-6 py-16 text-center">
      <p class="font-display text-lg font-semibold">No float issued</p>
      <p class="mx-auto mt-1.5 max-w-sm text-sm text-ink-700/70">
        Ask the cashier to issue one. It will appear here to be counted, and the counter opens as soon as you receive it.
      </p>
    </div>

    <div v-else-if="status === 'PENDING_RECEIPT'" class="grid gap-6 lg:grid-cols-[1fr_20rem]">
      <div class="space-y-4">
        <div class="rounded-counter border border-held/30 bg-held/5 px-4 py-3 text-sm text-held">
          The cashier issued <MoneyText :value="issuedTotal" class="font-semibold" />. Count every note yourself.
        </div>
        <DenominationDrawer
          v-model="counted"
          :notes="notes"
          :target="issuedTotal"
          :expected="issued"
          label="Count the notes you were handed"
        />
      </div>

      <aside class="h-fit rounded-counter border border-ink-800 bg-ink-900 p-5 text-ink-100 lg:sticky lg:top-24">
        <h2 class="font-display text-sm font-semibold uppercase tracking-[0.14em] text-ink-300">Receipt</h2>
        <dl class="mt-4 space-y-3 text-sm">
          <div class="flex justify-between"><dt class="text-ink-300">Issued</dt><dd><MoneyText :value="issuedTotal" /></dd></div>
          <div class="flex justify-between">
            <dt class="text-ink-300">Your count</dt>
            <dd><MoneyText :value="notes.reduce((s, n) => s + n * (counted[n] ?? 0), 0)" /></dd>
          </div>
        </dl>
        <p v-if="!countMatches" class="mt-3 text-xs leading-relaxed text-held">
          Quantities must match the cashier's note-for-note, not just in total.
        </p>
        <button type="button" :disabled="!countMatches" @click="open('receive')"
                class="mt-5 w-full rounded-counter bg-seal py-3 text-sm font-semibold text-ink-950 transition hover:brightness-110 disabled:opacity-35">
          Receive float with PIN
        </button>
      </aside>
    </div>

    <div v-else-if="status === 'ACTIVE'" class="grid gap-6 lg:grid-cols-[1fr_20rem]">
      <DenominationDrawer
        v-model="returning"
        :notes="notes"
        :target="expectedReturn"
        :stock="onHand"
        label="Count the cash you are handing back"
      />
      <aside class="h-fit rounded-counter border border-ink-800 bg-ink-900 p-5 text-ink-100 lg:sticky lg:top-24">
        <h2 class="font-display text-sm font-semibold uppercase tracking-[0.14em] text-ink-300">Return</h2>
        <dl class="mt-4 space-y-3 text-sm">
          <div class="flex justify-between"><dt class="text-ink-300">System says on hand</dt><dd><MoneyText :value="expectedReturn" /></dd></div>
          <div class="flex justify-between"><dt class="text-ink-300">You counted</dt><dd><MoneyText :value="returnTotal" /></dd></div>
        </dl>
        <p class="mt-3 text-xs leading-relaxed text-ink-300">
          Once returned, the counter closes until a new float is issued.
        </p>
        <button type="button" :disabled="!returnMatches" @click="open('return')"
                class="mt-5 w-full rounded-counter border border-seal py-3 text-sm font-semibold text-seal transition hover:bg-seal hover:text-ink-950 disabled:opacity-35">
          Hand back to cashier
        </button>
      </aside>
    </div>

    <div v-else class="rounded-counter border border-paper-edge bg-white px-6 py-16 text-center">
      <p class="font-display text-lg font-semibold">Waiting for the cashier to confirm</p>
      <p class="mx-auto mt-1.5 max-w-sm text-sm text-ink-700/70">
        You handed back <MoneyText :value="expectedReturn" class="font-semibold" />. When the cashier confirms with
        their PIN, the vault is credited and this float closes.
      </p>
    </div>

    <PinSeal
      :open="pinOpen"
      :busy="pinBusy"
      :error="pinError"
      :title="intent === 'receive' ? 'Confirm you counted the float' : 'Confirm the cash you are returning'"
      :detail="intent === 'receive' ? 'Your PIN records that the notes match.' : 'Your PIN records the return count.'"
      @confirm="confirm"
      @close="pinOpen = false"
    />
  </EmployeeLayout>
</template>
