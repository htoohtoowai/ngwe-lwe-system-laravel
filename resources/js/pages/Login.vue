<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { computed, onMounted, ref } from 'vue'

import { ApiRequestError, apiRequest } from '../lib/api'
import {
  readStoredToken,
  removeStoredToken,
  storeToken,
} from '../lib/auth-token'
import type { LoginResponse, SessionUser } from '../types'

/**
 * Sign in — Myanmar internet-banking visual language.
 *
 * Matches the reference system: white card on a pale canvas, one red brand
 * color, gray filled inputs that light up with a red border on focus, pill
 * buttons, and a red-outline + check "selected" state (the same pattern the
 * reference uses on its amount chips).
 *
 * Demo credentials render ONLY when the controller passes `demoUsers`
 * (app()->environment('local')), and tap-to-fill — no passwords on screen.
 */
defineProps<{
  demoUsers?: { role: string; username: string; password: string; pin: string }[] | null
  errors?: Record<string, string>
}>()

const username = ref('')
const password = ref('')
const showPassword = ref(false)
const submitting = ref(false)
const selectedDemo = ref<string | null>(null)
const lang = ref<'en' | 'mm'>('en')
const notice = ref<string | null>(null)

const t = computed(() => lang.value === 'en'
  ? {
      title: 'Sign in',
      sub: 'Operations console for owners, cashiers and employees.',
      username: 'Username',
      usernamePh: 'Enter your username',
      password: 'Password',
      passwordPh: 'Enter your password',
      show: 'Show', hide: 'Hide',
      login: 'Sign in',
      loggingIn: 'Signing in…',
      demo: 'Demo accounts — local only',
      demoHint: 'Tap an account to fill the form. These exist only in local seed data.',
      tagline: 'Money transfer, counted note by note.',
      taglineSub: 'Floats, vault, exchange and reconciliation for your whole counter team — on desktop, tablet and mobile.',
    }
  : {
      title: 'အကောင့်ဝင်ရန်',
      sub: 'ပိုင်ရှင်၊ ငွေကိုင်နှင့် ဝန်ထမ်းများအတွက် လုပ်ငန်းစနစ်။',
      username: 'အသုံးပြုသူအမည်',
      usernamePh: 'အသုံးပြုသူအမည် ရိုက်ထည့်ပါ',
      password: 'စကားဝှက်',
      passwordPh: 'စကားဝှက် ရိုက်ထည့်ပါ',
      show: 'ပြရန်', hide: 'ဖျောက်ရန်',
      login: 'ဝင်မည်',
      loggingIn: 'ဝင်နေသည်…',
      demo: 'စမ်းသပ်အကောင့်များ — local သီးသန့်',
      demoHint: 'အကောင့်တစ်ခုကို နှိပ်ရုံဖြင့် ဖောင်ထဲ အလိုအလျောက်ဖြည့်ပေးသည်။',
      tagline: 'ငွေလွှဲလုပ်ငန်း၊ ငွေစက္ကူတစ်ရွက်ချင်း တိကျစွာ။',
      taglineSub: 'Float၊ vault၊ ငွေလဲနှုန်းနှင့် စာရင်းညှိခြင်းအားလုံးကို desktop, tablet, mobile တို့တွင် အသုံးပြုနိုင်သည်။',
    })

const canSubmit = computed(() => username.value.trim().length > 0 && password.value.length > 0)

function fill(u: { username: string; password: string }) {
  username.value = u.username
  password.value = u.password
  selectedDemo.value = u.username
  notice.value = null
}

onMounted(() => {
  const token = readStoredToken()

  if (token) {
    void restoreExistingSession(token)
  }
})

async function restoreExistingSession(token: string): Promise<void> {
  submitting.value = true

  try {
    await apiRequest<{ user: SessionUser }>('/api/auth/me', { token })
    goToConsole()
  } catch {
    removeStoredToken()
  } finally {
    submitting.value = false
  }
}

async function submit(): Promise<void> {
  if (!canSubmit.value || submitting.value) {
    return
  }

  submitting.value = true
  notice.value = null

  try {
    const response = await apiRequest<LoginResponse>('/api/auth/login', {
      method: 'POST',
      body: {
        username: username.value.trim(),
        password: password.value,
      },
    })

    storeToken(response.token)
    password.value = ''
    goToConsole()
  } catch (error) {
    notice.value = messageFromError(error)
  } finally {
    submitting.value = false
  }
}

function messageFromError(error: unknown): string {
  if (error instanceof ApiRequestError || error instanceof Error) {
    return error.message
  }

  return 'Login failed.'
}

function goToConsole(): void {
  const token = readStoredToken()

  router.visit('/dashboard', {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  })
}
</script>

