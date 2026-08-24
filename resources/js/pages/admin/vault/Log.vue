<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BankLayout from '@/layouts/BankLayout.vue';

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
}>();

const rows = computed(() => props.rows ?? []);
const search = ref('');
const page = ref(1);
const pageSize = ref(25);
const loading = ref(false);
const selectedLog = ref<VaultLog | null>(null);

const filtered = computed(() => {
    const query = search.value.trim().toLowerCase();

    return rows.value.filter((row) => {
        if (query === '') {
            return true;
        }

        const detailValues = [...row.details, ...row.cash_details].flatMap(
            (detail) => [detail.denomination, detail.quantity, detail.amount],
        );

        return [
            row.id,
            row.batch_id,
            row.txn_type,
            row.movement_type,
            row.source_type,
            row.source_id,
            row.destination_type,
            row.destination_id,
            row.transaction_id,
            row.float_id,
            typeLabel(row),
            row.note,
            row.performed_by_name,
            row.verified_by_name,
            row.total_amount,
            row.cash_total_amount,
            row.reconciliation_status,
            ...row.reconciliation_issues,
            ...detailValues,
        ].some((value) =>
            String(value ?? '')
                .toLowerCase()
                .includes(query),
        );
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

watch([search, pageSize], () => (page.value = 1));
watch(pageCount, (count) => (page.value = Math.min(page.value, count)));

const money = (value: number) => Number(value ?? 0).toLocaleString();
const dateTime = (value?: string | null) =>
    value ? new Date(value).toLocaleString() : '-';

function typeLabel(log: VaultLog): string {
    if (log.movement_type === 'admin_to_cashier') {
        return 'cashier_vault_deposit';
    }

    if (log.movement_type === 'cashier_to_admin') {
        return 'cashier_vault_withdraw';
    }

    if (!log.movement_type) {
        const note = (log.note ?? '').toLowerCase();
        if (note.includes('owner deposit to cashier vault')) {
            return 'cashier_vault_deposit';
        }
        if (note.includes('owner withdrawal from cashier vault')) {
            return 'cashier_vault_withdraw';
        }
    }

    return log.movement_type ?? log.txn_type;
}

function flowLabel(log: VaultLog): string {
    if (!log.source_type && !log.destination_type) {
        return '-';
    }

    return `${log.source_type ?? '?'} → ${log.destination_type ?? '?'}`;
}

function reference(log: VaultLog): string {
    if (!log.batch_id) {
        return 'legacy';
    }

    return log.batch_id.slice(0, 8).toUpperCase();
}

function statusLabel(status: ReconciliationStatus): string {
    return {
        matched: 'MATCHED',
        mismatch: 'MISMATCH',
        missing_cash_log: 'MISSING CASH LOG',
        legacy_unlinked: 'LEGACY / UNLINKED',
        not_applicable: 'N/A (VERIFY)',
    }[status];
}

function statusClass(status: ReconciliationStatus): string {
    if (status === 'matched') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }

    if (status === 'not_applicable') {
        return 'border-slate-200 bg-slate-50 text-slate-600';
    }

    if (status === 'legacy_unlinked') {
        return 'border-amber-200 bg-amber-50 text-amber-700';
    }

    return 'border-rose-200 bg-rose-50 text-rose-700';
}

function openDetails(log: VaultLog): void {
    selectedLog.value = log;
}

function closeDetails(): void {
    selectedLog.value = null;
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
                    placeholder="Search reference, flow, transaction, note or denomination"
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
                <table class="w-full min-w-[1260px] text-left text-sm">
                    <thead class="bg-mist text-xs text-slate uppercase">
                        <tr>
                            <th class="px-4 py-3">Time</th>
                            <th class="px-4 py-3">Reference</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Flow</th>
                            <th class="px-4 py-3">Note</th>
                            <th class="px-4 py-3 text-right">Total</th>
                            <th class="px-4 py-3">Reconcile</th>
                            <th class="px-4 py-3 text-center">Details</th>
                            <th class="px-4 py-3">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="log in paginated" :key="log.id">
                            <td class="px-4 py-3 text-xs text-slate">
                                {{ dateTime(log.created_at) }}
                            </td>
                            <td class="px-4 py-3 font-mono text-xs font-bold">
                                {{ reference(log) }}
                            </td>
                            <td class="px-4 py-3 font-bold">
                                {{ typeLabel(log) }}
                            </td>
                            <td
                                class="px-4 py-3 text-xs font-semibold text-slate"
                            >
                                {{ flowLabel(log) }}
                            </td>
                            <td class="max-w-[320px] px-4 py-3 text-slate">
                                {{ log.note ?? '-' }}
                            </td>
                            <td class="money px-4 py-3 text-right font-bold">
                                {{ money(log.total_amount) }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-bold"
                                    :class="
                                        statusClass(log.reconciliation_status)
                                    "
                                >
                                    {{ statusLabel(log.reconciliation_status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <button
                                    type="button"
                                    class="bank-button bank-button-secondary px-3 py-2 text-xs"
                                    @click="openDetails(log)"
                                >
                                    Details ({{ log.denomination_count }})
                                </button>
                            </td>
                            <td class="px-4 py-3 text-slate">
                                {{ log.performed_by_name ?? '-' }}
                            </td>
                        </tr>
                        <tr v-if="!paginated.length">
                            <td
                                colspan="9"
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
                <span>
                    Showing
                    {{ filtered.length ? (page - 1) * pageSize + 1 : 0 }} to
                    {{ Math.min(page * pageSize, filtered.length) }} of
                    {{ filtered.length }} transactions
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
                    transactions
                </label>
                <div class="flex justify-end gap-2">
                    <button
                        class="bank-button bank-button-secondary px-3 py-2"
                        :disabled="page <= 1"
                        @click="page--"
                    >
                        Previous
                    </button>
                    <span class="self-center"
                        >{{ page }} / {{ pageCount }}</span
                    >
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

        <div
            v-if="selectedLog"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4"
            @click.self="closeDetails"
        >
            <section
                class="max-h-[92vh] w-full max-w-5xl overflow-auto rounded-xl border border-line bg-card shadow-xl"
            >
                <header
                    class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-line bg-card p-5"
                >
                    <div>
                        <h2 class="text-lg font-bold">
                            Cash Movement Reconciliation
                        </h2>
                        <p class="mt-1 text-sm text-slate">
                            {{ typeLabel(selectedLog) }} ·
                            {{ dateTime(selectedLog.created_at) }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="bank-button bank-button-secondary px-3 py-2"
                        @click="closeDetails"
                    >
                        Close
                    </button>
                </header>

                <div class="p-5">
                    <div
                        class="grid gap-3 rounded-lg border border-line bg-mist/30 p-4 text-sm md:grid-cols-3"
                    >
                        <p>
                            <span class="font-semibold">Reference:</span><br />
                            <span class="font-mono text-xs">{{
                                selectedLog.batch_id ?? 'legacy / unlinked'
                            }}</span>
                        </p>
                        <p>
                            <span class="font-semibold">Flow:</span><br />
                            {{ flowLabel(selectedLog) }}
                        </p>
                        <p>
                            <span class="font-semibold">Status:</span><br />
                            <span
                                class="mt-1 inline-flex rounded-full border px-2.5 py-1 text-[11px] font-bold"
                                :class="
                                    statusClass(
                                        selectedLog.reconciliation_status,
                                    )
                                "
                            >
                                {{
                                    statusLabel(
                                        selectedLog.reconciliation_status,
                                    )
                                }}
                            </span>
                        </p>
                        <p>
                            <span class="font-semibold">Transaction:</span>
                            #{{ selectedLog.transaction_id ?? '-' }}
                        </p>
                        <p>
                            <span class="font-semibold">Float:</span>
                            #{{ selectedLog.float_id ?? '-' }}
                        </p>
                        <p>
                            <span class="font-semibold">By:</span>
                            {{ selectedLog.performed_by_name ?? '-' }}
                        </p>
                        <p class="md:col-span-3">
                            <span class="font-semibold">Note:</span>
                            {{ selectedLog.note ?? '-' }}
                        </p>
                    </div>

                    <div
                        v-if="selectedLog.reconciliation_issues.length"
                        class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"
                    >
                        <p class="font-bold">Reconciliation issues</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li
                                v-for="issue in selectedLog.reconciliation_issues"
                                :key="issue"
                            >
                                {{ issue }}
                            </li>
                        </ul>
                    </div>

                    <div class="mt-5 grid gap-5 lg:grid-cols-2">
                        <section>
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="font-bold">vault_transactions</h3>
                                <span class="money text-sm font-bold">{{
                                    money(selectedLog.total_amount)
                                }}</span>
                            </div>
                            <div
                                class="overflow-auto rounded-lg border border-line"
                            >
                                <table
                                    class="w-full min-w-[460px] text-left text-sm"
                                >
                                    <thead
                                        class="bg-mist text-xs text-slate uppercase"
                                    >
                                        <tr>
                                            <th class="px-4 py-3">
                                                Denomination
                                            </th>
                                            <th class="px-4 py-3 text-right">
                                                Qty
                                            </th>
                                            <th class="px-4 py-3 text-right">
                                                Amount
                                            </th>
                                        </tr>
                                    </thead>
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
                        </section>

                        <section>
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="font-bold">
                                    cash_denomination_logs
                                </h3>
                                <span class="money text-sm font-bold">{{
                                    money(selectedLog.cash_total_amount)
                                }}</span>
                            </div>
                            <div
                                class="overflow-auto rounded-lg border border-line"
                            >
                                <table
                                    class="w-full min-w-[460px] text-left text-sm"
                                >
                                    <thead
                                        class="bg-mist text-xs text-slate uppercase"
                                    >
                                        <tr>
                                            <th class="px-4 py-3">
                                                Denomination
                                            </th>
                                            <th class="px-4 py-3 text-right">
                                                Qty
                                            </th>
                                            <th class="px-4 py-3 text-right">
                                                Amount
                                            </th>
                                            <th class="px-4 py-3">Vault</th>
                                        </tr>
                                    </thead>
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
                                            <td
                                                class="px-4 py-3 text-xs font-semibold"
                                            >
                                                {{
                                                    detail.affects_main_vault
                                                        ? 'AFFECTS'
                                                        : 'TRACE ONLY'
                                                }}
                                            </td>
                                        </tr>
                                        <tr
                                            v-if="
                                                !selectedLog.cash_details.length
                                            "
                                        >
                                            <td
                                                colspan="4"
                                                class="px-4 py-6 text-center text-slate"
                                            >
                                                No linked cash denomination
                                                rows.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>
                </div>
            </section>
        </div>
    </BankLayout>
</template>
