<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BankLayout from '@/layouts/BankLayout.vue';

type Branch = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
};

type Reconciliation = {
    id: number;
    branch_id: number | null;
    branch_code: string | null;
    branch_name: string | null;
    recon_date: string | null;
    closed_by_name: string | null;
    total_cash: string | number;
    total_digital: string | number;
    shared_global_digital_total: string | number;
    grand_total: string | number;
    notes: string | null;
};

const props = defineProps<{
    role: 'admin';
    notificationCount?: number;
    rows: Reconciliation[];
    branches: Branch[];
    selectedBranchId: number;
}>();

const rows = computed(() => props.rows ?? []);
const selectedBranchId = ref(props.selectedBranchId);
const search = ref('');
const dateFrom = ref('');
const dateTo = ref('');
const page = ref(1);
const pageSize = ref(25);

const selectedBranch = computed(
    () =>
        props.branches.find(
            (branch) => branch.id === selectedBranchId.value,
        ) ?? null,
);

const filtered = computed(() => {
    const query = search.value.trim().toLowerCase();

    return rows.value.filter((row) => {
        const date = row.recon_date ?? '';
        const matchesDate =
            (!dateFrom.value || date >= dateFrom.value) &&
            (!dateTo.value || date <= dateTo.value);

        const matchesSearch =
            !query ||
            [
                row.recon_date,
                row.branch_code,
                row.branch_name,
                row.closed_by_name,
                row.total_cash,
                row.total_digital,
                row.grand_total,
                row.notes,
            ].some((value) =>
                String(value ?? '')
                    .toLowerCase()
                    .includes(query),
            );

        return matchesDate && matchesSearch;
    });
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

watch([search, dateFrom, dateTo, pageSize], () => (page.value = 1));
watch(pageCount, (count) => (page.value = Math.min(page.value, count)));

function loadBranch(): void {
    router.get(
        '/admin/reports/reconciliations',
        { branch_id: selectedBranchId.value },
        {
            preserveScroll: true,
            preserveState: false,
            replace: true,
        },
    );
}

const money = (value: string | number) =>
    Number(value ?? 0).toLocaleString();

const dailyClosingHref = computed(
    () => `/admin/reports?branch_id=${selectedBranchId.value}`,
);
</script>

<template>
    <BankLayout :role="role" :notification-count="notificationCount">
        <div class="mx-auto grid w-full max-w-7xl gap-5">
            <header
                class="flex flex-col gap-3 border-b border-line pb-4 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <p
                        class="text-xs font-black tracking-[0.18em] text-slate uppercase"
                    >
                        Closing & Reports
                    </p>
                    <h1 class="text-2xl font-black tracking-tight">
                        Branch Reconciliation History
                    </h1>
                    <p class="mt-1 text-sm font-semibold text-slate">
                        {{ selectedBranch?.name ?? 'Selected branch' }}
                    </p>
                </div>

                <Link
                    :href="dailyClosingHref"
                    class="bank-button bank-button-secondary"
                >
                    Daily Closing
                </Link>
            </header>

            <section
                class="rounded-xl border border-line bg-card p-5 shadow-sm"
            >
                <div class="grid gap-3 md:grid-cols-4">
                    <label>
                        <span class="bank-label">Branch</span>
                        <select
                            v-model.number="selectedBranchId"
                            class="bank-input"
                            @change="loadBranch"
                        >
                            <option
                                v-for="branch in branches"
                                :key="branch.id"
                                :value="branch.id"
                            >
                                {{ branch.name }} ({{ branch.code }})
                            </option>
                        </select>
                    </label>

                    <input
                        v-model="search"
                        type="search"
                        class="bank-input"
                        placeholder="Search closer, date or notes"
                    />
                    <input
                        v-model="dateFrom"
                        type="date"
                        class="bank-input"
                        aria-label="From date"
                    />
                    <input
                        v-model="dateTo"
                        type="date"
                        class="bank-input"
                        aria-label="To date"
                    />
                </div>

                <div class="mt-4 overflow-auto rounded-lg border border-line">
                    <table class="w-full min-w-[980px] text-left text-sm">
                        <thead class="bg-mist text-xs text-slate uppercase">
                            <tr>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Branch</th>
                                <th class="px-4 py-3">Closed By</th>
                                <th class="px-4 py-3 text-right">Cash</th>
                                <th class="px-4 py-3 text-right">
                                    Branch Digital
                                </th>
                                <th class="px-4 py-3 text-right">
                                    Shared Digital
                                </th>
                                <th class="px-4 py-3 text-right">Grand</th>
                                <th class="px-4 py-3">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in paginated" :key="row.id">
                                <td class="px-4 py-3 font-bold text-ink">
                                    {{ row.recon_date ?? '-' }}
                                </td>
                                <td class="px-4 py-3 text-slate">
                                    {{ row.branch_name ?? '-' }}
                                    <span
                                        v-if="row.branch_code"
                                        class="text-xs"
                                    >
                                        ({{ row.branch_code }})
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate">
                                    {{ row.closed_by_name ?? '-' }}
                                </td>
                                <td class="money px-4 py-3 text-right">
                                    {{ money(row.total_cash) }}
                                </td>
                                <td class="money px-4 py-3 text-right">
                                    {{ money(row.total_digital) }}
                                </td>
                                <td class="money px-4 py-3 text-right">
                                    {{
                                        money(
                                            row.shared_global_digital_total,
                                        )
                                    }}
                                </td>
                                <td
                                    class="money px-4 py-3 text-right font-bold"
                                >
                                    {{ money(row.grand_total) }}
                                </td>
                                <td class="px-4 py-3 text-slate">
                                    {{ row.notes ?? '-' }}
                                </td>
                            </tr>

                            <tr v-if="!paginated.length">
                                <td
                                    colspan="8"
                                    class="px-4 py-8 text-center text-slate"
                                >
                                    No reconciliation records found for this
                                    branch.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <footer
                    class="mt-4 grid items-center gap-3 text-sm font-semibold text-slate md:grid-cols-3"
                >
                    <span>
                        Showing
                        {{
                            filtered.length
                                ? (page - 1) * pageSize + 1
                                : 0
                        }}
                        to
                        {{ Math.min(page * pageSize, filtered.length) }}
                        of {{ filtered.length }} entries
                    </span>

                    <label class="bank-page-size justify-self-center">
                        Show
                        <select
                            v-model.number="pageSize"
                            class="bank-page-size-select"
                        >
                            <option :value="10">10</option>
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                        </select>
                        entries
                    </label>

                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            class="bank-button bank-button-secondary px-3 py-2"
                            :disabled="page <= 1"
                            @click="page--"
                        >
                            Previous
                        </button>
                        <span class="self-center">
                            {{ page }} / {{ pageCount }}
                        </span>
                        <button
                            type="button"
                            class="bank-button bank-button-secondary px-3 py-2"
                            :disabled="page >= pageCount"
                            @click="page++"
                        >
                            Next
                        </button>
                    </div>
                </footer>
            </section>
        </div>
    </BankLayout>
</template>
