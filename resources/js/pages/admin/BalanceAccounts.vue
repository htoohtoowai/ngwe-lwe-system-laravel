<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BankLayout from '@/layouts/BankLayout.vue';

type Branch = { id: number; code: string; name: string; is_active: boolean };
type Account = {
    id: number;
    branch_id: number | null;
    account_name: string;
    account_identifier: string;
    account_type: string;
    balance: string | number;
    company?: { id?: number; name?: string | null } | null;
};

const props = defineProps<{
    role: 'admin';
    type: 'pay' | 'bank';
    branches: Branch[];
    selectedBranchId: number;
    accounts: Account[];
    notificationCount?: number;
}>();

const selectedBranchId = ref(props.selectedBranchId);
const title = computed(() => (props.type === 'pay' ? 'Pay' : 'Bank'));
const groups = computed(() => {
    const map = new Map<string, Account[]>();
    for (const account of props.accounts) {
        const name = account.company?.name ?? 'Provider';
        map.set(name, [...(map.get(name) ?? []), account]);
    }
    return [...map.entries()].map(([provider, accounts]) => ({
        provider,
        accounts,
        total: accounts.reduce((sum, account) => sum + Number(account.balance ?? 0), 0),
    }));
});
const grandTotal = computed(() =>
    props.accounts.reduce((sum, account) => sum + Number(account.balance ?? 0), 0),
);

function money(value: string | number): string {
    return Number(value ?? 0).toLocaleString();
}

function loadBranch(): void {
    router.get(
        `/admin/balances/${props.type}`,
        { branch_id: selectedBranchId.value },
        { preserveScroll: true, preserveState: false, replace: true },
    );
}
</script>

<template>
    <BankLayout :role="role" :notification-count="notificationCount">
        <div class="mx-auto w-full max-w-5xl pb-8">
            <header class="flex flex-col gap-4 border-b border-line pb-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-black tracking-[0.14em] text-slate uppercase">Balances</p>
                    <h1 class="mt-1 text-2xl font-black text-ink">{{ title }} Accounts</h1>
                </div>
                <label class="w-full sm:w-72">
                    <span class="bank-label">Branch</span>
                    <select v-model.number="selectedBranchId" class="bank-input" @change="loadBranch">
                        <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }} ({{ branch.code }})</option>
                    </select>
                </label>
            </header>

            <section class="mt-5 rounded-3xl border border-line bg-card p-5 shadow-sm">
                <p class="text-xs font-black tracking-[0.1em] text-slate uppercase">{{ title }} Total</p>
                <p class="money mt-2 text-3xl font-black text-ink">{{ money(grandTotal) }} MMK</p>
            </section>

            <section class="mt-5 grid gap-4">
                <article v-for="group in groups" :key="group.provider" class="rounded-3xl border border-line bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-black text-ink">{{ group.provider }}</h2>
                        <p class="money text-sm font-black text-ink">{{ money(group.total) }} MMK</p>
                    </div>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div v-for="account in group.accounts" :key="account.id" class="rounded-2xl bg-mist p-4">
                            <p class="font-black text-ink">{{ account.account_name }}</p>
                            <p class="mt-1 text-xs font-semibold text-slate">{{ account.account_identifier }}</p>
                            <p class="money mt-3 text-xl font-black text-ink">{{ money(account.balance) }} MMK</p>
                            <p class="mt-1 text-[11px] font-bold text-slate">{{ account.branch_id === null ? 'Shared account' : 'Branch account' }}</p>
                        </div>
                    </div>
                </article>
                <div v-if="!groups.length" class="rounded-2xl border border-dashed border-line px-5 py-10 text-center text-sm font-semibold text-slate">No active {{ title.toLowerCase() }} accounts for this branch.</div>
            </section>
        </div>
    </BankLayout>
</template>
