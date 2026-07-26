<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { computed, onMounted, ref } from 'vue'

import { ApiRequestError, apiRequest } from '../lib/api'
import {
  readStoredToken,
  removeStoredToken,
  storeToken,
} from '../lib/auth-token'
import { useLocale } from '../lib/i18n'
import type { LoginResponse, SessionUser } from '../types'

type DemoUser = { role: string; username: string; password: string; pin: string }

const props = defineProps<{
  demoUsers?: DemoUser[] | null
  errors?: Record<string, string>
}>()

const username = ref('')
const password = ref('')
const showPassword = ref(false)
const submitting = ref(false)
const selectedDemo = ref<string | null>(null)
const { lang, setLang } = useLocale()
const notice = ref<string | null>(null)

const copy = computed(() => lang.value === 'en'
  ? {
      eyebrow: 'Doctor Phone Operations',
      title: 'Welcome back',
      sub: 'Sign in to manage today’s money movement with confidence.',
      username: 'Username',
      usernamePh: 'Enter your username',
      password: 'Password',
      passwordPh: 'Enter your password',
      show: 'Show',
      hide: 'Hide',
      login: 'Continue to workspace',
      loggingIn: 'Checking your access…',
      secure: 'Secure staff access',
      secureSub: 'Your session is protected by token authentication.',
      secureConnection: 'Secure connection',
      accountType: 'Staff account',
      localDemo: 'Local',
      demo: 'Quick sign in',
      demoHint: 'Local demo accounts only. Tap a role to fill the form.',
      sessionExpired: 'Your session expired. Please sign in again.',
      invalidLogin: 'We could not sign you in. Check your username and password.',
      workspace: 'One clear view for every counter',
      workspaceSub: 'Keep the main vault, teller floats, Cash In, Cash Out and reconciliation in sync.',
      feature1: 'Main vault control',
      feature2: 'Teller float tracking',
      feature3: 'Cash In / Cash Out workflow',
      footer: 'For authorised Doctor Phone staff only.',
    }
  : {
      eyebrow: 'ဒေါက်တာဖုန်း လုပ်ငန်းစီမံရေး',
      title: 'ပြန်လည်ကြိုဆိုပါတယ်',
      sub: 'ဒေါက်တာဖုန်းရဲ့ နေ့စဉ်ငွေစာရင်းတွေကို စီမံဖို့ ဝင်ရောက်ပါ။',
      username: 'အသုံးပြုသူအမည်',
      usernamePh: 'အသုံးပြုသူအမည် ရိုက်ထည့်ပါ',
      password: 'စကားဝှက်',
      passwordPh: 'စကားဝှက် ရိုက်ထည့်ပါ',
      show: 'ပြပါ',
      hide: 'ဖျောက်ပါ',
      login: 'စနစ်ထဲ ဝင်ရောက်မယ်',
      loggingIn: 'ဝင်ရောက်နေပါတယ်…',
      secure: 'လုံခြုံစွာ ဝင်ရောက်ထားပါတယ်',
      secureSub: 'သင့်အကောင့်ကို လုံခြုံစွာ ကာကွယ်ထားပါတယ်။',
      secureConnection: 'လုံခြုံစွာ ချိတ်ဆက်ထားပါတယ်',
      accountType: 'ဝန်ထမ်းအကောင့်',
      localDemo: 'စမ်းသုံးရန်',
      demo: 'အမြန်ဝင်ရန်',
      demoHint: 'စမ်းသုံးရန် အကောင့်ရှိပါက အောက်ကအမည်ကို နှိပ်ပါ။',
      sessionExpired: 'ဝင်ရောက်ချိန် သက်တမ်းကုန်သွားပါပြီ။ ပြန်ဝင်ပေးပါ။',
      invalidLogin: 'ဝင်လို့မရပါ။ အသုံးပြုသူအမည်နဲ့ စကားဝှက်ကို စစ်ပေးပါ။',
      workspace: 'ငွေစာရင်းတွေကို တစ်နေရာတည်းမှာ စီမံပါ',
      workspaceSub: 'နေ့စဉ်ငွေသွင်း၊ ငွေထုတ်နဲ့ ဝန်ထမ်းငွေခွဲစာရင်းတွေကို လွယ်ကူစွာ စီမံနိုင်ပါတယ်။',
      feature1: 'အဓိကငွေစာရင်း စီမံခြင်း',
      feature2: 'ဝန်ထမ်းငွေခွဲ စာရင်း',
      feature3: 'ငွေသွင်း / ငွေထုတ် စာရင်း',
      footer: 'ဒေါက်တာဖုန်း ဝန်ထမ်းများအတွက်သာ။',
    })

