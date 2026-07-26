<script setup lang="ts">
import { computed, ref } from 'vue'
import { useLocale } from '@/lib/i18n'

/**
 * Account tile — the reference "From / To" block: gray tile with the account
 * name, number, green balance, and a white pill "Change" button that opens a
 * searchable picker. Balance is always visible because it is the number a
 * teller checks before every movement.
 */
const props = defineProps<{
  modelValue: number | null
  accounts: { id: number; company: string; name: string; number?: string; balance: string }[]
  label: string
  /** warn when the selected account's balance can't cover this */
  mustCover?: number | null
  /** ids to hide (e.g. the already-chosen source when picking destination) */
  exclude?: number[]
}>()

const emit = defineEmits<{ 'update:modelValue': [number | null] }>()
const { t } = useLocale()

const open = ref(false)
const query = ref('')

const selected = computed(() => props.accounts.find(a => a.id === props.modelValue) ?? null)

const list = computed(() => {
  const q = query.value.trim().toLowerCase()

  return props.accounts
    .filter(a => !(props.exclude ?? []).includes(a.id))
    .filter(a => !q || `${a.company} ${a.name} ${a.number ?? ''}`.toLowerCase().includes(q))
})

const insufficient = computed(() =>
  selected.value !== null && props.mustCover != null && props.mustCover > 0 &&
  Number(selected.value.balance) < props.mustCover)

const mmk = (v: string | number) => Number(v).toLocaleString()

function choose(id: number) {
  emit('update:modelValue', id)
  open.value = false
  query.value = ''
}
</script>

<template>
  <div>
    <p class="bank-label bank-required">{{ label }}</p>

    <!-- the tile -->
    <div class="flex items-center gap-3 rounded-field bg-mist px-4 py-3.5 transition focus-within:ring-2 focus-within:ring-brand/35"
         :class="insufficient ? 'ring-2 ring-brand' : ''">
      <template v-if="selected">
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-bold uppercase">{{ selected.name }}</p>
          <p v-if="selected.number" class="money text-[11px] text-slate">{{ selected.number }}</p>
          <p class="money mt-0.5 text-sm font-bold text-balance">
            {{ mmk(selected.balance) }} <span class="text-[10px]">MMK</span>
          </p>
        </div>
      </template>
      <p v-else class="flex-1 text-sm text-slate">{{ t('component.chooseAccount') }}</p>

      <button type="button" :aria-label="selected ? `${t('common.change')}: ${label}` : `${t('common.select')}: ${label}`" @click="open = true"
              class="bank-button bank-button-secondary min-h-9 shrink-0 px-4 py-1.5 text-xs shadow-sm">
        {{ selected ? t('common.change') : t('common.select') }}
      </button>
    </div>

    <p v-if="insufficient" class="mt-1.5 text-xs font-semibold text-brand">
      {{ t('component.accountBelowRequired') }}
    </p>

    <!-- picker -->
    <Teleport to="body">
      <div v-if="open" class="fixed inset-0 z-50 grid place-items-end bg-ink/40 p-0 sm:place-items-center sm:p-4"
           role="presentation" tabindex="-1"
           @keydown.esc="open = false">
        <div class="max-h-[80vh] w-full overflow-hidden rounded-t-2xl bg-card shadow-2xl sm:max-w-md sm:rounded-2xl" role="dialog" aria-modal="true" :aria-label="label">
          <div class="flex items-center justify-between border-b border-line px-5 py-4">
            <h3 class="text-base font-bold">{{ label }}</h3>
            <button type="button" class="bank-button grid size-9 place-items-center rounded-full p-0 hover:bg-mist"
                    :aria-label="t('common.close')" @click="open = false">✕</button>
          </div>
          <div class="border-b border-line p-3">
            <label class="sr-only" :for="`account-search-${label}`">{{ t('component.searchAccount') }}</label>
            <input :id="`account-search-${label}`" v-model="query" type="search" autocomplete="off" :placeholder="t('component.searchAccount')"
                   class="bank-input" autofocus />
          </div>
          <ul class="max-h-[50vh] divide-y divide-line overflow-y-auto">
            <li v-for="a in list" :key="a.id">
              <button type="button" :aria-pressed="a.id === modelValue" @click="choose(a.id)"
                      class="flex w-full items-center justify-between gap-3 px-5 py-3.5 text-left transition hover:bg-mist/60"
                      :class="a.id === modelValue ? 'bg-mist/60' : ''">
                <span class="min-w-0">
                  <span class="block truncate text-sm font-bold">{{ a.name }}</span>
                  <span class="text-[11px] text-slate">{{ a.company }}<template v-if="a.number"> · <span class="money">{{ a.number }}</span></template></span>
                </span>
                <span class="money shrink-0 text-sm font-bold"
                      :class="mustCover != null && Number(a.balance) < mustCover ? 'text-brand' : 'text-balance'">
                  {{ mmk(a.balance) }} <span class="text-[10px] text-slate">MMK</span>
                </span>
              </button>
            </li>
            <li v-if="!list.length" class="px-5 py-10 text-center text-sm text-slate">{{ t('common.noResults') }}</li>
          </ul>
        </div>
      </div>
    </Teleport>
  </div>
</template>
