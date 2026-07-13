<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { apiRequest } from '@/lib/api'
import { readStoredToken, removeStoredToken } from '@/lib/auth-token'

/**
 * Bank shell — reference layout, three breakpoints:
 *   Desktop (lg+): fixed left sidebar + top bar.
 *   Tablet/Mobile: hamburger → slide-over drawer, same top bar condensed.
 * Top bar: hamburger · logo · announcement ticker · bell(99+) · lang · avatar.
 */
const props = defineProps<{
  role: 'owner' | 'cashier' | 'employee'
  announcement?: string | null
  notificationCount?: number
}>()

const page = usePage<{
  auth?: {
    user?: {
      full_name?: string | null
      username?: string | null
    } | null
  }
}>()
const user = computed(() => page.props.auth?.user)
const drawer = ref(false)
const lang = ref<'en' | 'mm'>('en')

const NAV: { label: string; labelMm: string; href: string; icon: string; roles: string[] }[] = [
  { label: 'Home',            labelMm: 'ပင်မ',            href: '/dashboard',        icon: '⌂', roles: ['owner', 'cashier', 'employee'] },
  { label: 'Transactions',    labelMm: 'ငွေလွှဲမှတ်တမ်း',   href: '/transactions',     icon: '⇄', roles: ['owner', 'cashier', 'employee'] },
  { label: 'Accounts',        labelMm: 'အကောင့်များ',      href: '/accounts',         icon: '▦', roles: ['owner', 'cashier'] },
  { label: 'Floats',          labelMm: 'Float များ',       href: '/floats',           icon: '▣', roles: ['owner', 'cashier', 'employee'] },
  { label: 'Vault',           labelMm: 'ငွေတိုက်',         href: '/vault',            icon: '◫', roles: ['owner', 'cashier'] },
  { label: 'Exchange Rates',  labelMm: 'ငွေလဲနှုန်း',      href: '/exchange-rates',   icon: '¤', roles: ['owner', 'cashier', 'employee'] },
  { label: 'Reports',         labelMm: 'အစီရင်ခံစာ',       href: '/reports',          icon: '≣', roles: ['owner'] },
  { label: 'Reconciliation',  labelMm: 'စာရင်းညှိ',        href: '/reconciliation',   icon: '✓', roles: ['owner', 'cashier'] },
  { label: 'Settings',        labelMm: 'ချိန်ညှိချက်',      href: '/settings',         icon: '⚙', roles: ['owner'] },
]

const nav = computed(() => NAV.filter(n => n.roles.includes(props.role)))
const hrefFor = (href: string) => {
  if (href === '/transactions') {
    return props.role === 'employee' ? '/employee' : '/'
  }

  if (href === '/floats') {
    return props.role === 'employee' ? '/employee/float' : '/'
  }

  if (href === '/exchange-rates') {
    return props.role === 'employee' ? '/employee/exchange' : '/'
  }

  if (['/accounts', '/vault', '/reports', '/reconciliation', '/settings', '/notifications'].includes(href)) {
    return props.role === 'employee' ? '/dashboard' : '/'
  }

  return href
}
function authHeaders(): Record<string, string> {
  const token = readStoredToken()

  return token ? { Authorization: `Bearer ${token}` } : {}
}
const isActive = (href: string) => {
  const resolved = hrefFor(href)

  return page.url === resolved || (resolved !== '/dashboard' && page.url.startsWith(resolved))
}

async function signOut() {
  const token = readStoredToken()

  if (token) {
    await apiRequest('/api/auth/logout', {
      method: 'POST',
      token,
    }).catch(() => undefined)
  }

  removeStoredToken()
  router.visit('/login')
}
</script>

