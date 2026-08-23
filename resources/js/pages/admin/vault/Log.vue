<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BankLayout from '@/layouts/BankLayout.vue';

type VaultLog = {
    id: number;
    txn_type: string;
    denomination: number;
    quantity: number;
    performed_by_name: string | null;
    note: string | null;
    created_at?: string | null;
};

const props = defineProps<{
    role: 'admin';
    notificationCount?: number;
    rows: VaultLog[];
}>();
const rows = computed(() => props.rows ?? []);
const search = ref('');
const page = ref(1);
const pageSize = ref(25);
const loading = ref(false);
const filtered = computed(() => {
    const query = search.value.trim().toLowerCase();
    return rows.value.filter(
        (row) =>
            query === '' ||
            [
                row.txn_type,
                row.note,
                row.performed_by_name,
                row.denomination,
                row.quantity,
            ].some((value) =>
                String(value ?? '')
                    .toLowerCase()
                    .includes(query),
            ),
    );
});
const pageCount = computed(() =>
    Math.max(1, Math.ceil(filtered.value.length / pageSize.value)),
);
const paginated = computed(() =>
    filtered.value.slice(
        (page.value - 1) * pageSize.value,
        page.value * pageSize.value,
    ),
);
watch([search, pageSize], () => (page.value = 1));
watch(pageCount, (count) => (page.value = Math.min(page.value, count)));
const money = (value: number) => value.toLocaleString();
const dateTime = (value?: string | null) =>
    value ? new Date(value).toLocaleString() : '-';

function typeLabel(log: VaultLog): string {
    const note = (log.note ?? '').toLowerCase();
    if (note.includes('owner deposit to cashier vault'))
        return 'cashier_vault_deposit';
    if (note.includes('owner withdrawal from cashier vault'))
        return 'cashier_vault_withdraw';
    return log.txn_type;
}
function load(): void {
    loading.value = true;
    router.reload({ only: ['rows'], onFinish: () => (loading.value = false) });
}
</script>

<template>
    <BankLayout :role="role" :notification-count="notificationCount">
        <h1 class="text-2xl font-bold tracking-tight">Vault Log</h1>
        <section
            class="mt-5 rounded-xl border border-line bg-card p-5 shadow-sm"
        >
            <div class="flex gap-2">
                <input
                    v-model="search"
                    type="search"
                    class="bank-input max-w-md"
                    placeholder="Search type, note, user"
                />
                <button
                    class="bank-button bank-button-secondary"
                    :disabled="loading"
                    @click="load"
                >
                    Refresh
                </button>
            </div>
            <div class="mt-4 overflow-auto rounded-lg border border-line">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="bg-mist text-xs text-slate uppercase">
                        <tr>
                            <th class="px-4 py-3">Time</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Note</th>
                            <th class="px-4 py-3 text-right">Qty</th>
                            <th class="px-4 py-3">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="log in paginated" :key="log.id">
                            <td class="px-4 py-3 text-xs text-slate">
                                {{ dateTime(log.created_at) }}
                            </td>
                            <td class="px-4 py-3 font-bold">
                                {{ typeLabel(log) }}
                            </td>
                            <td class="px-4 py-3 text-slate">
                                {{ log.note ?? '-' }}
                            </td>
                            <td class="money px-4 py-3 text-right">
                                {{ money(log.denomination) }} ×
                                {{ log.quantity }}
                            </td>
                            <td class="px-4 py-3 text-slate">
                                {{ log.performed_by_name ?? '-' }}
                            </td>
                        </tr>
                        <tr v-if="!paginated.length">
                            <td
                                colspan="5"
                                class="px-4 py-8 text-center text-slate"
                            >
                                No vault logs found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer
                class="mt-4 grid items-center gap-3 text-sm font-semibold text-slate md:grid-cols-3"
            >
                <span
                    >Showing
                    {{ filtered.length ? (page - 1) * pageSize + 1 : 0 }} to
                    {{ Math.min(page * pageSize, filtered.length) }} of
                    {{ filtered.length }} entries</span
                >
                <label class="bank-page-size justify-self-center"
                    >Show
                    <select
                        v-model.number="pageSize"
                        class="bank-page-size-select"
                    >
                        <option :value="10">10</option>
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>
                    entries</label
                >
                <div class="flex justify-end gap-2">
                    <button
                        class="bank-button bank-button-secondary px-3 py-2"
                        :disabled="page <= 1"
                        @click="page--"
                    >
                        Previous</button
                    ><span class="self-center"
                        >{{ page }} / {{ pageCount }}</span
                    ><button
                        class="bank-button bank-button-secondary px-3 py-2"
                        :disabled="page >= pageCount"
                        @click="page++"
                    >
                        Next
                    </button>
                </div>
            </footer>
        </section>
    </BankLayout>
</template>
