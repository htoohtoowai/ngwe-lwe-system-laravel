<script setup lang="ts">
import { ref, watch } from 'vue'

defineProps<{
  open: boolean
  title: string
  detail: string
  busy?: boolean
  error?: string | null
}>()

const emit = defineEmits<{ confirm: [pin: string]; close: [] }>()

const pin = ref('')
const input = ref<HTMLInputElement | null>(null)

watch(() => pin.value, v => { pin.value = v.replace(/\D/g, '').slice(0, 8) })
</script>

<template>
  <div v-if="open" class="fixed inset-0 z-50 grid place-items-center bg-ink-950/60 p-4"
       @keydown.esc="emit('close')">
    <div class="w-full max-w-sm rounded-counter border border-ink-800 bg-white shadow-2xl">
      <header class="flex items-center gap-2.5 border-b border-paper-edge px-5 py-4">
        <span class="grid size-8 place-items-center rounded-full bg-seal/15 text-sm font-semibold text-seal">PIN</span>
        <div>
          <h2 class="font-display font-semibold text-ink-900">{{ title }}</h2>
          <p class="text-xs text-ink-700/65">{{ detail }}</p>
        </div>
      </header>

      <div class="px-5 py-5">
        <label class="field-label" for="pin">Your PIN</label>
        <input
          id="pin" ref="input" v-model="pin" type="password" inputmode="numeric" autocomplete="off"
          placeholder="...."
          class="field-input money mt-1.5 text-center text-2xl tracking-[0.5em]"
          @keydown.enter="pin.length >= 4 && emit('confirm', pin)"
        />
        <p v-if="error" class="mt-2 text-sm text-debit">{{ error }}</p>
        <p v-else class="mt-2 text-xs text-ink-700/60">4-8 digits. Three wrong attempts locks the action.</p>
      </div>

      <footer class="flex gap-2 border-t border-paper-edge px-5 py-3">
        <button type="button" @click="emit('close')"
                class="flex-1 rounded-counter border border-paper-edge py-2.5 text-sm font-medium text-ink-800 transition hover:border-ink-700">
          Cancel
        </button>
        <button type="button" :disabled="pin.length < 4 || busy" @click="emit('confirm', pin)"
                class="flex-1 rounded-counter bg-ink-900 py-2.5 text-sm font-semibold text-white transition hover:bg-ink-800 disabled:opacity-40">
          {{ busy ? 'Verifying...' : 'Authorise' }}
        </button>
      </footer>
    </div>
  </div>
</template>
