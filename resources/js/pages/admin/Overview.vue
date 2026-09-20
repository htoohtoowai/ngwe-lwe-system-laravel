<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppMenuIcon from '@/components/ui/AppMenuIcon.vue';
import BankLayout from '@/layouts/BankLayout.vue';
import { useLocale } from '@/lib/i18n';
import type { MenuIconName } from '@/lib/menu-icons';

type Branch = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
};

type Account = {
    id: number;
    branch_id: number | null;
    account_type: 'PAY' | 'BANK' | string;
    balance: string | number;
    is_active: boolean;
};

type LauncherAction = {
    icon: MenuIconName;
    labelEn: string;
    labelMm: string;
    href: string;
    badge?: number;
};

type LauncherGroup = {
    titleEn: string;
    titleMm: string;
    actions: LauncherAction[];
};

type AdminData = {
    selectedBranchId: number;
    selectedBranch?: Branch | null;
    branches: Branch[];
    accounts?: Account[];
    adjustmentCounts?: {
        deposit: number;
        withdraw: number;
    };
};

const props = defineProps<{
    role: 'admin';
    announcement?: string | null;
    notificationCount?: number;
    adminData: AdminData;
}>();

const { lang } = useLocale();
const selectedBranchId = ref(props.adminData.selectedBranchId);
const selectedBranch = computed(
    () =>
        props.adminData.branches.find(
            (branch) => branch.id === selectedBranchId.value,
        ) ?? props.adminData.selectedBranch ?? null,
);

function branchHref(path: string): string {
    const separator = path.includes('?') ? '&' : '?';
    return `${path}${separator}branch_id=${selectedBranchId.value}`;
}

const groups = computed<LauncherGroup[]>(() => [
    {
        titleEn: 'Money Movement',
        titleMm: 'ငွေရွှေ့ပြောင်းမှု',
        actions: [
            {
                icon: 'cashIn' as MenuIconName,
                labelEn: 'Deposit',
                labelMm: 'ငွေသွင်း',
                href: branchHref('/admin/adjustments?direction=deposit'),
                badge: props.adminData.adjustmentCounts?.deposit ?? 0,
            },
            {
                icon: 'cashOut' as MenuIconName,
                labelEn: 'Withdraw',
                labelMm: 'ငွေထုတ်',
                href: branchHref('/admin/adjustments?direction=withdraw'),
                badge: props.adminData.adjustmentCounts?.withdraw ?? 0,
            },
        ] satisfies LauncherAction[],
    },
    {
        titleEn: 'Balances',
        titleMm: 'လက်ကျန်များ',
        actions: [
            {
                icon: 'vault' as MenuIconName,
                labelEn: 'Cash',
                labelMm: 'ငွေသား',
                href: branchHref('/admin/vault'),
            },
            {
                icon: 'accounts' as MenuIconName,
                labelEn: 'Pay',
                labelMm: 'Pay',
                href: branchHref('/admin/balances/pay'),
            },
            {
                icon: 'companies' as MenuIconName,
                labelEn: 'Bank',
                labelMm: 'ဘဏ်',
                href: branchHref('/admin/balances/bank'),
            },
        ] satisfies LauncherAction[],
    },
    {
        titleEn: 'Operations',
        titleMm: 'လုပ်ငန်းများ',
        actions: [
            {
                icon: 'transactions' as MenuIconName,
                labelEn: 'Transactions',
                labelMm: 'ငွေလုပ်ငန်းများ',
                href: branchHref('/admin/transactions'),
            },
            {
                icon: 'reconcile' as MenuIconName,
                labelEn: 'Reconcile',
                labelMm: 'စာရင်းညှိ',
                href: branchHref('/admin/reports/reconciliations'),
            },
            {
                icon: 'reports' as MenuIconName,
                labelEn: 'Reports',
                labelMm: 'အစီရင်ခံစာ',
                href: branchHref('/admin/reports'),
            },
        ] satisfies LauncherAction[],
    },
    {
        titleEn: 'Management',
        titleMm: 'စီမံခန့်ခွဲမှု',
        actions: [
            {
                icon: 'companies' as MenuIconName,
                labelEn: 'Branches',
                labelMm: 'ဆိုင်ခွဲများ',
                href: '/admin/branches',
            },
            {
                icon: 'users' as MenuIconName,
                labelEn: 'Staff',
                labelMm: 'ဝန်ထမ်းများ',
                href: branchHref('/admin/users'),
            },
            {
                icon: 'services' as MenuIconName,
                labelEn: 'Providers',
                labelMm: 'Provider များ',
                href: '/admin/companies',
            },
            {
                icon: 'accounts' as MenuIconName,
                labelEn: 'Accounts',
                labelMm: 'အကောင့်များ',
                href: branchHref('/admin/accounts'),
            },
            {
                icon: 'fees' as MenuIconName,
                labelEn: 'Fee Rules',
                labelMm: 'ဝန်ဆောင်ခ',
                href: '/admin/fees',
            },
            {
                icon: 'exchange' as MenuIconName,
                labelEn: 'Exchange Rates',
                labelMm: 'ငွေလဲနှုန်း',
                href: '/admin/exchange-rates',
            },
            {
                icon: 'settings' as MenuIconName,
                labelEn: 'Audit Logs',
                labelMm: 'စစ်ဆေးမှတ်တမ်း',
                href: '/admin/audit-logs',
            },
        ] satisfies LauncherAction[],
    },
]);

