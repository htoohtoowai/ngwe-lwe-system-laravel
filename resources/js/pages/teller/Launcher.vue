<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import BankLayout from '@/layouts/BankLayout.vue';
import { useLocale } from '@/lib/i18n';

type LauncherIcon =
    | 'cash-in'
    | 'cash-out'
    | 'send-money'
    | 'receive-money'
    | 'transfer'
    | 'exchange';

type LauncherAction = {
    key: LauncherIcon;
    href: string;
    labelEn: string;
    labelMm: string;
    iconClass: string;
};

const props = defineProps<{
    role: 'teller';
    announcement?: string | null;
    notificationCount?: number;
}>();

const { lang } = useLocale();

const actions: LauncherAction[] = [
    {
        key: 'cash-in',
        href: '/transactions/cash-in',
        labelEn: 'Cash In',
        labelMm: 'ငွေသွင်း',
        iconClass:
            'from-emerald-400 via-teal-500 to-cyan-600 shadow-emerald-950/35',
    },
    {
        key: 'cash-out',
        href: '/transactions/cash-out',
        labelEn: 'Cash Out',
        labelMm: 'ငွေထုတ်',
        iconClass:
            'from-rose-400 via-orange-500 to-amber-500 shadow-orange-950/35',
    },
    {
        key: 'send-money',
        href: '/transactions/send-money',
        labelEn: 'Send Money',
        labelMm: 'ငွေပို့',
        iconClass:
            'from-sky-400 via-blue-500 to-indigo-600 shadow-blue-950/35',
    },
    {
        key: 'receive-money',
        href: '/transactions/receive-money',
        labelEn: 'Receive Money',
        labelMm: 'ငွေလက်ခံ',
        iconClass:
            'from-violet-400 via-fuchsia-500 to-pink-500 shadow-fuchsia-950/35',
    },
    {
        key: 'transfer',
        href: '/transactions/transfer',
        labelEn: 'Transfer',
        labelMm: 'ငွေလွှဲ',
        iconClass:
            'from-cyan-400 via-sky-500 to-blue-600 shadow-sky-950/35',
    },
    {
        key: 'exchange',
        href: '/transactions/exchange',
        labelEn: 'Exchange',
        labelMm: 'ငွေလဲ',
        iconClass:
            'from-amber-400 via-orange-500 to-rose-500 shadow-rose-950/35',
    },
];

const title = computed(() =>
    lang.value === 'mm'
        ? 'ငွေကိုင်ဝန်ထမ်း ပင်မစာမျက်နှာ'
        : 'Teller Home',
);

const subtitle = computed(() =>
    lang.value === 'mm'
        ? 'လုပ်ဆောင်လိုသော ငွေလုပ်ငန်းအမျိုးအစားကို ရွေးပါ'
        : 'Choose a transaction',
);

