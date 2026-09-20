<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BankLayout from '@/layouts/BankLayout.vue';

type Branch = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
};

type VaultLogDetail = {
    id: number;
    denomination: number;
    quantity: number;
    amount: number;
    affects_main_vault?: boolean;
};

type ReconciliationStatus =
    | 'matched'
    | 'mismatch'
    | 'missing_cash_log'
    | 'legacy_unlinked'
    | 'not_applicable';

type VaultLog = {
    id: number;
    branch_id: number;
    batch_id: string | null;
    txn_type: string;
    movement_type: string | null;
    source_type: string | null;
    source_id: number | null;
    destination_type: string | null;
    destination_id: number | null;
    float_id: number | null;
    transaction_id: number | null;
    performed_by_name: string | null;
    verified_by_name: string | null;
    note: string | null;
    created_at?: string | null;
    total_amount: number;
    denomination_count: number;
    details: VaultLogDetail[];
    cash_total_amount: number;
    cash_log_count: number;
    cash_details: VaultLogDetail[];
    reconciliation_status: ReconciliationStatus;
    reconciliation_issues: string[];
};

const props = defineProps<{
    role: 'admin';
    notificationCount?: number;
    rows: VaultLog[];
    branches: Branch[];
    selectedBranchId: number;
}>();

const rows = computed(() => props.rows ?? []);
const selectedBranchId = ref(props.selectedBranchId);
const selectedBranch = computed(
    () =>
        props.branches.find(
            (branch) => branch.id === selectedBranchId.value,
        ) ?? null,
);

const search = ref('');
const page = ref(1);
const pageSize = ref(25);
const selectedLog = ref<VaultLog | null>(null);

