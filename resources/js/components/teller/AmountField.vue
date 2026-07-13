<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  modelValue: number
  label?: string
  chips?: number[]
}>(), {
  label: 'Amount',
  chips: () => [5_000, 10_000, 50_000, 100_000, 500_000],
})

const emit = defineEmits<{ 'update:modelValue': [number] }>()

const UNITS: [number, string][] = [
  [10_000_000, 'ကုဋေ'],
  [100_000, 'သိန်း'],
  [10_000, 'သောင်း'],
  [1_000, 'ထောင်'],
  [100, 'ရာ'],
]

const reading = computed(() => {
  let n = Math.floor(props.modelValue || 0)

  if (n <= 0) {
return ''
}

  const parts: string[] = []

  for (const [unit, name] of UNITS) {
    const q = Math.floor(n / unit)

    if (q > 0) {
      parts.push(`${q.toLocaleString()} ${name}`)
      n -= q * unit
    }
  }

  if (n > 0) {
parts.push(`${n}`)
}

  return parts.join(' ')
})

function set(v: number) {
  emit('update:modelValue', Math.max(0, Math.floor(v || 0)))
}
</script>

<template>
  <div>
    <label class="field-label">{{ label }}</label>
    <input
      :value="modelValue || ''"
      type="number"
      min="0"
      step="100"
      inputmode="numeric"
      placeholder="0"
      class="field-input money mt-1.5 text-xl"
      @input="set(Number(($event.target as HTMLInputElement).value))"
    />

    <p class="mt-1.5 min-h-5 text-sm font-medium" :class="modelValue > 0 ? 'text-ink-800' : 'text-transparent'">
      {{ reading || '-' }} <span v-if="modelValue > 0" class="text-ink-700/50">ကျပ်</span>
    </p>

    <div class="mt-1 flex flex-wrap gap-1.5">
      <button v-for="c in chips" :key="c" type="button" @click="set((modelValue || 0) + c)"
              class="money rounded-full border border-paper-edge bg-white px-3 py-1 text-xs font-semibold text-ink-800 transition hover:border-ink-700 active:scale-95">
        +{{ c.toLocaleString() }}
      </button>
      <button type="button" @click="set(0)"
              class="rounded-full px-3 py-1 text-xs font-medium text-ink-700/60 transition hover:text-debit">
        Reset
      </button>
    </div>
  </div>
</template>