<template>
  <div class="min-h-screen bg-canvas font-sans text-ink antialiased">

    <!-- Top bar: brand left, language right — same chrome as the reference -->
    <header class="mx-auto flex max-w-6xl items-center justify-between px-5 py-4">
      <div class="flex items-center gap-2.5">
        <span class="grid size-9 place-items-center rounded-full bg-brand text-sm font-bold text-white">NL</span>
        <div class="leading-tight">
          <p class="text-[15px] font-bold tracking-tight">Ngwe Lwe</p>
          <p class="text-[11px] font-medium text-slate">Internet Operations</p>
        </div>
      </div>

      <div class="flex items-center rounded-pill border border-line bg-card p-0.5 text-[12px] font-semibold shadow-sm">
        <button type="button" @click="lang = 'en'"
                class="rounded-pill px-3.5 py-1.5 transition"
                :class="lang === 'en' ? 'bg-brand text-white' : 'text-slate hover:text-ink'">
          English
        </button>
        <button type="button" @click="lang = 'mm'"
                class="rounded-pill px-3.5 py-1.5 transition"
                :class="lang === 'mm' ? 'bg-brand text-white' : 'text-slate hover:text-ink'">
          မြန်မာ
        </button>
      </div>
    </header>

    <main class="mx-auto grid max-w-6xl items-center gap-10 px-5 pb-16 pt-6 lg:grid-cols-[1.1fr_1fr] lg:pt-14">

      <!-- Brand side: white-first like the reference, red used sparingly -->
      <section class="hidden lg:block">
        <span class="inline-flex items-center gap-1.5 rounded-pill bg-brand px-3.5 py-1.5 text-xs font-bold text-white">
          Welcome
        </span>
        <h1 class="mt-5 max-w-lg text-4xl font-bold leading-[1.15] tracking-tight">
          {{ t.tagline }}
        </h1>
        <p class="mt-4 max-w-md text-[15px] leading-relaxed text-slate">
          {{ t.taglineSub }}
        </p>

        <!-- A quiet product proof: an account row exactly as it looks inside -->
        <div class="mt-10 max-w-sm rounded-2xl border border-line bg-card p-4 shadow-sm">
          <p class="text-[11px] font-semibold uppercase tracking-wide text-slate">Main vault</p>
          <div class="mt-2 flex items-baseline justify-between">
            <p class="text-sm font-semibold">Today's position</p>
            <p class="money text-lg font-bold text-balance">12,450,000 <span class="text-[11px] font-semibold text-slate">MMK</span></p>
          </div>
          <div class="mt-3 flex gap-2">
            <span class="rounded-pill bg-mist px-3 py-1 text-[11px] font-semibold text-slate">3 floats active</span>
            <span class="rounded-pill bg-mist px-3 py-1 text-[11px] font-semibold text-slate">2 pending cash-in</span>
          </div>
        </div>
      </section>

      <!-- Sign-in card -->
      <section class="mx-auto w-full max-w-md rounded-2xl border border-line bg-card p-7 shadow-sm sm:p-9">
        <h2 class="text-2xl font-bold tracking-tight">{{ t.title }}</h2>
        <p class="mt-1.5 text-sm text-slate">{{ t.sub }}</p>

        <form class="mt-7 space-y-5" @submit.prevent="submit">
          <div>
            <label class="bank-label" for="username">{{ t.username }}</label>
            <input
              id="username" v-model="username" type="text" autocomplete="username"
              autocapitalize="none" spellcheck="false" autofocus
              class="bank-input" :placeholder="t.usernamePh"
            />
          </div>

          <div>
            <label class="bank-label" for="password">{{ t.password }}</label>
            <div class="relative">
              <input
                id="password" v-model="password"
                :type="showPassword ? 'text' : 'password'" autocomplete="current-password"
                class="bank-input pr-20" :placeholder="t.passwordPh"
              />
              <button type="button" @click="showPassword = !showPassword"
                      class="absolute inset-y-0 right-0 px-4 text-xs font-bold text-slate transition hover:text-brand">
                {{ showPassword ? t.hide : t.show }}
              </button>
            </div>
          </div>

          <p v-if="notice || errors?.username || errors?.password"
             class="rounded-field bg-brand-soft px-4 py-3 text-sm font-medium text-brand-deep">
            {{ notice ?? errors?.username ?? errors?.password }}
          </p>

          <button type="submit" :disabled="!canSubmit || submitting"
                  class="w-full rounded-pill bg-brand py-3.5 text-[15px] font-bold text-white shadow-sm transition
                         hover:bg-brand-deep active:scale-[0.99]
                         disabled:cursor-not-allowed disabled:bg-mist disabled:text-slate disabled:shadow-none">
            {{ submitting ? t.loggingIn : t.login }}
          </button>
        </form>

        <!-- Demo accounts: reference's selected-chip pattern (red outline + check) -->
        <div v-if="demoUsers?.length" class="mt-8 border-t border-line pt-6">
          <p class="text-[11px] font-bold uppercase tracking-wide text-slate">{{ t.demo }}</p>

          <div class="mt-3 grid gap-2">
            <button v-for="u in demoUsers" :key="u.username" type="button" @click="fill(u)"
                    class="relative flex items-center justify-between rounded-field border-2 px-4 py-3 text-left transition"
                    :class="selectedDemo === u.username
                      ? 'border-brand bg-white'
                      : 'border-transparent bg-mist hover:bg-line/60'">
              <span class="flex items-center gap-3">
                <span class="rounded-pill px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                      :class="selectedDemo === u.username ? 'bg-brand text-white' : 'bg-card text-slate'">
                  {{ u.role }}
                </span>
                <span class="text-sm font-semibold">{{ u.username }}</span>
              </span>

              <!-- red check badge, as on the reference's selected amount chip -->
              <span v-if="selectedDemo === u.username"
                    class="absolute -right-1.5 -top-1.5 grid size-5 place-items-center rounded-full bg-brand text-[10px] font-bold text-white">
                ✓
              </span>
            </button>
          </div>
          <p class="mt-3 text-[11px] leading-relaxed text-slate">{{ t.demoHint }}</p>
        </div>
      </section>
    </main>
  </div>
</template>
