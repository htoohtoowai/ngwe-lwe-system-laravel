<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import BankLayout from '@/layouts/BankLayout.vue';
import { apiRequest } from '@/lib/api';
import { readStoredToken } from '@/lib/auth-token';

type Reconciliation = {
    id: number;
    recon_date: string | null;
    closed_by_name: string | null;
    total_cash: string | number;
    total_digital: string | number;
    grand_total: string | number;
    notes: string | null;
};

defineProps<{ role: 'admin'; notificationCount?: number }>();
const rows = ref<Reconciliation[]>([]);
const search = ref('');
const dateFrom = ref('');
const dateTo = ref('');
const page = ref(1);
const pageSize = ref(25);
const loading = ref(false);
const error = ref('');

const filtered = computed(() => {
    const query = search.value.trim().toLowerCase();
    return rows.value.filter((row) => {
        const date = row.recon_date ?? '';
        const matchesDate =
            (!dateFrom.value || date >= dateFrom.value) &&
            (!dateTo.value || date <= dateTo.value);
        const matchesSearch =
            !query ||
            [row.recon_date, row.closed_by_name, row.total_cash, row.total_digital, row.grand_total, row.notes]
                .some((value) => String(value ?? '').toLowerCase().includes(query));
        return matchesDate && matchesSearch;
    });
});
const pageCount = computed(() => Math.max(1, Math.ceil(filtered.value.length / pageSize.value)));
const paginated = computed(() => filtered.value.slice((page.value - 1) * pageSize.value, page.value * pageSize.value));
watch([search, dateFrom, dateTo, pageSize], () => (page.value = 1));
watch(pageCount, (count) => (page.value = Math.min(page.value, count)));
const money = (value: string | number) => Number(value ?? 0).toLocaleString();

async function load(): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
        const response = await apiRequest<{ data?: Reconciliation[] }>('/api/reports/daily-reconciliations', {
            token: readStoredToken(),
            query: { per_page: 100 },
        });
        rows.value = response.data ?? [];
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'Unable to load reconciliation history.';
    } finally {
        loading.value = false;
    }
}
onMounted(load);
</script>

<template>
    <BankLayout :role="role" :notification-count="notificationCount">
        <h1 class="text-2xl font-bold tracking-tight">Reconciliation History</h1>
        <section class="mt-5 rounded-xl border border-line bg-card p-5 shadow-sm">
            <div class="grid gap-2 md:grid-cols-4">
                <input v-model="search" type="search" class="bank-input md:col-span-2" placeholder="Search date, closed by, notes or total" />
                <input v-model="dateFrom" type="date" class="bank-input" aria-label="From date" />
                <input v-model="dateTo" type="date" class="bank-input" aria-label="To date" />
            </div>
            <p v-if="error" class="mt-3 text-sm font-bold text-brand">{{ error }}</p>
            <div class="mt-4 overflow-auto rounded-lg border border-line">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="bg-mist text-xs text-slate uppercase">
                        <tr><th class="px-4 py-3">Date</th><th class="px-4 py-3">Closed By</th><th class="px-4 py-3 text-right">Cash</th><th class="px-4 py-3 text-right">Digital</th><th class="px-4 py-3 text-right">Grand</th><th class="px-4 py-3">Notes</th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in paginated" :key="row.id">
                            <td class="px-4 py-3 font-bold text-ink">{{ row.recon_date ?? '-' }}</td><td class="px-4 py-3 text-slate">{{ row.closed_by_name ?? '-' }}</td><td class="money px-4 py-3 text-right">{{ money(row.total_cash) }}</td><td class="money px-4 py-3 text-right">{{ money(row.total_digital) }}</td><td class="money px-4 py-3 text-right font-bold">{{ money(row.grand_total) }}</td><td class="px-4 py-3 text-slate">{{ row.notes ?? '-' }}</td>
                        </tr>
                        <tr v-if="!paginated.length"><td colspan="6" class="px-4 py-8 text-center text-slate">No reconciliation records found.</td></tr>
                    </tbody>
                </table>
            </div>
            <footer class="mt-4 grid items-center gap-3 text-sm font-semibold text-slate md:grid-cols-3">
                <span>Showing {{ filtered.length ? (page - 1) * pageSize + 1 : 0 }} to {{ Math.min(page * pageSize, filtered.length) }} of {{ filtered.length }} entries</span>
                <label class="flex items-center justify-center gap-2">Show <select v-model.number="pageSize" class="bank-input w-20 py-2"><option :value="10">10</option><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option></select> entries</label>
                <div class="flex justify-end gap-2"><button type="button" class="bank-button bank-button-secondary px-3 py-2" :disabled="page <= 1" @click="page--">Previous</button><span class="self-center">{{ page }} / {{ pageCount }}</span><button type="button" class="bank-button bank-button-secondary px-3 py-2" :disabled="page >= pageCount" @click="page++">Next</button></div>
            </footer>
        </section>
    </BankLayout>
</template>
