<script setup lang="ts">
import BankLayout from '@/layouts/BankLayout.vue';

type Teller = {
    id: number;
    name: string;
    float_id: number | null;
    float_status: string | null;
    current_balance: string | number;
    pending_additional_issues: number;
    today_transactions: number;
};

defineProps<{
    role: 'cashier';
    branch: { id: number; code?: string | null; name: string };
    tellers: Teller[];
}>();

const money = (value: string | number) => Number(value ?? 0).toLocaleString();
</script>

<template>
    <BankLayout :role="role">
        <div class="mx-auto w-full max-w-5xl pb-8">
            <header class="border-b border-line pb-4">
                <p class="text-xs font-black tracking-[0.14em] text-slate uppercase">Branch Team</p>
                <h1 class="mt-1 text-2xl font-black text-ink">Tellers</h1>
                <p class="mt-1 text-sm font-semibold text-slate">{{ branch.name }}<span v-if="branch.code"> · {{ branch.code }}</span></p>
            </header>

            <section class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <article v-for="teller in tellers" :key="teller.id" class="rounded-3xl border border-line bg-card p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-black text-ink">{{ teller.name }}</p>
                            <p class="mt-1 text-xs font-semibold text-slate">{{ teller.float_status ?? 'No open float' }}</p>
                        </div>
                        <span class="rounded-full bg-mist px-2.5 py-1 text-[10px] font-black">{{ teller.today_transactions }} txns</span>
                    </div>
                    <p class="money mt-5 text-2xl font-black text-ink">{{ money(teller.current_balance) }} MMK</p>
                    <p class="mt-1 text-xs font-semibold text-slate">Current teller cash</p>
                    <p v-if="teller.pending_additional_issues > 0" class="mt-4 text-xs font-black text-brand">{{ teller.pending_additional_issues }} additional float issue(s) waiting</p>
                </article>
                <div v-if="!tellers.length" class="rounded-2xl border border-dashed border-line px-5 py-10 text-center text-sm font-semibold text-slate">No active Tellers in this branch.</div>
            </section>
        </div>
    </BankLayout>
</template>
