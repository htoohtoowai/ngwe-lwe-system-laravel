<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { apiRequest } from '@/lib/api'
import { readStoredToken, removeStoredToken } from '@/lib/auth-token'
import { useLocale } from '@/lib/i18n'

/**
 * Bank shell — reference layout, three breakpoints:
 *   Desktop (lg+): fixed left sidebar + top bar.
 *   Tablet/Mobile: hamburger → slide-over drawer, same top bar condensed.
 * Top bar: hamburger · logo · announcement ticker · bell(99+) · lang · avatar.
 */
const props = defineProps<{
  role: 'admin' | 'cashier' | 'teller'
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
const mobileMenuButton = ref<HTMLButtonElement | null>(null)
const mobileCloseButton = ref<HTMLButtonElement | null>(null)
const sidebarCollapsed = ref(false)
const { lang, setLang, t } = useLocale()
const SIDEBAR_STORAGE_KEY = 'ngwe-lwe:bank-sidebar-collapsed'

type NavSection = 'Banking' | 'Office' | 'Admin'
type NavIcon =
  | 'overview'
  | 'counter'
  | 'cashIn'
  | 'cashOut'
  | 'transfer'
  | 'accounts'
  | 'exchange'
  | 'floats'
  | 'vault'
  | 'reconcile'
  | 'reports'
  | 'settings'
type NavItem = {
  label: string
  labelMm: string
  href: string
  icon: NavIcon
  roles: string[]
  section: NavSection
}

const NAV: NavItem[] = [
  { label: 'Overview', labelMm: 'အနှစ်ချုပ်', href: '/dashboard', icon: 'overview', roles: ['admin', 'cashier', 'teller'], section: 'Banking' },
  { label: 'Cashier Operations', labelMm: 'Cashier ငွေစီမံခန့်ခွဲမှု', href: '/cashier', icon: 'counter', roles: ['cashier'], section: 'Banking' },
  { label: 'Profile', labelMm: 'ကိုယ်ရေးအချက်အလက်', href: '/cashier/profile', icon: 'settings', roles: ['cashier'], section: 'Office' },
  { label: 'Counter', labelMm: 'ကောင်တာ', href: '/teller', icon: 'counter', roles: ['teller'], section: 'Banking' },
  { label: 'My Float', labelMm: 'ကိုယ်ပိုင်ငွေသား', href: '/teller/float', icon: 'floats', roles: ['teller'], section: 'Banking' },
  { label: 'Cash In', labelMm: 'ငွေသွင်း', href: '/transactions/cash-in', icon: 'cashIn', roles: ['teller'], section: 'Banking' },
  { label: 'Cash Out', labelMm: 'ငွေထုတ်', href: '/transactions/cash-out', icon: 'cashOut', roles: ['teller'], section: 'Banking' },
  { label: 'Transfer', labelMm: 'လွှဲပြောင်း', href: '/transactions/transfer', icon: 'transfer', roles: ['teller'], section: 'Banking' },
  { label: 'Exchange', labelMm: 'ငွေလဲ', href: '/transactions/exchange', icon: 'exchange', roles: ['teller'], section: 'Banking' },
]
const iconPaths: Record<NavIcon, string[]> = {
  overview: ['M4 5.5A1.5 1.5 0 0 1 5.5 4h5v7h-6.5V5.5Z', 'M13.5 4h5A1.5 1.5 0 0 1 20 5.5V9h-6.5V4Z', 'M4 13.5h6.5V20h-5A1.5 1.5 0 0 1 4 18.5v-5Z', 'M13.5 11.5H20v7a1.5 1.5 0 0 1-1.5 1.5h-5v-8.5Z'],
  counter: ['M5 6h14v12H5V6Z', 'M8 10h8', 'M8 14h3', 'M14 14h2'],
  cashIn: ['M12 5v14', 'M7 10l5-5 5 5', 'M5 19h14'],
  cashOut: ['M12 19V5', 'M7 14l5 5 5-5', 'M5 5h14'],
  transfer: ['M7 7h11m0 0-3-3m3 3-3 3', 'M17 17H6m0 0 3 3m-3-3 3-3'],
  accounts: ['M4 7.5A2.5 2.5 0 0 1 6.5 5H19v14H6.5A2.5 2.5 0 0 1 4 16.5v-9Z', 'M4 8h13', 'M15 13h4'],
  exchange: ['M8 7h8m0 0-2-2m2 2-2 2', 'M16 17H8m0 0 2 2m-2-2 2-2', 'M12 4v16'],
  floats: ['M6 8c0-2 2.7-4 6-4s6 2 6 4-2.7 4-6 4-6-2-6-4Z', 'M6 8v5c0 2 2.7 4 6 4s6-2 6-4V8', 'M6 13v3c0 2 2.7 4 6 4s6-2 6-4v-3'],
  vault: ['M5 8h14v11H5V8Z', 'M8 8V5h8v3', 'M12 12.5a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z'],
  reconcile: ['M5 7h9', 'M5 12h14', 'M5 17h7', 'M16 6l3 3-3 3'],
  reports: ['M6 4h9l3 3v13H6V4Z', 'M14 4v4h4', 'M9 13h6', 'M9 17h4'],
  settings: ['M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z', 'M19.4 15a1.8 1.8 0 0 0 .36 1.98l-1.8 3.12a1.8 1.8 0 0 0-1.98.2 1.8 1.8 0 0 0-.76 1.67H8.8a1.8 1.8 0 0 0-.76-1.67 1.8 1.8 0 0 0-1.98-.2l-1.8-3.12A1.8 1.8 0 0 0 4.6 15a1.8 1.8 0 0 0-1.4-1.27v-3.46A1.8 1.8 0 0 0 4.6 9a1.8 1.8 0 0 0-.36-1.98l1.8-3.12a1.8 1.8 0 0 0 1.98-.2A1.8 1.8 0 0 0 8.8 2h6.4a1.8 1.8 0 0 0 .76 1.67 1.8 1.8 0 0 0 1.98.2l1.8 3.12A1.8 1.8 0 0 0 19.4 9a1.8 1.8 0 0 0 1.4 1.27v3.46A1.8 1.8 0 0 0 19.4 15Z'],
}

const nav = computed(() => NAV.filter(n => n.roles.includes(props.role)))
const navSections = computed(() => {
  const sections: NavSection[] = ['Banking', 'Office', 'Admin']

  return sections
    .map(section => ({
      label: section,
      items: nav.value.filter(item => item.section === section),
    }))
    .filter(section => section.items.length > 0)
})
const sectionLabel = (section: NavSection) => t(({ Banking: 'section.banking', Office: 'section.office', Admin: 'section.admin' }[section]), section)
const navLabel = (item: NavItem) => lang.value === 'mm' ? item.labelMm : item.label
const roleLabel = computed(() => t(`role.${props.role}`))
const displayName = computed(() => user.value?.full_name ?? user.value?.username ?? roleLabel.value)
const avatarInitial = computed(() => displayName.value.trim().slice(0, 1).toUpperCase())
const notificationLabel = computed(() => {
  const count = props.notificationCount ?? 0

  return count > 0 ? `${count} ${t('common.pendingNotifications')}` : t('common.noPendingNotifications')
})
const hrefFor = (href: string) => {
  if (href === '/transactions') {
    return '/transactions/transfer'
  }

  if (href === '/exchange-rates') {
    return '/transactions/exchange'
  }

  return href
}
function authHeaders(): Record<string, string> {
  const token = readStoredToken()

  return token ? { Authorization: `Bearer ${token}` } : {}
}
const isActive = (href: string) => {
  if (href === '/transactions') {
    return page.url.startsWith('/transactions')
  }

  const resolved = hrefFor(href)

  return page.url === resolved || (resolved !== '/dashboard' && page.url.startsWith(resolved))
}

onMounted(() => {
  sidebarCollapsed.value = window.localStorage.getItem(SIDEBAR_STORAGE_KEY) === '1'
})

watch(drawer, async isOpen => {
  document.body.style.overflow = isOpen ? 'hidden' : ''

  if (isOpen) {
    await nextTick()
    mobileCloseButton.value?.focus()
  } else {
    mobileMenuButton.value?.focus()
  }
})

onBeforeUnmount(() => {
  document.body.style.overflow = ''
})

watch(sidebarCollapsed, collapsed => {
  window.localStorage.setItem(SIDEBAR_STORAGE_KEY, collapsed ? '1' : '0')
})

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
    <a
      href="#bank-main-content"
      class="sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:not-sr-only focus:rounded-lg focus:bg-brand focus:px-4 focus:py-2 focus:text-sm focus:font-bold focus:text-white"
    >
      Skip to content
    </a>

    <!-- ===== Top bar ===== -->
    <header class="sticky top-0 z-40 border-b border-line bg-card">
      <div class="flex h-14 items-center gap-3 px-4 lg:px-6">
        <!-- hamburger: tablet & mobile -->
        <button
          ref="mobileMenuButton"
          type="button"
          class="grid size-9 place-items-center rounded-full text-slate transition hover:bg-mist hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/70 lg:hidden"
          :aria-label="t('common.openMenu')"
          aria-controls="mobile-bank-menu"
          :aria-expanded="drawer"
          @click="drawer = true"
        >
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
            <path d="M4 7h16" />
            <path d="M4 12h16" />
            <path d="M4 17h16" />
          </svg>
        </button>

        <Link href="/dashboard" :headers="authHeaders()" class="flex items-center gap-2">
          <span class="grid size-8 place-items-center rounded-full bg-brand text-xs font-bold text-white">DP</span>
          <span class="hidden text-[15px] font-bold tracking-tight sm:block">{{ t('brand.name') }}</span>
        </Link>

        <!-- announcement ticker -->
        <div v-if="announcement" class="ml-2 hidden min-w-0 flex-1 items-center gap-2 md:flex">
          <span class="shrink-0 rounded-pill bg-brand px-3 py-1 text-[11px] font-bold text-white">{{ t('common.announcement') }}</span>
          <p class="truncate text-[13px] text-slate">{{ announcement }}</p>
        </div>
        <div v-else class="flex-1" />
        <div v-if="announcement" class="min-w-0 flex-1 md:hidden" />

        <div class="ml-auto flex items-center gap-1.5 sm:gap-2">
          <!-- bell + 99+ badge -->
          <Link
            href="/dashboard"
            :headers="authHeaders()"
            class="relative grid size-10 place-items-center rounded-full border border-line bg-card text-slate transition hover:border-brand/30 hover:bg-brand-soft hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/70"
            :aria-label="notificationLabel"
            :title="notificationLabel"
          >
            <svg class="size-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
              <path d="M10 21h4" />
            </svg>
            <span v-if="notificationCount"
                  class="absolute -right-0.5 -top-0.5 rounded-pill bg-brand px-1.5 py-px text-[9px] font-bold text-white">
              {{ notificationCount > 99 ? '99+' : notificationCount }}
            </span>
          </Link>

          <!-- language -->
          <div class="hidden items-center rounded-pill border border-line bg-card p-0.5 text-[11px] font-bold shadow-sm sm:flex" :aria-label="t('common.language')">
            <button
              type="button"
              class="rounded-pill px-2.5 py-1 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/70"
              :class="lang === 'en' ? 'bg-brand text-white' : 'text-slate hover:bg-mist hover:text-ink'"
              :aria-pressed="lang === 'en'"
              :title="t('language.english')"
              @click="setLang('en')"
            >
              EN
            </button>
            <button
              type="button"
              class="rounded-pill px-2.5 py-1 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/70"
              :class="lang === 'mm' ? 'bg-brand text-white' : 'text-slate hover:bg-mist hover:text-ink'"
              :aria-pressed="lang === 'mm'"
              :title="t('language.myanmar')"
              @click="setLang('mm')"
            >
              မြန်မာ
            </button>
          </div>

          <!-- avatar -->
          <div class="flex items-center gap-1.5 rounded-pill border border-line bg-card py-1 pl-1 pr-1 shadow-sm sm:gap-2 sm:pr-2">
            <span class="grid size-7 place-items-center rounded-full bg-brand text-[11px] font-bold uppercase text-white">
              {{ avatarInitial }}
            </span>
            <span class="hidden max-w-28 truncate text-[13px] font-bold sm:block">
              {{ displayName }}
            </span>
            <button
              type="button"
              class="grid size-7 place-items-center rounded-full text-slate transition hover:bg-brand-soft hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/70"
              :aria-label="t('common.signOut')"
              :title="t('common.signOut')"
              @click="signOut"
            >
              <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 3v9" />
                <path d="M6.7 6.7a7.5 7.5 0 1 0 10.6 0" />
              </svg>
            </button>
          </div>
        </div>
      </div>

      <!-- announcement on mobile: its own row -->
      <div v-if="announcement" class="flex items-center gap-2 border-t border-line px-4 py-1.5 md:hidden">
        <span class="shrink-0 rounded-pill bg-brand px-2.5 py-0.5 text-[10px] font-bold text-white">{{ t('common.announcement') }}</span>
        <p class="truncate text-xs text-slate">{{ announcement }}</p>
      </div>
    </header>

    <div class="flex">
      <!-- ===== Sidebar: desktop ===== -->
      <aside
        :aria-label="t('common.desktopNavigation')"
        class="sticky top-14 hidden h-[calc(100vh-3.5rem)] shrink-0 border-r border-white/10 bg-[#2f3035] text-white shadow-[0_14px_32px_-24px_rgba(15,23,42,0.9)] transition-[width] duration-200 lg:block"
        :class="sidebarCollapsed ? 'w-[84px]' : 'w-[260px]'"
        :data-desktop-drawer="sidebarCollapsed ? 'collapsed' : 'expanded'"
      >
        <div class="flex h-full flex-col">
          <div class="border-b border-white/10 py-5" :class="sidebarCollapsed ? 'px-2' : 'px-4'">
            <div class="flex items-center justify-between gap-2" :class="sidebarCollapsed ? 'flex-col' : ''">
              <span class="grid size-11 place-items-center rounded-[1.05rem] bg-brand text-sm font-black text-white shadow-[0_10px_24px_-14px_rgba(179,27,44,0.85)]">DP</span>
              <div v-if="!sidebarCollapsed" class="min-w-0">
                <p class="truncate text-base font-black tracking-tight">ဒေါက်တာဖုန်း</p>
                <p class="mt-0.5 text-[10px] font-bold uppercase tracking-[0.18em] text-white/45">{{ t('brand.operations') }}</p>
              </div>
              <button
                type="button"
                class="grid size-8 shrink-0 place-items-center rounded-lg border border-white/10 text-[11px] font-black text-white/60 transition hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/70"
                :aria-label="sidebarCollapsed ? t('common.expandMenu') : t('common.collapseMenu')"
                aria-controls="desktop-bank-menu"
                :aria-expanded="!sidebarCollapsed"
                :title="sidebarCollapsed ? t('common.expandMenu') : t('common.collapseMenu')"
                @click="sidebarCollapsed = !sidebarCollapsed"
              >
                <svg
                  class="size-4"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2.2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  aria-hidden="true"
                >
                  <path v-if="sidebarCollapsed" d="m7 6 6 6-6 6" />
                  <path v-if="sidebarCollapsed" d="m13 6 6 6-6 6" />
                  <path v-if="!sidebarCollapsed" d="m11 6-6 6 6 6" />
                  <path v-if="!sidebarCollapsed" d="m17 6-6 6 6 6" />
                </svg>
              </button>
            </div>
          </div>

          <nav id="desktop-bank-menu" class="min-h-0 flex-1 overflow-y-auto px-3 py-4">
            <section
              v-for="section in navSections"
              :key="section.label"
              class="last:mb-0"
              :class="sidebarCollapsed ? 'mb-2' : 'mb-5'"
            >
              <p v-if="!sidebarCollapsed" class="px-3 pb-2 text-[10px] font-black uppercase tracking-[0.2em] text-white/40">{{ sectionLabel(section.label) }}</p>
              <ul class="space-y-1">
                <li v-for="item in section.items" :key="item.href">
                  <Link
                    :href="hrefFor(item.href)"
                    :headers="authHeaders()"
                    :aria-current="isActive(item.href) ? 'page' : undefined"
                    :aria-label="navLabel(item)"
                    :title="navLabel(item)"
                    class="group relative flex items-center gap-3 rounded-xl border px-3 py-2.5 text-[13.5px] font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/70"
                    :class="isActive(item.href)
                      ? 'border-white/10 bg-white/10 text-white shadow-[inset_3px_0_0_#b31b2c]'
                      : 'border-transparent text-white/65 hover:bg-white/10 hover:text-white'"
                  >
                    <span
                      class="absolute inset-y-2 left-0 w-1 rounded-r-full transition"
                      :class="isActive(item.href) ? 'bg-brand' : 'bg-transparent'"
                    />
                    <span
                      class="grid size-9 shrink-0 place-items-center rounded-xl transition"
                      :class="isActive(item.href) ? 'bg-brand text-white shadow-sm' : 'bg-white/10 text-white/60 group-hover:bg-white/15 group-hover:text-white'"
                    >
                      <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path v-for="path in iconPaths[item.icon]" :key="path" :d="path" />
                      </svg>
                    </span>
                    <span v-if="!sidebarCollapsed" class="min-w-0 flex-1 truncate">{{ navLabel(item) }}</span>
                    <span
                      v-if="sidebarCollapsed"
                      class="pointer-events-none absolute left-[calc(100%+0.55rem)] top-1/2 z-50 -translate-y-1/2 whitespace-nowrap rounded-lg bg-ink px-2.5 py-1.5 text-xs font-bold text-white opacity-0 shadow-lg ring-1 ring-white/10 transition group-hover:opacity-100 group-focus-visible:opacity-100"
                    >
                      {{ navLabel(item) }}
                    </span>
                    <span
                      v-if="!sidebarCollapsed"
                      class="grid size-6 place-items-center rounded-lg text-base leading-none transition"
                      :class="isActive(item.href) ? 'bg-brand text-white' : 'bg-white/10 text-white/40 group-hover:bg-white/15 group-hover:text-white'"
                    >
                      ›
                    </span>
                  </Link>
                </li>
              </ul>
            </section>
          </nav>

        </div>
      </aside>

      <!-- ===== Drawer: tablet & mobile ===== -->
      <Teleport to="body">
        <div v-if="drawer" class="fixed inset-0 z-50 lg:hidden" @keydown.esc="drawer = false">
          <button type="button" class="absolute inset-0 bg-ink/40" :aria-label="t('common.closeMenu')" @click="drawer = false" />
          <aside id="mobile-bank-menu" class="absolute inset-y-0 left-0 flex w-[260px] max-w-[85vw] flex-col bg-[#2f3035] text-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="mobile-bank-menu-title">
            <div class="flex h-14 items-center justify-between border-b border-white/10 px-4">
              <div class="flex items-center gap-2">
                <span class="grid size-8 place-items-center rounded-lg bg-brand text-xs font-bold text-white">DP</span>
                <span id="mobile-bank-menu-title" class="font-bold">ဒေါက်တာဖုန်း</span>
              </div>
              <button ref="mobileCloseButton" type="button" class="grid size-9 place-items-center rounded-lg text-lg text-white/70 hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/70"
                      :aria-label="t('common.closeMenu')" @click="drawer = false">✕</button>
            </div>
            <nav :aria-label="t('common.mobileNavigation')" class="min-h-0 flex-1 overflow-y-auto p-3">
              <section v-for="section in navSections" :key="section.label" class="mb-5 last:mb-0">
                <p class="px-3 pb-2 text-[10px] font-black uppercase tracking-[0.2em] text-white/40">{{ sectionLabel(section.label) }}</p>
                <ul class="space-y-0.5">
                  <li v-for="item in section.items" :key="item.href">
                    <Link :href="hrefFor(item.href)" :headers="authHeaders()" @click="drawer = false"
                          :aria-current="isActive(item.href) ? 'page' : undefined"
                          class="flex items-center gap-3 rounded-xl px-3.5 py-3 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/70"
                          :class="isActive(item.href) ? 'bg-white/10 text-white shadow-[inset_3px_0_0_#b31b2c]' : 'text-white/65 hover:bg-white/10 hover:text-white'">
                      <span class="grid size-8 shrink-0 place-items-center rounded-lg" :class="isActive(item.href) ? 'bg-brand text-white' : 'bg-white/10 text-white/60'">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                          <path v-for="path in iconPaths[item.icon]" :key="path" :d="path" />
                        </svg>
                      </span>
                      {{ navLabel(item) }}
                    </Link>
                  </li>
                </ul>
              </section>
            </nav>
          </aside>
        </div>
      </Teleport>

      <!-- ===== Content ===== -->
      <main id="bank-main-content" class="min-w-0 flex-1 px-4 py-5 lg:px-8 lg:py-7" tabindex="-1">
        <slot :lang="lang" />
      </main>
    </div>
  </div>
</template>
