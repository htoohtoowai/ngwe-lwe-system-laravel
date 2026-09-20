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
        <section
            class="mx-auto w-full max-w-4xl px-1 pt-2 pb-8 sm:px-4 sm:pt-6 lg:pt-8"
            aria-label="Teller actions"
        >
            <div
                class="grid grid-cols-2 gap-x-4 gap-y-7 sm:grid-cols-3 sm:gap-x-8 sm:gap-y-10 md:gap-x-12"
            >
                <Link
                    v-for="action in actions"
                    :key="action.href"
                    :href="action.href"
                    class="group flex min-w-0 min-h-36 flex-col items-center justify-center rounded-[1.75rem] px-3 py-4 text-center outline-none transition duration-200 hover:bg-white/75 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-brand/70 focus-visible:ring-offset-2 active:scale-[0.98] sm:min-h-40"
                >
                    <span
                        class="transition duration-200 group-hover:-translate-y-1 group-hover:scale-[1.04]"
                    >
                        <AppMenuIcon
                            :name="action.icon"
                            size="launcher"
                        />
                    </span>

                    <span
                        class="mt-3 w-full truncate text-sm font-bold tracking-tight text-ink sm:text-[15px]"
                    >
                        {{ actionLabel(action) }}
                    </span>
                </Link>
            </div>
        </section>
    </BankLayout>
</template>
