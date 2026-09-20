<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppMenuIcon from '@/components/ui/AppMenuIcon.vue';
import BankLayout from '@/layouts/BankLayout.vue';
import { useLocale } from '@/lib/i18n';
import type { MenuIconName } from '@/lib/menu-icons';
import type { CashierOperationsPageProps } from '@/types/page-props';

type LauncherAction = {
    icon: MenuIconName;
    href: string;
    labelEn: string;
    labelMm: string;
    badge?: number;
};

const props = defineProps<Omit<CashierOperationsPageProps, 'section'>>();
const { lang } = useLocale();

const closeFloatCount = computed(
    () =>
        props.floats.filter(
            (item) => item.status === 'PENDING_RECONCILIATION',
        ).length,
);

const actions = computed<LauncherAction[]>(() => [
    {
        icon: 'cashIn',
        href: '/cashier/admin-requests?direction=deposit',
        labelEn: 'Deposit',
        labelMm: 'ငွေသွင်း',
    },
    {
        icon: 'cashOut',
        href: '/cashier/admin-requests?direction=withdraw',
        labelEn: 'Withdraw',
        labelMm: 'ငွေထုတ်',
    },
    {
        icon: 'vault',
        href: '/cashier/main-vault-denomination-stock',
        labelEn: 'Cash',
        labelMm: 'ငွေသား',
    },
    {
        icon: 'cashIn',
        href: '/cashier/teller-entry-notifications',
        labelEn: 'Pending Cash In',
        labelMm: 'စောင့်ဆိုင်းငွေသွင်း',
        badge: props.pendingCashIns.length,
    },
    {
        icon: 'floats',
        href: '/cashier/morning-issue',
        labelEn: 'Issue Float',
        labelMm: 'ငွေခွဲထုတ်ပေး',
    },
    {
        icon: 'reconcile',
        href: '/cashier/end-of-day',
        labelEn: 'Close Floats',
        labelMm: 'ငွေခွဲပိတ်',
        badge: closeFloatCount.value,
    },
    {
        icon: 'users',
        href: '/cashier/tellers',
        labelEn: 'Tellers',
        labelMm: 'ကောင်တာဝန်ထမ်းများ',
    },
    {
        icon: 'transactions',
        href: '/cashier/teller-entry-history',
        labelEn: 'Teller History',
        labelMm: 'ကောင်တာမှတ်တမ်း',
    },
    {
        icon: 'reports',
        href: '/cashier/main-vault-audit-log',
        labelEn: 'Vault Log',
        labelMm: 'ငွေတိုက်မှတ်တမ်း',
    },
    {
        icon: 'settings',
        href: '/cashier/profile',
        labelEn: 'Profile',
        labelMm: 'ကိုယ်ရေးအချက်အလက်',
    },
]);

function label(action: LauncherAction): string {
    return lang.value === 'mm' ? action.labelMm : action.labelEn;
}

function money(value: string | number): string {
    return Number(value ?? 0).toLocaleString();
}
</script>

<template>
    <BankLayout
        :role="role"
        :announcement="announcement"
        :notification-count="notificationCount"
    >
        <div class="mx-auto w-full max-w-5xl pb-10">
            <header class="px-1 pt-2 sm:px-4 sm:pt-5">
                <p
                    class="text-xs font-black tracking-[0.14em] text-slate uppercase"
                >
                    Cashier
                </p>
                <h1 class="mt-1 text-2xl font-black tracking-tight text-ink">
                    {{ lang === 'mm' ? 'လုပ်ငန်းရွေးချယ်ရန်' : 'Apps' }}
                </h1>
            </header>

            <section
                class="mx-auto w-full px-1 pt-5 pb-8 sm:px-4 sm:pt-7"
                aria-label="Cashier apps"
            >
                <div
                    class="grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3 sm:gap-x-7 md:grid-cols-4 md:gap-x-8"
                >
                    <Link
                        v-for="action in actions"
                        :key="action.href"
                        :href="action.href"
                        class="group relative flex min-h-34 min-w-0 flex-col items-center justify-center rounded-[1.75rem] px-3 py-4 text-center outline-none transition duration-200 hover:bg-white/75 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-brand/70 focus-visible:ring-offset-2 active:scale-[0.98] sm:min-h-40"
                    >
                        <span class="relative">
                            <AppMenuIcon :name="action.icon" size="launcher" />
                            <span
                                v-if="(action.badge ?? 0) > 0"
                                class="absolute -top-2 -right-3 grid min-w-6 place-items-center rounded-full bg-brand px-1.5 py-1 text-[10px] font-black leading-none text-white shadow"
                            >
                                {{ action.badge }}
                            </span>
                        </span>
                        <span
                            class="mt-3 w-full truncate text-sm font-bold tracking-tight text-ink sm:text-[15px]"
                        >
                            {{ label(action) }}
                        </span>
                    </Link>
                </div>
            </section>

            <section class="border-t border-line pt-6 sm:mx-4 sm:pt-7">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4">
                    <Link
                        href="/cashier/main-vault-denomination-stock"
                        class="col-span-2 rounded-3xl border border-line bg-card p-4 shadow-sm transition hover:border-brand/30 hover:shadow-md sm:col-span-1 sm:p-5"
                    >
                        <p class="text-[11px] font-black tracking-[0.08em] text-slate uppercase">
                            {{ lang === 'mm' ? 'ငွေတိုက်လက်ကျန်' : 'Branch Cash' }}
                        </p>
                        <p class="money mt-2 text-2xl font-black tracking-tight text-ink sm:text-3xl">
                            {{ money(vaultTotal) }}
                            <span class="text-sm font-black">MMK</span>
                        </p>
                    </Link>

                    <Link
                        href="/cashier/tellers"
                        class="rounded-3xl border border-line bg-card p-4 shadow-sm transition hover:border-brand/30 hover:shadow-md sm:p-5"
                    >
                        <p class="text-[11px] font-black tracking-[0.08em] text-slate uppercase">
                            {{ lang === 'mm' ? 'ကောင်တာဝန်ထမ်း' : 'Tellers' }}
                        </p>
                        <p class="mt-2 text-2xl font-black tracking-tight text-ink sm:text-3xl">
                            {{ tellers.length }}
                        </p>
                    </Link>

                    <Link
                        href="/cashier/teller-entry-notifications"
                        class="rounded-3xl border border-line bg-card p-4 shadow-sm transition hover:border-brand/30 hover:shadow-md sm:p-5"
                    >
                        <p class="text-[11px] font-black tracking-[0.08em] text-slate uppercase">
                            {{ lang === 'mm' ? 'စောင့်ဆိုင်းငွေသွင်း' : 'Pending Cash In' }}
                        </p>
                        <p class="mt-2 text-2xl font-black tracking-tight text-ink sm:text-3xl">
                            {{ pendingCashIns.length }}
                        </p>
                    </Link>
                </div>
            </section>
        </div>
    </BankLayout>
</template>
