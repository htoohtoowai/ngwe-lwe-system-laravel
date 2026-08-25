<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BankLayout from '@/layouts/BankLayout.vue';
import { transactionTone } from '@/lib/transaction-tone';

type Transaction = {
    id: number;
    transaction_type: string;
    customer_name: string | null;
    customer_phone?: string | null;
    amount: string | number;
    customer_fee: string | number | null;
    status: string;
    created_at?: string | null;
    created_by?: number | null;
};

const props = defineProps<{
    role: 'admin';
    title: string;
    transactionType?: '' | 'cash_in' | 'cash_out' | 'transfer' | 'exchange';
    announcement?: string | null;
    notificationCount?: number;
    rows: Transaction[];
}>();

const rows = computed(() => props.rows ?? []);
const search = ref('');
const selectedType = ref('');
const dateFrom = ref('');
const dateTo = ref('');
const page = ref(1);
const pageSize = ref(25);
const loading = ref(false);
const error = ref('');

const filtered = computed(() => {
    const query = search.value.trim().toLowerCase();
    return rows.value.filter((row) => {
        const date = row.created_at?.slice(0, 10) ?? '';
        const matchesFilters =
            (!selectedType.value || row.transaction_type === selectedType.value) &&
            (!dateFrom.value || date >= dateFrom.value) &&
            (!dateTo.value || date <= dateTo.value);
        const matchesSearch = query === '' || [
            row.id,
            `#${row.id}`,
            row.customer_name,
            row.customer_phone,
            row.transaction_type,
            typeLabel(row.transaction_type),
            row.amount,
            row.customer_fee,
            row.status,
        ].some((value) => String(value ?? '').toLowerCase().includes(query));
        return matchesFilters && matchesSearch;
    });
});
const pageCount = computed(() =>
    Math.max(1, Math.ceil(filtered.value.length / pageSize.value)),
);
const paginated = computed(() => {
    const start = (page.value - 1) * pageSize.value;
    return filtered.value.slice(start, start + pageSize.value);
});

watch([search, pageSize], () => (page.value = 1));
watch(pageCount, (count) => (page.value = Math.min(page.value, count)));

function typeLabel(type: string): string {
    return { cash_in: 'Cash In', cash_out: 'Cash Out', transfer: 'Transfer', exchange: 'Exchange' }[type] ?? type;
}
function money(value: string | number | null): string {
    return Number(value ?? 0).toLocaleString();
}
function dateTime(value?: string | null): string {
    return value ? new Date(value).toLocaleString() : '-';
}
function load(): void {
    loading.value = true;
    error.value = '';
    page.value = 1;
    router.reload({ only: ['rows'], onFinish: () => (loading.value = false) });
}
</script>

<template>
    <BankLayout :role="role" :announcement="announcement" :notification-count="notificationCount">
        <h1 class="text-2xl font-bold tracking-tight" :class="transactionType ? transactionTone(transactionType).text : 'text-ink'">{{ title }}</h1>
        <section class="mt-5 rounded-xl border border-line bg-card p-5 shadow-sm">
            <form class="grid gap-2 md:grid-cols-5" @submit.prevent="load">
                <input v-model="search" type="search" class="bank-input" placeholder="Search reference, customer, status" />
                <select v-if="!transactionType" v-model="selectedType" class="bank-input">
                    <option value="">All types</option>
                    <option value="cash_in">Cash In</option>
                    <option value="cash_out">Cash Out</option>
                    <option value="transfer">Transfer</option>
                    <option value="exchange">Exchange</option>
                </select>
                <input v-model="dateFrom" type="date" class="bank-input" aria-label="From date" />
                <input v-model="dateTo" type="date" class="bank-input" aria-label="To date" />
                <button class="bank-button bank-button-secondary" :disabled="loading">Apply</button>
            </form>
            <p v-if="error" class="mt-3 text-sm font-bold text-brand">{{ error }}</p>
            <div class="mt-4 overflow-auto rounded-lg border border-line">
                <table class="w-full min-w-[860px] text-left text-sm">
                    <thead class="bg-mist text-xs text-slate uppercase"><tr><th class="px-4 py-3">Ref</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3 text-right">Amount</th><th class="px-4 py-3 text-right">Fee</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Time</th><th class="px-4 py-3"></th></tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in paginated" :key="row.id">
                            <td class="money px-4 py-3 font-black">#{{ row.id }}</td><td class="px-4 py-3"><span class="rounded-pill border px-2.5 py-1 text-[10px] font-black uppercase" :class="transactionTone(row.transaction_type).badge">{{ typeLabel(row.transaction_type) }}</span></td><td class="px-4 py-3">{{ row.customer_name ?? '-' }}</td><td class="money px-4 py-3 text-right font-bold">{{ money(row.amount) }}</td><td class="money px-4 py-3 text-right">{{ money(row.customer_fee) }}</td><td class="px-4 py-3">{{ row.status }}</td><td class="px-4 py-3 text-xs">{{ dateTime(row.created_at) }}</td><td class="px-4 py-3 text-right"><Link :href="`/admin/transactions/${row.id}`" class="font-black text-brand">View</Link></td>
                        </tr>
                        <tr v-if="!paginated.length"><td colspan="8" class="px-4 py-8 text-center text-slate">No transactions found.</td></tr>
                    </tbody>
                </table>
            </div>
            <footer class="mt-4 grid items-center gap-3 text-sm font-semibold text-slate md:grid-cols-3">
                <span>Showing {{ filtered.length ? (page - 1) * pageSize + 1 : 0 }} to {{ Math.min(page * pageSize, filtered.length) }} of {{ filtered.length }} entries</span>
                <label class="bank-page-size justify-self-center">Show <select v-model.number="pageSize" class="bank-page-size-select"><option :value="10">10</option><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option></select> entries</label>
                <div class="flex justify-end gap-2"><button class="bank-button bank-button-secondary px-3 py-2" :disabled="page <= 1" @click="page--">Previous</button><span class="self-center">{{ page }} / {{ pageCount }}</span><button class="bank-button bank-button-secondary px-3 py-2" :disabled="page >= pageCount" @click="page++">Next</button></div>
            </footer>
        </section>
    </BankLayout>
</template>
