<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
  value: string | number
  currency?: 'MMK' | 'THB'
  signed?: 'credit' | 'debit' | null
}>(), { currency: 'MMK', signed: null })

const formatted = computed(() => {
  const n = Number(props.value ?? 0)
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: props.currency === 'THB' ? 2 : 0,
    maximumFractionDigits: props.currency === 'THB' ? 2 : 0,
  }).format(Math.abs(n))
})

const tone = computed(() =>
  props.signed === 'credit' ? 'text-credit'
  : props.signed === 'debit' ? 'text-debit'
  : '')
</script>

<template>
  <span class="money" :class="tone">
    <span v-if="signed === 'credit'">+</span><span v-else-if="signed === 'debit'">-</span>{{ formatted }}<span class="ml-1 text-[0.7em] font-sans font-medium opacity-55">{{ currency }}</span>
  </span>
</template>