const filtered = computed(() => {
    const query = search.value.trim().toLowerCase();

    if (!query) return rows.value;

    return rows.value.filter((row) =>
        [
            row.id,
            row.batch_id,
            row.txn_type,
            row.movement_type,
            row.source_type,
            row.destination_type,
            row.transaction_id,
            row.float_id,
            row.note,
            row.performed_by_name,
            row.total_amount,
            row.reconciliation_status,
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

watch([search, pageSize], () => {
    page.value = 1;
});

watch(pageCount, (count) => {
    page.value = Math.min(page.value, count);
});

function loadBranch(): void {
    router.get(
        '/admin/vault/log',
        { branch_id: selectedBranchId.value },
        {
            preserveScroll: true,
            preserveState: false,
            replace: true,
        },
    );
}

const money = (value: number) => Number(value ?? 0).toLocaleString();
const dateTime = (value?: string | null) =>
    value ? new Date(value).toLocaleString() : '-';

function typeLabel(log: VaultLog): string {
    return log.movement_type ?? log.txn_type;
}

function flowLabel(log: VaultLog): string {
    if (!log.source_type && !log.destination_type) return '-';

    return `${log.source_type ?? '?'} → ${log.destination_type ?? '?'}`;
}

function statusClass(status: ReconciliationStatus): string {
    if (status === 'matched') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }

    if (status === 'legacy_unlinked' || status === 'not_applicable') {
        return 'border-amber-200 bg-amber-50 text-amber-700';
    }

    return 'border-rose-200 bg-rose-50 text-rose-700';
}
</script>

<template>
    <BankLayout :role="role" :notification-count="notificationCount">
        <div class="mx-auto grid w-full max-w-7xl gap-5">
            <header class="border-b border-line pb-4">
                <p
                    class="text-xs font-black tracking-[0.18em] text-slate uppercase"
                >
                    Branch Vault
                </p>
                <h1 class="text-2xl font-black text-ink">Vault Log</h1>
                <p class="mt-1 text-sm font-semibold text-slate">
                    {{ selectedBranch?.name ?? 'Selected branch' }}
                </p>
            </header>

            <section
                class="rounded-xl border border-line bg-card p-5 shadow-sm"
            >
                <div class="grid gap-3 sm:grid-cols-2">
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

                    <label>
                        <span class="bank-label">Search</span>
                        <input
                            v-model="search"
                            type="search"
                            class="bank-input"
                            placeholder="Reference, flow, transaction or note"
                        />
                    </label>
                </div>

                <div class="mt-4 overflow-auto rounded-lg border border-line">
                    <table class="w-full min-w-[1100px] text-left text-sm">
                        <thead class="bg-mist text-xs text-slate uppercase">
                            <tr>
                                <th class="px-4 py-3">Time</th>
                                <th class="px-4 py-3">Reference</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Flow</th>
                                <th class="px-4 py-3">Note</th>
                                <th class="px-4 py-3 text-right">Total</th>
                                <th class="px-4 py-3">Reconcile</th>
                                <th class="px-4 py-3 text-right">Details</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="log in paginated" :key="log.id">
                                <td class="px-4 py-3 text-xs text-slate">
                                    {{ dateTime(log.created_at) }}
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">
                                    {{
                                        log.batch_id
                                            ? log.batch_id
                                                  .slice(0, 8)
                                                  .toUpperCase()
                                            : 'legacy'
                                    }}
                                </td>
                                <td class="px-4 py-3 font-bold">
                                    {{ typeLabel(log) }}
                                </td>
                                <td class="px-4 py-3 text-xs text-slate">
                                    {{ flowLabel(log) }}
                                </td>
                                <td class="px-4 py-3 text-slate">
                                    {{ log.note ?? '-' }}
                                </td>
                                <td
                                    class="money px-4 py-3 text-right font-bold"
                                >
                                    {{ money(log.total_amount) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full border px-2.5 py-1 text-[11px] font-bold"
                                        :class="
                                            statusClass(
                                                log.reconciliation_status,
                                            )
                                        "
                                    >
                                        {{ log.reconciliation_status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        type="button"
                                        class="bank-button bank-button-secondary px-3 py-2 text-xs"
                                        @click="selectedLog = log"
                                    >
                                        {{ log.denomination_count }} notes
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!paginated.length">
                                <td
                                    colspan="8"
                                    class="px-4 py-8 text-center text-slate"
                                >
                                    No vault logs found for this branch.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <footer
                    class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm font-semibold text-slate"
                >
                    <span>{{ filtered.length }} movements</span>
                    <div class="flex items-center gap-2">
                        <select
                            v-model.number="pageSize"
                            class="bank-page-size-select"
                        >
                            <option :value="10">10</option>
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                        </select>
                        <button
                            class="bank-button bank-button-secondary px-3 py-2"
                            :disabled="page <= 1"
                            @click="page--"
                        >
                            Previous
                        </button>
                        <span>{{ page }} / {{ pageCount }}</span>
                        <button
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

        <Teleport to="body">
            <div
                v-if="selectedLog"
                class="fixed inset-0 z-50 grid place-items-center bg-black/45 p-4"
                @click.self="selectedLog = null"
            >
                <section
                    class="max-h-[90vh] w-full max-w-4xl overflow-auto rounded-xl border border-line bg-card p-5 shadow-xl"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-black text-ink">
                                Vault Reconciliation Detail
                            </h2>
                            <p class="text-sm font-semibold text-slate">
                                {{ typeLabel(selectedLog) }} ·
                                {{ dateTime(selectedLog.created_at) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="bank-button bank-button-secondary"
                            @click="selectedLog = null"
                        >
                            Close
                        </button>
                    </div>

                    <div class="mt-5 grid gap-5 md:grid-cols-2">
                        <div>
                            <h3 class="font-black text-ink">
                                vault_transactions
                            </h3>
                            <div
                                class="mt-2 overflow-hidden rounded-lg border border-line"
                            >
                                <table class="w-full text-sm">
                                    <tbody class="divide-y divide-line">
                                        <tr
                                            v-for="detail in selectedLog.details"
                                            :key="detail.id"
                                        >
                                            <td class="money px-4 py-3">
                                                {{ money(detail.denomination) }}
                                            </td>
                                            <td class="px-4 py-3 text-right">
                                                {{ detail.quantity }}
                                            </td>
                                            <td
                                                class="money px-4 py-3 text-right"
                                            >
                                                {{ money(detail.amount) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div>
                            <h3 class="font-black text-ink">
                                cash_denomination_logs
                            </h3>
                            <div
                                class="mt-2 overflow-hidden rounded-lg border border-line"
                            >
                                <table class="w-full text-sm">
                                    <tbody class="divide-y divide-line">
                                        <tr
                                            v-for="detail in selectedLog.cash_details"
                                            :key="detail.id"
                                        >
                                            <td class="money px-4 py-3">
                                                {{ money(detail.denomination) }}
                                            </td>
                                            <td class="px-4 py-3 text-right">
                                                {{ detail.quantity }}
                                            </td>
                                            <td
                                                class="money px-4 py-3 text-right"
                                            >
                                                {{ money(detail.amount) }}
                                            </td>
                                        </tr>
                                        <tr
                                            v-if="
                                                !selectedLog.cash_details.length
                                            "
                                        >
                                            <td
                                                colspan="3"
                                                class="px-4 py-6 text-center text-slate"
                                            >
                                                No linked cash rows.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="selectedLog.reconciliation_issues.length"
                        class="mt-5 rounded-lg border border-brand/20 bg-brand-soft p-4 text-sm"
                    >
                        <p class="font-black text-brand">
                            Reconciliation issues
                        </p>
                        <ul class="mt-2 list-disc pl-5">
                            <li
                                v-for="issue in selectedLog.reconciliation_issues"
                                :key="issue"
                            >
                                {{ issue }}
                            </li>
                        </ul>
                    </div>
                </section>
            </div>
        </Teleport>
    </BankLayout>
</template>