function label(action: LauncherAction): string {
    return lang.value === 'mm' ? action.labelMm : action.labelEn;
}

function groupTitle(group: { titleEn: string; titleMm: string }): string {
    return lang.value === 'mm' ? group.titleMm : group.titleEn;
}

function loadBranch(): void {
    router.get(
        '/admin',
        { branch_id: selectedBranchId.value },
        {
            preserveScroll: true,
            preserveState: false,
            replace: true,
        },
    );
}
</script>

<template>
    <BankLayout
        :role="role"
        :announcement="announcement"
        :notification-count="notificationCount"
    >
        <div class="mx-auto w-full max-w-6xl pb-10">
            <header
                class="flex flex-col gap-4 px-1 pt-2 sm:flex-row sm:items-end sm:justify-between sm:px-4 sm:pt-5"
            >
                <div>
                    <p
                        class="text-xs font-black tracking-[0.14em] text-slate uppercase"
                    >
                        Admin
                    </p>
                    <h1 class="mt-1 text-2xl font-black tracking-tight text-ink">
                        {{ lang === 'mm' ? 'လုပ်ငန်းရွေးချယ်ရန်' : 'Apps' }}
                    </h1>
                </div>

                <label class="w-full sm:w-72">
                    <span class="bank-label">
                        {{ lang === 'mm' ? 'ဆိုင်ခွဲ' : 'Branch' }}
                    </span>
                    <select
                        v-model.number="selectedBranchId"
                        class="bank-input"
                        @change="loadBranch"
                    >
                        <option
                            v-for="branch in adminData.branches"
                            :key="branch.id"
                            :value="branch.id"
                        >
                            {{ branch.name }} ({{ branch.code }})
                        </option>
                    </select>
                </label>
            </header>

            <div
                class="mt-4 rounded-2xl border border-line bg-card/70 px-4 py-3 text-sm font-semibold text-slate sm:mx-4"
            >
                <span class="font-black text-ink">
                    {{ selectedBranch?.name ?? 'Branch' }}
                </span>
                <span v-if="selectedBranch?.code"> · {{ selectedBranch.code }}</span>
            </div>

            <section
                v-for="group in groups"
                :key="group.titleEn"
                class="mt-7 sm:mt-9"
            >
                <h2
                    class="px-2 text-xs font-black tracking-[0.14em] text-slate uppercase sm:px-4"
                >
                    {{ groupTitle(group) }}
                </h2>

                <div
                    class="mt-2 grid grid-cols-2 gap-x-3 gap-y-5 sm:grid-cols-3 sm:gap-x-5 md:grid-cols-4 md:gap-x-6 xl:grid-cols-6"
                >
                    <Link
                        v-for="action in group.actions"
                        :key="action.labelEn"
                        :href="action.href"
                        class="group relative flex min-h-32 min-w-0 flex-col items-center justify-center rounded-[1.65rem] px-2 py-4 text-center outline-none transition duration-200 hover:bg-white/80 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-brand/70 focus-visible:ring-offset-2 active:scale-[0.98] sm:min-h-36"
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
        </div>
    </BankLayout>
</template>