<template>
  <div class="min-h-screen bg-canvas font-sans text-ink antialiased">

    <!-- ===== Top bar ===== -->
    <header class="sticky top-0 z-40 border-b border-line bg-card">
      <div class="flex h-14 items-center gap-3 px-4 lg:px-6">
        <!-- hamburger: tablet & mobile -->
        <button type="button" class="grid size-9 place-items-center rounded-full text-xl transition hover:bg-mist lg:hidden"
                aria-label="Menu" @click="drawer = true">≡</button>

        <Link href="/dashboard" :headers="authHeaders()" class="flex items-center gap-2">
          <span class="grid size-8 place-items-center rounded-full bg-brand text-xs font-bold text-white">NL</span>
          <span class="hidden text-[15px] font-bold tracking-tight sm:block">Ngwe Lwe</span>
        </Link>

        <!-- announcement ticker -->
        <div v-if="announcement" class="ml-2 hidden min-w-0 flex-1 items-center gap-2 md:flex">
          <span class="shrink-0 rounded-pill bg-brand px-3 py-1 text-[11px] font-bold text-white">Announcement</span>
          <p class="truncate text-[13px] text-slate">{{ announcement }}</p>
        </div>
        <div v-else class="flex-1" />
        <div v-if="announcement" class="min-w-0 flex-1 md:hidden" />

        <div class="ml-auto flex items-center gap-1.5 sm:gap-2">
          <!-- bell + 99+ badge -->
          <Link href="/dashboard" :headers="authHeaders()" class="relative grid size-9 place-items-center rounded-full transition hover:bg-mist" aria-label="Notifications">
            <span class="text-lg">🔔</span>
            <span v-if="notificationCount"
                  class="absolute -right-0.5 -top-0.5 rounded-pill bg-brand px-1.5 py-px text-[9px] font-bold text-white">
              {{ notificationCount > 99 ? '99+' : notificationCount }}
            </span>
          </Link>

          <!-- language -->
          <div class="hidden items-center rounded-pill border border-line p-0.5 text-[11px] font-bold sm:flex">
            <button type="button" class="rounded-pill px-2.5 py-1 transition"
                    :class="lang === 'en' ? 'bg-brand text-white' : 'text-slate'" @click="lang = 'en'">EN</button>
            <button type="button" class="rounded-pill px-2.5 py-1 transition"
                    :class="lang === 'mm' ? 'bg-brand text-white' : 'text-slate'" @click="lang = 'mm'">မြန်မာ</button>
          </div>

          <!-- avatar -->
          <div class="flex items-center gap-2 rounded-pill border border-line py-1 pl-1 pr-2.5 sm:pr-3">
            <span class="grid size-7 place-items-center rounded-full bg-brand text-[11px] font-bold uppercase text-white">
              {{ (user?.full_name ?? user?.username ?? '?').slice(0, 1) }}
            </span>
            <span class="hidden max-w-28 truncate text-[13px] font-bold sm:block">
              {{ user?.full_name ?? user?.username }}
            </span>
            <button type="button" class="text-[11px] font-bold text-slate transition hover:text-brand" @click="signOut">
              ⏻
            </button>
          </div>
        </div>
      </div>

      <!-- announcement on mobile: its own row -->
      <div v-if="announcement" class="flex items-center gap-2 border-t border-line px-4 py-1.5 md:hidden">
        <span class="shrink-0 rounded-pill bg-brand px-2.5 py-0.5 text-[10px] font-bold text-white">Announcement</span>
        <p class="truncate text-xs text-slate">{{ announcement }}</p>
      </div>
    </header>

    <div class="mx-auto flex max-w-[1400px]">
      <!-- ===== Sidebar: desktop ===== -->
      <aside class="sticky top-14 hidden h-[calc(100vh-3.5rem)] w-60 shrink-0 border-r border-line bg-card lg:block">
        <nav class="p-3">
          <ul class="space-y-0.5">
            <li v-for="item in nav" :key="item.href">
              <Link :href="hrefFor(item.href)"
                    :headers="authHeaders()"
                    class="flex items-center gap-3 rounded-field px-3.5 py-2.5 text-[13.5px] font-semibold transition"
                    :class="isActive(item.href) ? 'bg-mist text-ink' : 'text-slate hover:bg-mist/60 hover:text-ink'">
                <span class="w-4 text-center" :class="isActive(item.href) ? 'text-brand' : ''">{{ item.icon }}</span>
                {{ lang === 'mm' ? item.labelMm : item.label }}
              </Link>
            </li>
          </ul>
        </nav>
      </aside>

      <!-- ===== Drawer: tablet & mobile ===== -->
      <Teleport to="body">
        <div v-if="drawer" class="fixed inset-0 z-50 lg:hidden">
          <div class="absolute inset-0 bg-ink/40" @click="drawer = false" />
          <aside class="absolute inset-y-0 left-0 w-72 max-w-[85vw] bg-card shadow-2xl">
            <div class="flex h-14 items-center justify-between border-b border-line px-4">
              <div class="flex items-center gap-2">
                <span class="grid size-8 place-items-center rounded-full bg-brand text-xs font-bold text-white">NL</span>
                <span class="font-bold">Ngwe Lwe</span>
              </div>
              <button type="button" class="grid size-9 place-items-center rounded-full text-lg hover:bg-mist"
                      aria-label="Close" @click="drawer = false">✕</button>
            </div>
            <nav class="p-3">
              <ul class="space-y-0.5">
                <li v-for="item in nav" :key="item.href">
                  <Link :href="hrefFor(item.href)" :headers="authHeaders()" @click="drawer = false"
                        class="flex items-center gap-3 rounded-field px-3.5 py-3 text-sm font-semibold transition"
                        :class="isActive(item.href) ? 'bg-mist text-ink' : 'text-slate hover:bg-mist/60'">
                    <span class="w-4 text-center" :class="isActive(item.href) ? 'text-brand' : ''">{{ item.icon }}</span>
                    {{ lang === 'mm' ? item.labelMm : item.label }}
                  </Link>
                </li>
              </ul>
            </nav>
          </aside>
        </div>
      </Teleport>

      <!-- ===== Content ===== -->
      <main class="min-w-0 flex-1 px-4 py-5 lg:px-8 lg:py-7">
        <slot :lang="lang" />
      </main>
    </div>
  </div>
</template>