const canSubmit = computed(() => username.value.trim().length > 0 && password.value.length > 0)

const roleLabel = (role: string): string => ({
  admin: lang.value === 'mm' ? 'Admin' : 'Admin',
  cashier: lang.value === 'mm' ? 'Cashier' : 'Cashier',
  teller: lang.value === 'mm' ? 'Teller' : 'Teller',
}[role] ?? role)

function fill(user: DemoUser): void {
  username.value = user.username
  password.value = user.password
  selectedDemo.value = user.username
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
    notice.value = copy.value.sessionExpired
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
    return error.message || copy.value.invalidLogin
  }

  return copy.value.invalidLogin
}

function goToConsole(): void {
  const token = readStoredToken()

  router.visit('/dashboard', {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
  })
}
</script>

<template>
  <div class="min-h-[100svh] bg-[#f4f6f8] font-sans text-ink antialiased">
    <div class="mx-auto grid min-h-[100svh] max-w-[1440px] lg:grid-cols-[minmax(420px,0.86fr)_minmax(520px,1.14fr)]">
      <aside class="relative hidden overflow-hidden bg-[#0d1c2c] px-10 py-10 text-white lg:flex lg:flex-col xl:px-16">
        <div class="absolute -right-36 -top-36 size-[420px] rounded-full border border-white/10"></div>
        <div class="absolute -bottom-40 -left-32 size-[460px] rounded-full border border-white/10"></div>
        <div class="absolute right-24 top-40 size-2 rounded-full bg-[#e9b949] shadow-[0_0_0_8px_rgba(233,185,73,0.1)]"></div>

        <div class="relative flex items-center gap-3">
          <span class="grid size-11 place-items-center rounded-2xl bg-[#d92d45] text-sm font-black tracking-tight shadow-lg shadow-[#d92d45]/20">NL</span>
          <div class="leading-tight">
            <p class="font-burmese text-[18px] font-bold tracking-tight">ဒေါက်တာဖုန်း</p>
            <p class="mt-0.5 text-[11px] font-medium text-white/55">Money transfer operations</p>
          </div>
        </div>

        <div class="relative mt-auto max-w-lg pb-10 pt-24">
          <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.16em] text-[#f5c969]">
            <span class="size-1.5 rounded-full bg-[#f5c969]"></span>
            {{ copy.eyebrow }}
          </span>
          <h1 class="mt-6 text-4xl font-bold leading-[1.08] tracking-[-0.04em] xl:text-5xl">
            {{ copy.workspace }}
          </h1>
          <p class="mt-5 max-w-md text-sm leading-7 text-white/60">
            {{ copy.workspaceSub }}
          </p>

          <div class="mt-10 space-y-2.5">
            <div v-for="(feature, index) in [copy.feature1, copy.feature2, copy.feature3]" :key="feature" class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm text-white/75">
              <span class="grid size-7 shrink-0 place-items-center rounded-xl bg-[#43c78b]/15 text-xs font-bold text-[#73e1ac]">0{{ index + 1 }}</span>
              <span>{{ feature }}</span>
            </div>
          </div>
        </div>

        <p class="relative text-[11px] text-white/35">{{ copy.footer }}</p>
      </aside>

      <main class="flex min-h-[100svh] flex-col px-5 py-5 sm:px-8 lg:px-12 xl:px-20">
        <header class="flex items-center justify-between">
          <div class="flex items-center gap-2.5 lg:hidden">
            <span class="grid size-9 place-items-center rounded-xl bg-[#d92d45] text-xs font-black text-white">ဒ</span>
            <span class="font-burmese text-[15px] font-bold tracking-tight">ဒေါက်တာဖုန်း</span>
          </div>

          <div class="ml-auto flex items-center gap-3">
            <span class="hidden items-center gap-1.5 text-[11px] font-semibold text-slate sm:flex">
              <span class="size-1.5 rounded-full bg-[#43c78b]"></span>
              {{ copy.secureConnection }}
            </span>
            <div class="flex rounded-full border border-line bg-card p-1 shadow-sm" aria-label="Language selector">
              <button type="button" @click="setLang('en')" class="rounded-full px-3 py-1.5 text-[11px] font-bold transition" :class="lang === 'en' ? 'bg-[#0d1c2c] text-white' : 'text-slate hover:text-ink'">EN</button>
              <button type="button" @click="setLang('mm')" class="rounded-full px-3 py-1.5 text-[11px] font-bold transition" :class="lang === 'mm' ? 'bg-[#0d1c2c] text-white' : 'text-slate hover:text-ink'">မြန်မာ</button>
            </div>
          </div>
        </header>

        <section class="mx-auto flex w-full max-w-[460px] flex-1 flex-col justify-center py-10 lg:py-16">
          <div class="mb-8">
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#d92d45]">{{ copy.eyebrow }}</p>
            <h2 class="mt-3 text-3xl font-bold tracking-[-0.035em] text-[#0d1c2c] sm:text-4xl">{{ copy.title }}</h2>
            <p class="mt-3 max-w-sm text-sm leading-6 text-slate">{{ copy.sub }}</p>
          </div>

          <form class="space-y-5" novalidate @submit.prevent="submit">
            <div>
              <label class="bank-label" for="username">{{ copy.username }}</label>
              <div class="relative">
                <svg aria-hidden="true" class="pointer-events-none absolute left-4 top-1/2 size-[18px] -translate-y-1/2 text-slate/70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6.75a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.25a7.5 7.5 0 0 1 15 0" />
                </svg>
                <input id="username" v-model="username" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus class="bank-input bank-input-leading" :placeholder="copy.usernamePh" :aria-invalid="Boolean(notice || props.errors?.username)" :aria-describedby="notice || props.errors?.username || props.errors?.password ? 'login-error' : undefined" />
              </div>
            </div>

            <div>
              <div class="flex items-center justify-between">
                <label class="bank-label" for="password">{{ copy.password }}</label>
                <span class="mb-1.5 text-[11px] font-medium text-slate">{{ copy.accountType }}</span>
              </div>
              <div class="relative">
                <svg aria-hidden="true" class="pointer-events-none absolute left-4 top-1/2 size-[18px] -translate-y-1/2 text-slate/70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V8.25a4.5 4.5 0 0 0-9 0v2.25m-1.5 0h12a1.5 1.5 0 0 1 1.5 1.5v7.5a1.5 1.5 0 0 1-1.5 1.5H6a1.5 1.5 0 0 1-1.5-1.5V12a1.5 1.5 0 0 1 1.5-1.5Z" />
                </svg>
                <input id="password" v-model="password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" class="bank-input bank-input-leading pr-20" :placeholder="copy.passwordPh" :aria-invalid="Boolean(notice || props.errors?.password)" :aria-describedby="notice || props.errors?.username || props.errors?.password ? 'login-error' : undefined" />
                <button type="button" :aria-label="showPassword ? copy.hide : copy.show" @click="showPassword = !showPassword" class="absolute inset-y-0 right-0 px-4 text-xs font-bold text-slate transition hover:text-[#d92d45]">
                  {{ showPassword ? copy.hide : copy.show }}
                </button>
              </div>
            </div>

            <p v-if="notice || props.errors?.username || props.errors?.password" id="login-error" role="alert" class="flex gap-3 rounded-2xl border border-[#f3b8c1] bg-[#fff3f4] px-4 py-3.5 text-sm font-medium leading-5 text-[#a71932]">
              <svg aria-hidden="true" class="mt-0.5 size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M10.29 3.86 2.82 17.25a1.5 1.5 0 0 0 1.3 2.25h15.76a1.5 1.5 0 0 0 1.3-2.25L13.71 3.86a1.95 1.95 0 0 0-3.42 0Z" /></svg>
              <span>{{ notice ?? props.errors?.username ?? props.errors?.password }}</span>
            </p>

            <button type="submit" :disabled="!canSubmit || submitting" class="bank-button group flex w-full rounded-2xl bg-[#d92d45] py-4 text-sm font-bold text-white shadow-lg shadow-[#d92d45]/15 hover:bg-[#b92339] focus:outline-none focus:ring-4 focus:ring-[#d92d45]/20 disabled:bg-mist disabled:text-slate disabled:shadow-none">
              <span>{{ submitting ? copy.loggingIn : copy.login }}</span>
              <svg v-if="!submitting" aria-hidden="true" class="size-4 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" /></svg>
            </button>
          </form>

          <div v-if="props.demoUsers?.length" class="mt-8 border-t border-line pt-6">
            <div class="flex items-end justify-between gap-4">
              <div>
                <p class="text-xs font-bold text-[#0d1c2c]">{{ copy.demo }}</p>
                <p class="mt-1 text-[11px] leading-5 text-slate">{{ copy.demoHint }}</p>
              </div>
              <span class="rounded-full bg-[#eef1f4] px-2.5 py-1 text-[10px] font-bold tracking-wide text-slate">{{ copy.localDemo }}</span>
            </div>
            <div class="mt-4 grid grid-cols-3 gap-2">
              <button v-for="user in props.demoUsers" :key="user.username" type="button" @click="fill(user)" class="relative rounded-2xl border px-2 py-3 text-center transition focus:outline-none focus:ring-4 focus:ring-[#d92d45]/10" :class="selectedDemo === user.username ? 'border-[#d92d45] bg-[#fff3f4] text-[#a71932]' : 'border-line bg-card text-slate hover:border-[#d9929d] hover:bg-[#fff8f8]'">
                <span class="mx-auto grid size-8 place-items-center rounded-xl text-xs font-black" :class="selectedDemo === user.username ? 'bg-[#d92d45] text-white' : 'bg-[#eef1f4] text-[#0d1c2c]'">{{ user.role.slice(0, 1).toUpperCase() }}</span>
                <span class="mt-2 block text-[11px] font-bold">{{ roleLabel(user.role) }}</span>
                <span class="mt-0.5 block truncate text-[10px] opacity-65">{{ user.username }}</span>
                <span v-if="selectedDemo === user.username" class="absolute -right-1.5 -top-1.5 grid size-5 place-items-center rounded-full bg-[#d92d45] text-xs font-bold text-white">✓</span>
              </button>
            </div>
          </div>

          <div class="mt-8 flex items-center gap-3 text-[11px] text-slate">
            <span class="grid size-7 place-items-center rounded-full bg-[#e8f7f0] text-[#209966]">
              <svg aria-hidden="true" class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6" /></svg>
            </span>
            {{ copy.secure }}
            <span class="text-slate/40">•</span>
            <span>{{ copy.secureSub }}</span>
          </div>
        </section>

        <footer class="pb-2 text-center text-[10px] text-slate/60 lg:text-right">© {{ new Date().getFullYear() }} ဒေါက်တာဖုန်း</footer>
      </main>
    </div>
  </div>
</template>
