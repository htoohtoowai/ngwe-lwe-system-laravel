<script setup lang="ts">
import { computed } from 'vue'
import { useLocale } from '@/lib/i18n'

const props = withDefaults(defineProps<{
  modelValue: number
  label?: string
  id?: string
  chips?: number[]
}>(), {
  id: 'teller-amount',
  chips: () => [5_000, 10_000, 50_000, 100_000, 500_000],
})

const emit = defineEmits<{ 'update:modelValue': [number] }>()
const { t } = useLocale()

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
    <label class="field-label" :for="props.id">{{ label ?? t('component.amount') }}</label>
    <input
      :id="props.id"
      :value="modelValue || ''"
      type="number"
      min="0"
      step="100"
      inputmode="numeric"
      autocomplete="off"
      placeholder="0"
      class="field-input money mt-1.5 text-xl"
      aria-describedby="teller-amount-reading"
      @input="set(Number(($event.target as HTMLInputElement).value))"
    />

    <p id="teller-amount-reading" class="mt-1.5 min-h-5 text-sm font-medium" aria-live="polite" :class="modelValue > 0 ? 'text-ink-800' : 'text-transparent'">
      {{ reading || '-' }} <span v-if="modelValue > 0" class="text-ink-700/50">ကျပ်</span>
    </p>

    <div class="mt-1 flex flex-wrap gap-1.5">
      <button v-for="c in chips" :key="c" type="button" @click="set((modelValue || 0) + c)"
              class="bank-button bank-button-secondary money min-h-9 rounded-full px-3 py-1 text-xs">
        +{{ c.toLocaleString() }}
      </button>
      <button type="button" @click="set(0)"
              class="bank-button bank-button-danger min-h-9 rounded-full px-3 py-1 text-xs">
        Reset
      </button>
    </div>
  </div>
</template>
