<script setup lang="ts">
import { computed, ref } from 'vue'
import MoneyText from './MoneyText.vue'

const props = defineProps<{
  modelValue: number | null
  accounts: { id: number; name: string; company: string; balance: string }[]
  label?: string
  mustCover?: number | null
}>()

const emit = defineEmits<{ 'update:modelValue': [number | null] }>()

const open = ref(false)
const query = ref('')
const highlight = ref(0)

const selected = computed(() => props.accounts.find(a => a.id === props.modelValue) ?? null)

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  const list = q
    ? props.accounts.filter(a => `${a.company} ${a.name}`.toLowerCase().includes(q))
    : props.accounts

  return list.slice(0, 8)
})

const insufficient = computed(() =>
  selected.value !== null && props.mustCover != null && props.mustCover > 0 &&
  Number(selected.value.balance) < props.mustCover,
)

function choose(id: number) {
  emit('update:modelValue', id)
  open.value = false
  query.value = ''
}

function onKey(e: KeyboardEvent) {
  if (e.key === 'ArrowDown') {
    highlight.value = Math.min(highlight.value + 1, filtered.value.length - 1)
    e.preventDefault()
  } else if (e.key === 'ArrowUp') {
    highlight.value = Math.max(highlight.value - 1, 0)
    e.preventDefault()
  } else if (e.key === 'Enter' && filtered.value[highlight.value]) {
    choose(filtered.value[highlight.value].id)
    e.preventDefault()
  } else if (e.key === 'Escape') {
    open.value = false
  }
}
</script>

<template>
  <div class="relative">
    <label class="field-label">{{ label ?? 'Account' }}</label>

    <button v-if="selected && !open" type="button" @click="open = true"
            class="mt-1.5 flex w-full items-center justify-between rounded-counter border border-ink-700 bg-white px-3 py-2.5 text-left transition hover:bg-ink-100/50">
      <span>
        <span class="block text-[11px] font-semibold uppercase tracking-wide text-ink-700/60">{{ selected.company }}</span>
        <span class="font-medium text-ink-900">{{ selected.name }}</span>
      </span>
      <MoneyText :value="selected.balance" class="text-sm font-semibold" />
    </button>

    <template v-else>
      <input
        v-model="query"
        type="search"
        placeholder="Search company or account..."
        class="field-input mt-1.5"
        @focus="open = true"
        @keydown="onKey"
      />
      <ul v-if="open"
          class="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-counter border border-paper-edge bg-white shadow-lg">
        <li v-if="!filtered.length" class="px-3 py-6 text-center text-sm text-ink-700/60">
          No account matches "{{ query }}".
        </li>
        <li v-for="(a, i) in filtered" :key="a.id">
          <button type="button" @click="choose(a.id)" @mousemove="highlight = i"
                  class="flex w-full items-center justify-between px-3 py-2.5 text-left transition"
                  :class="i === highlight ? 'bg-ink-100' : ''">
            <span>
              <span class="block text-[11px] font-semibold uppercase tracking-wide text-ink-700/60">{{ a.company }}</span>
              <span class="text-sm font-medium text-ink-900">{{ a.name }}</span>
            </span>
            <MoneyText :value="a.balance" class="text-sm"
                       :class="mustCover != null && Number(a.balance) < mustCover ? 'text-debit' : 'text-ink-800'" />
          </button>
        </li>
      </ul>
    </template>

    <p v-if="insufficient" class="mt-1.5 text-sm text-debit">
      This account holds <MoneyText :value="selected!.balance" class="font-semibold" />. It is less than the
      <MoneyText :value="mustCover!" class="font-semibold" /> required.
    </p>
  </div>
</template>
