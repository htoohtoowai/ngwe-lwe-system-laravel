<script setup lang="ts">
import { computed } from 'vue'
import MoneyText from './MoneyText.vue'

const props = withDefaults(defineProps<{
  notes: number[]
  modelValue: Record<number, number>
  target?: number | null
  stock?: Record<number, number> | null
  expected?: Record<number, number> | null
  label?: string
  readonly?: boolean
}>(), { target: null, stock: null, expected: null, label: 'Notes counted', readonly: false })

const emit = defineEmits<{ 'update:modelValue': [Record<number, number>] }>()

const qty = (n: number) => Number(props.modelValue?.[n] ?? 0)
const lineTotal = (n: number) => n * qty(n)

const total = computed(() => props.notes.reduce((sum, n) => sum + lineTotal(n), 0))
const difference = computed(() => props.target === null ? 0 : total.value - (props.target ?? 0))
const balanced = computed(() => props.target === null ? true : difference.value === 0)
const noteCount = computed(() => props.notes.reduce((c, n) => c + qty(n), 0))

function set(n: number, next: number) {
  if (props.readonly) return
  const cap = props.stock?.[n] ?? Infinity
  const clamped = Math.max(0, Math.min(Math.floor(next || 0), cap))
  emit('update:modelValue', { ...props.modelValue, [n]: clamped })
}

function autoFill() {
  if (props.target === null || props.readonly) return
  let left = props.target
  const next: Record<number, number> = {}
  for (const n of [...props.notes].sort((a, b) => b - a)) {
    const cap = props.stock?.[n] ?? Infinity
    const take = Math.min(Math.floor(left / n), cap)
    next[n] = take
    left -= take * n
  }
  emit('update:modelValue', next)
}

function clear() {
  if (props.readonly) return
  emit('update:modelValue', {})
}

const mismatch = (n: number) => props.expected !== null && qty(n) !== Number(props.expected?.[n] ?? 0)
</script>

<template>
  <section class="rounded-counter border border-paper-edge bg-white">
    <header class="flex items-center justify-between border-b border-paper-edge px-4 py-2.5">
      <div>
        <h3 class="field-label">{{ label }}</h3>
        <p class="mt-0.5 text-xs text-ink-700/60">{{ noteCount }} notes</p>
      </div>
      <div v-if="!readonly" class="flex gap-1.5">
        <button v-if="target !== null" type="button" @click="autoFill"
                class="rounded-counter border border-paper-edge px-2.5 py-1 text-xs font-medium text-ink-800 transition hover:border-ink-700">
          Fill from largest
        </button>
        <button type="button" @click="clear"
                class="rounded-counter px-2.5 py-1 text-xs font-medium text-ink-700/70 transition hover:text-debit">
          Clear
        </button>
      </div>
    </header>

    <ul class="divide-y divide-paper-edge">
      <li v-for="n in notes" :key="n"
          class="flex items-center gap-3 px-4 py-2.5 transition"
          :class="[
            qty(n) > 0 ? 'bg-ink-100/40' : '',
            mismatch(n) ? 'bg-debit/5' : '',
          ]">
        <span class="money w-24 shrink-0 text-sm font-semibold tabular-nums text-ink-900">{{ n.toLocaleString() }}</span>

        <span v-if="stock" class="hidden w-24 shrink-0 text-[11px] text-ink-700/55 sm:block">
          {{ (stock[n] ?? 0).toLocaleString() }} on hand
        </span>
        <span v-else-if="expected" class="hidden w-24 shrink-0 text-[11px] text-ink-700/55 sm:block">
          {{ (expected[n] ?? 0).toLocaleString() }} issued
        </span>

        <div class="ml-auto flex items-center gap-1">
          <button type="button" :disabled="readonly || qty(n) === 0" @click="set(n, qty(n) - 1)"
                  class="grid size-8 place-items-center rounded-counter border border-paper-edge text-ink-800 transition hover:border-ink-700 disabled:opacity-30">-</button>
          <input
            :value="qty(n)" :readonly="readonly" inputmode="numeric"
            @input="set(n, Number(($event.target as HTMLInputElement).value))"
            class="money h-8 w-14 rounded-counter border border-paper-edge text-center text-sm focus:border-ink-700 focus:outline-none focus:ring-2 focus:ring-ink-700/15"
            :class="mismatch(n) ? 'border-debit text-debit' : ''"
          />
          <button type="button" :disabled="readonly || qty(n) >= (stock?.[n] ?? Infinity)" @click="set(n, qty(n) + 1)"
                  class="grid size-8 place-items-center rounded-counter border border-paper-edge text-ink-800 transition hover:border-ink-700 disabled:opacity-30">+</button>
        </div>

        <MoneyText :value="lineTotal(n)"
                   class="w-32 shrink-0 text-right text-sm"
                   :class="qty(n) > 0 ? 'text-ink-900' : 'text-ink-300'" />
      </li>
    </ul>

    <footer
      class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 transition"
      :class="target === null ? 'border-paper-edge bg-paper'
              : balanced ? 'border-credit/30 bg-credit/5'
              : 'border-debit/30 bg-debit/5'"
    >
      <div>
        <p class="field-label">Counted</p>
        <MoneyText :value="total" class="text-lg font-semibold" />
      </div>

      <template v-if="target !== null">
        <div>
          <p class="field-label">Required</p>
          <MoneyText :value="target" class="text-lg font-semibold text-ink-700" />
        </div>
        <div class="text-right">
          <p class="field-label">{{ balanced ? 'Balanced' : difference > 0 ? 'Over by' : 'Short by' }}</p>
          <MoneyText v-if="!balanced" :value="Math.abs(difference)"
                     class="text-lg font-semibold" :class="difference > 0 ? 'text-held' : 'text-debit'" />
          <p v-else class="money text-lg font-semibold text-credit">OK 0</p>
        </div>
      </template>
    </footer>
  </section>
</template>
