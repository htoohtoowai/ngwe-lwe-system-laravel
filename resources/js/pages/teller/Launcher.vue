<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppMenuIcon from '@/components/ui/AppMenuIcon.vue';
import BankLayout from '@/layouts/BankLayout.vue';
import { useLocale } from '@/lib/i18n';
import type { MenuIconName } from '@/lib/menu-icons';

type LauncherAction = {
    icon: MenuIconName;
    href: string;
    labelEn: string;
    labelMm: string;
};

const props = defineProps<{
    role: 'teller';
    announcement?: string | null;
    notificationCount?: number;
}>();

const { lang } = useLocale();

const actions: LauncherAction[] = [
    {
        icon: 'cashIn',
        href: '/transactions/cash-in',
        labelEn: 'Cash In',
        labelMm: 'ငွေသွင်း',
    },
    {
        icon: 'cashOut',
        href: '/transactions/cash-out',
        labelEn: 'Cash Out',
        labelMm: 'ငွေထုတ်',
    },
    {
        icon: 'sendMoney',
        href: '/transactions/send-money',
        labelEn: 'Send Money',
        labelMm: 'ငွေပို့',
    },
    {
        icon: 'receiveMoney',
        href: '/transactions/receive-money',
        labelEn: 'Receive Money',
        labelMm: 'ငွေလက်ခံ',
    },
    {
        icon: 'transfer',
        href: '/transactions/transfer',
        labelEn: 'Transfer',
        labelMm: 'ငွေလွှဲ',
    },
    {
        icon: 'exchange',
        href: '/transactions/exchange',
        labelEn: 'Exchange',
        labelMm: 'ငွေလဲ',
    },
];

function actionLabel(action: LauncherAction): string {
    return lang.value === 'mm' ? action.labelMm : action.labelEn;
}
</script>

<template>
    <BankLayout
        :role="props.role"
        :announcement="props.announcement"
        :notification-count="props.notificationCount"
    >
        <div class="mx-auto w-full max-w-7xl">
            <section
                class="relative overflow-hidden rounded-[2rem] border border-white/10 bg-[#101523] px-4 py-8 text-white shadow-xl sm:px-8 sm:py-12"
            >
                <div
                    class="pointer-events-none absolute -top-28 -left-24 size-72 rounded-full bg-cyan-500/10 blur-3xl"
                />
                <div
                    class="pointer-events-none absolute -right-24 -bottom-32 size-80 rounded-full bg-violet-500/10 blur-3xl"
                />

                <div
                    class="relative mx-auto grid max-w-3xl grid-cols-2 gap-x-5 gap-y-8 sm:grid-cols-3 sm:gap-x-10 sm:gap-y-10"
                >
                    <Link
                        v-for="action in actions"
                        :key="action.href"
                        :href="action.href"
                        class="group flex min-w-0 flex-col items-center rounded-3xl px-2 py-3 text-center outline-none transition duration-200 hover:bg-white/5 focus-visible:ring-2 focus-visible:ring-white/70"
                    >
                        <span
                            class="transition duration-200 group-hover:-translate-y-1 group-hover:scale-[1.04] group-active:scale-[0.98]"
                        >
                            <AppMenuIcon
                                :name="action.icon"
                                size="launcher"
                            />
                        </span>

                        <span
                            class="mt-3 w-full truncate text-sm font-bold tracking-tight text-white sm:text-[15px]"
                        >
                            {{ actionLabel(action) }}
                        </span>
                    </Link>
                </div>
            </section>
        </div>
    </BankLayout>
</template>