function label(action: LauncherAction): string {
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
                class="relative overflow-hidden rounded-[2rem] bg-ink px-4 py-7 text-white shadow-xl sm:px-7 sm:py-9 lg:px-10 lg:py-10"
            >
                <div
                    class="pointer-events-none absolute -top-24 -left-20 size-64 rounded-full bg-balance/15 blur-3xl"
                />
                <div
                    class="pointer-events-none absolute -right-20 -bottom-28 size-72 rounded-full bg-brand/20 blur-3xl"
                />
                <div
                    class="pointer-events-none absolute top-1/3 left-1/2 size-56 -translate-x-1/2 rounded-full bg-white/5 blur-3xl"
                />

                <header class="relative text-center">
                    <p
                        class="text-[11px] font-black tracking-[0.22em] text-white/45 uppercase"
                    >
                        {{ title }}
                    </p>
                    <h1
                        class="mt-2 text-2xl font-black tracking-tight sm:text-3xl"
                    >
                        {{ subtitle }}
                    </h1>
                </header>

                <div
                    class="relative mt-7 grid grid-cols-2 gap-x-3 gap-y-6 sm:mt-9 sm:grid-cols-3 sm:gap-x-5 sm:gap-y-8 xl:grid-cols-6"
                >
                    <Link
                        v-for="action in actions"
                        :key="action.key"
                        :href="action.href"
                        class="group flex min-w-0 flex-col items-center rounded-3xl px-2 py-3 text-center outline-none transition duration-200 hover:bg-white/5 focus-visible:ring-2 focus-visible:ring-white/70"
                    >
                        <span
                            class="relative grid size-[88px] place-items-center overflow-hidden rounded-[1.45rem] border border-white/20 bg-gradient-to-br shadow-[0_18px_34px_-16px] transition duration-200 group-hover:-translate-y-1 group-hover:scale-[1.04] group-active:scale-[0.98] sm:size-[96px]"
                            :class="action.iconClass"
                        >
                            <span
                                class="pointer-events-none absolute -top-7 -right-7 size-20 rounded-full bg-white/25"
                            />
                            <span
                                class="pointer-events-none absolute -bottom-8 -left-7 size-20 rounded-full bg-black/10"
                            />

                            <svg
                                v-if="action.key === 'cash-in'"
                                viewBox="0 0 24 24"
                                class="relative size-12 drop-shadow-md"
                                fill="none"
                                aria-hidden="true"
                            >
                                <rect x="4" y="8" width="16" height="10" rx="3" fill="rgba(255,255,255,.22)" stroke="white" stroke-width="1.7" />
                                <path d="M12 3v9m0 0-3.4-3.4M12 12l3.4-3.4" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                                <circle cx="12" cy="15" r="1.5" fill="white" />
                            </svg>

                            <svg
                                v-else-if="action.key === 'cash-out'"
                                viewBox="0 0 24 24"
                                class="relative size-12 drop-shadow-md"
                                fill="none"
                                aria-hidden="true"
                            >
                                <rect x="4" y="8" width="16" height="10" rx="3" fill="rgba(255,255,255,.22)" stroke="white" stroke-width="1.7" />
                                <path d="M12 13V4m0 0-3.4 3.4M12 4l3.4 3.4" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                                <circle cx="12" cy="15" r="1.5" fill="white" />
                            </svg>

                            <svg
                                v-else-if="action.key === 'send-money'"
                                viewBox="0 0 24 24"
                                class="relative size-12 drop-shadow-md"
                                fill="none"
                                aria-hidden="true"
                            >
                                <path d="M3.5 11.2 20.5 4l-6.6 16-3.3-6.1-7.1-2.7Z" fill="rgba(255,255,255,.22)" stroke="white" stroke-width="1.8" stroke-linejoin="round" />
                                <path d="m10.6 13.9 9.9-9.9" stroke="white" stroke-width="2" stroke-linecap="round" />
                                <circle cx="6.3" cy="17.5" r="2.1" fill="white" fill-opacity=".92" />
                            </svg>

                            <svg
                                v-else-if="action.key === 'receive-money'"
                                viewBox="0 0 24 24"
                                class="relative size-12 drop-shadow-md"
                                fill="none"
                                aria-hidden="true"
                            >
                                <path d="M5 11.5h14l1.4 6A2 2 0 0 1 18.5 20h-13a2 2 0 0 1-1.9-2.5l1.4-6Z" fill="rgba(255,255,255,.22)" stroke="white" stroke-width="1.7" stroke-linejoin="round" />
                                <path d="M12 3v10m0 0-3.4-3.4M12 13l3.4-3.4" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>

                            <svg
                                v-else-if="action.key === 'transfer'"
                                viewBox="0 0 24 24"
                                class="relative size-12 drop-shadow-md"
                                fill="none"
                                aria-hidden="true"
                            >
                                <path d="M4 8h14m0 0-3-3m3 3-3 3" stroke="white" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M20 16H6m0 0 3 3m-3-3 3-3" stroke="white" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" />
                                <circle cx="4" cy="16" r="2" fill="rgba(255,255,255,.35)" />
                                <circle cx="20" cy="8" r="2" fill="rgba(255,255,255,.35)" />
                            </svg>

                            <svg
                                v-else
                                viewBox="0 0 24 24"
                                class="relative size-12 drop-shadow-md"
                                fill="none"
                                aria-hidden="true"
                            >
                                <path d="M18.6 8A7.5 7.5 0 0 0 6.2 5.8L4 8" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M5.4 16A7.5 7.5 0 0 0 17.8 18.2L20 16" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M4 4v4h4M20 20v-4h-4" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                <circle cx="12" cy="12" r="2.6" fill="rgba(255,255,255,.28)" stroke="white" stroke-width="1.5" />
                            </svg>
                        </span>

                        <span
                            class="mt-3 w-full truncate text-sm font-bold tracking-tight text-white sm:text-[15px]"
                        >
                            {{ label(action) }}
                        </span>
                    </Link>
                </div>
            </section>

            <p
                class="mx-auto mt-4 max-w-xl text-center text-xs font-semibold leading-5 text-slate"
            >
                {{
                    lang === 'mm'
                        ? 'အိုင်ကွန်တစ်ခုကို နှိပ်ပြီး ငွေလုပ်ငန်းစာရင်းသွင်းနိုင်ပါသည်။'
                        : 'Tap an icon to start a transaction.'
                }}
            </p>
        </div>
    </BankLayout>
</template>
