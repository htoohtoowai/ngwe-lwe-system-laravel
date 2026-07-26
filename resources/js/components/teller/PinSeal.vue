<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { useLocale } from '@/lib/i18n'

const props = defineProps<{
  open: boolean
  title: string
  detail: string
  busy?: boolean
  error?: string | null
}>()

const emit = defineEmits<{ confirm: [pin: string]; close: [] }>()
const { t } = useLocale()

const pin = ref('')
const input = ref<HTMLInputElement | null>(null)

watch(() => pin.value, v => {
 pin.value = v.replace(/\D/g, '').slice(0, 8) 
})

watch(() => props.open, open => {
  if (open) {
    pin.value = ''
    void nextTick(() => input.value?.focus())
  }
})
</script>

<template>
  <div v-if="open" class="fixed inset-0 z-50 grid place-items-center bg-ink-950/60 p-4"
       @keydown.esc="emit('close')">
    <div class="w-full max-w-sm rounded-counter border border-ink-800 bg-white shadow-2xl"
         role="dialog" aria-modal="true" aria-labelledby="pin-dialog-title" aria-describedby="pin-dialog-detail">
      <header class="flex items-center gap-2.5 border-b border-paper-edge px-5 py-4">
        <span class="grid size-8 place-items-center rounded-full bg-seal/15 text-sm font-semibold text-seal">PIN</span>
        <div>
          <h2 id="pin-dialog-title" class="font-display font-semibold text-ink-900">{{ title }}</h2>
          <p id="pin-dialog-detail" class="text-xs text-ink-700/65">{{ detail }}</p>
        </div>
      </header>

      <div class="px-5 py-5">
        <label class="field-label" for="teller-pin">{{ t('common.yourPin') }}</label>
        <input
          id="teller-pin" ref="input" v-model="pin" type="password" inputmode="numeric" autocomplete="off"
          placeholder="...."
          class="field-input money mt-1.5 text-center text-2xl tracking-[0.5em]"
          :aria-invalid="Boolean(error)"
          :aria-describedby="error ? 'pin-error' : 'pin-hint'"
          @keydown.enter="pin.length >= 4 && emit('confirm', pin)"
        />
        <p v-if="error" id="pin-error" class="mt-2 text-sm font-semibold text-debit" role="alert">{{ error }}</p>
        <p v-else id="pin-hint" class="mt-2 text-xs text-ink-700/60">{{ t('common.pinHint') }}</p>
      </div>

      <footer class="flex gap-2 border-t border-paper-edge px-5 py-3">
        <button type="button" @click="emit('close')"
                class="bank-button bank-button-secondary flex-1 rounded-counter">
          {{ t('common.cancel') }}
        </button>
        <button type="button" :disabled="pin.length < 4 || busy" @click="emit('confirm', pin)"
                class="bank-button flex-1 rounded-counter bg-ink-900 text-white hover:bg-ink-800 disabled:opacity-40">
          {{ busy ? t('common.verifying') : t('common.authorise') }}
        </button>
      </footer>
    </div>
  </div>
</template>
