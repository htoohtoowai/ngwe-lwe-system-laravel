<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BankLayout from '@/layouts/BankLayout.vue';

type MoneyValue = string | number | null | undefined;

type Branch = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
};

type Summary = {
    branch_id: number;
    branch_code: string;
    branch_name: string;
    summary_date: string;
    total_cash_in: MoneyValue;
    total_cash_out: MoneyValue;
    total_send_money: MoneyValue;
    total_receive_money: MoneyValue;
    total_transfer: MoneyValue;
    total_exchange: MoneyValue;
    total_commission: MoneyValue;
    total_customer_fees: MoneyValue;
    total_profit: MoneyValue;
    transaction_count: number;
    pending_cash_in_count: number;
    pending_send_money_count: number;
    main_vault_total: MoneyValue;
    employee_floats_total: MoneyValue;
    total_cash: MoneyValue;
    total_digital: MoneyValue;
    shared_global_digital_total: MoneyValue;
    visible_digital_total: MoneyValue;
    grand_total: MoneyValue;
};

type AdminData = {
    branches: Branch[];
    selectedBranchId: number;
    selectedBranch?: Branch | null;
    dailySummary: Summary;
};

const props = defineProps<{
    role: 'admin';
    announcement?: string | null;
    notificationCount?: number;
    adminData?: AdminData;
}>();

const branches = computed(() => props.adminData?.branches ?? []);
const summary = computed(() => props.adminData?.dailySummary ?? null);

const selectedBranchId = ref<number>(
    props.adminData?.selectedBranchId ?? branches.value[0]?.id ?? 0,
);
const reportDate = ref(
    summary.value?.summary_date ?? new Date().toISOString().slice(0, 10),
);

watch(
    () => props.adminData?.selectedBranchId,
    (value) => {
        if (value) selectedBranchId.value = value;
    },
);

watch(
    () => props.adminData?.dailySummary?.summary_date,
    (value) => {
        if (value) reportDate.value = value;
    },
);

const selectedBranch = computed(
    () =>
        branches.value.find(
            (branch) => branch.id === selectedBranchId.value,
        ) ?? null,
);

const closeForm = useForm({
    branch_id: selectedBranchId.value,
    date: reportDate.value,
    notes: '',
});

watch(selectedBranchId, (value) => {
    closeForm.branch_id = value;
});

watch(reportDate, (value) => {
    closeForm.date = value;
});

const money = (value: MoneyValue): string =>
    Number(value ?? 0).toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    });

function loadReport(): void {
    router.get(
        '/admin/reports',
        {
            branch_id: selectedBranchId.value,
            report_date: reportDate.value,
        },
        {
            preserveScroll: true,
            preserveState: false,
            replace: true,
        },
    );
}

function closeDay(): void {
    closeForm.post('/admin/branch-reporting/close-day', {
        preserveScroll: true,
        onSuccess: () => {
            closeForm.notes = '';
            loadReport();
        },
    });
}

const pdfHref = computed(
    () =>
        `/admin/branch-reporting/daily.pdf?branch_id=${selectedBranchId.value}&date=${encodeURIComponent(reportDate.value)}`,
);

const reconciliationHref = computed(
    () =>
        `/admin/reports/reconciliations?branch_id=${selectedBranchId.value}`,
);
</script>

<template>
    <BankLayout
        :role="role"
        :announcement="announcement"
        :notification-count="notificationCount"
    >
        <div class="mx-auto grid w-full max-w-7xl gap-5">
            <header
                class="flex flex-col gap-4 border-b border-line pb-4 lg:flex-row lg:items-end lg:justify-between"
            >
                <div>
                    <p
                        class="text-xs font-black tracking-[0.18em] text-slate uppercase"
                    >
                        Closing & Reports
                    </p>
                    <h1 class="text-2xl font-black text-ink">
                        Branch Daily Closing
                    </h1>
                    <p class="mt-1 text-sm font-semibold text-slate">
                        Review and close one branch at a time.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Link
                        :href="reconciliationHref"
                        class="bank-button bank-button-secondary"
                    >
                        Reconciliation History
                    </Link>
                    <a
                        :href="pdfHref"
                        target="_blank"
                        rel="noopener"
                        class="bank-button bank-button-secondary"
                    >
                        Export PDF
                    </a>
                </div>
            </header>

            <section
                class="rounded-xl border border-line bg-card p-5 shadow-sm"
            >
                <form
                    class="grid gap-4 lg:grid-cols-[1fr_1fr_auto]"
                    @submit.prevent="loadReport"
                >
                    <label>
                        <span class="bank-label">Branch</span>
                        <select
                            v-model.number="selectedBranchId"
                            class="bank-input"
                            required
                        >
                            <option
                                v-for="branch in branches"
                                :key="branch.id"
                                :value="branch.id"
                            >
                                {{ branch.name }} ({{ branch.code }})
                                {{ branch.is_active ? '' : ' - Inactive' }}
                            </option>
                        </select>
                    </label>

                    <label>
                        <span class="bank-label">Business Date</span>
                        <input
                            v-model="reportDate"
                            type="date"
                            class="bank-input"
                            required
                        />
                    </label>

                    <div class="flex items-end">
                        <button
                            type="submit"
                            class="bank-button bank-button-primary w-full lg:w-auto"
                        >
                            Load Report
                        </button>
                    </div>
                </form>

                <div
                    v-if="selectedBranch"
                    class="mt-4 rounded-lg bg-mist px-4 py-3 text-sm font-semibold text-slate"
                >
                    Viewing
                    <strong class="text-ink">
                        {{ selectedBranch.name }} ({{ selectedBranch.code }})
                    </strong>
                    · {{ reportDate }}
                </div>
            </section>

            <section
                v-if="summary"
                class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
            >
                <div class="rounded-xl border border-line bg-card p-4 shadow-sm">
                    <p class="text-xs font-bold text-slate">Branch Cash</p>
                    <p class="money mt-1 text-xl font-black text-ink">
                        {{ money(summary.total_cash) }}
                    </p>
                    <p class="mt-1 text-xs text-slate">
                        Vault {{ money(summary.main_vault_total) }} +
                        Teller floats {{ money(summary.employee_floats_total) }}
                    </p>
                </div>

                <div class="rounded-xl border border-line bg-card p-4 shadow-sm">
                    <p class="text-xs font-bold text-slate">
                        Branch-owned Digital
                    </p>
                    <p class="money mt-1 text-xl font-black text-ink">
                        {{ money(summary.total_digital) }}
                    </p>
                    <p class="mt-1 text-xs text-slate">
                        Only accounts owned by this branch.
                    </p>
                </div>

                <div class="rounded-xl border border-line bg-card p-4 shadow-sm">
                    <p class="text-xs font-bold text-slate">
                        Shared Global Digital
                    </p>
                    <p class="money mt-1 text-xl font-black text-ink">
                        {{ money(summary.shared_global_digital_total) }}
                    </p>
                    <p class="mt-1 text-xs text-slate">
                        Visible here, but excluded from branch grand total.
                    </p>
                </div>

                <div class="rounded-xl border border-line bg-card p-4 shadow-sm">
                    <p class="text-xs font-bold text-slate">
                        Branch Grand Total
                    </p>
                    <p class="money mt-1 text-xl font-black text-ink">
                        {{ money(summary.grand_total) }}
                    </p>
                    <p class="mt-1 text-xs text-slate">
                        Branch cash + branch-owned digital.
                    </p>
                </div>
            </section>

            <section
                v-if="summary"
                class="rounded-xl border border-line bg-card p-5 shadow-sm"
            >
                <div
                    class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <h2 class="text-lg font-black text-ink">
                            Daily Activity
                        </h2>
                        <p class="text-sm font-semibold text-slate">
                            {{ summary.branch_name }} · {{ summary.summary_date }}
                        </p>
                    </div>
                    <p class="text-sm font-bold text-slate">
                        {{ summary.transaction_count }} completed transactions
                    </p>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="rounded-lg bg-mist p-4">
                        <p class="text-xs font-bold text-slate">Cash In</p>
                        <p class="money mt-1 font-black text-ink">
                            {{ money(summary.total_cash_in) }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-mist p-4">
                        <p class="text-xs font-bold text-slate">Cash Out</p>
                        <p class="money mt-1 font-black text-ink">
                            {{ money(summary.total_cash_out) }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-mist p-4">
                        <p class="text-xs font-bold text-slate">Send Money</p>
                        <p class="money mt-1 font-black text-ink">
                            {{ money(summary.total_send_money) }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-mist p-4">
                        <p class="text-xs font-bold text-slate">
                            Receive Money
                        </p>
                        <p class="money mt-1 font-black text-ink">
                            {{ money(summary.total_receive_money) }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-mist p-4">
                        <p class="text-xs font-bold text-slate">Transfer</p>
                        <p class="money mt-1 font-black text-ink">
                            {{ money(summary.total_transfer) }}
                        </p>
                    </div>
                    <div class="rounded-lg bg-mist p-4">
                        <p class="text-xs font-bold text-slate">Exchange</p>
                        <p class="money mt-1 font-black text-ink">
                            {{ money(summary.total_exchange) }}
                        </p>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border border-line p-4">
                        <p class="text-xs font-bold text-slate">Commission</p>
                        <p class="money mt-1 font-black text-ink">
                            {{ money(summary.total_commission) }}
                        </p>
                    </div>
                    <div class="rounded-lg border border-line p-4">
                        <p class="text-xs font-bold text-slate">
                            Customer Fees
                        </p>
                        <p class="money mt-1 font-black text-ink">
                            {{ money(summary.total_customer_fees) }}
                        </p>
                    </div>
                    <div class="rounded-lg border border-line p-4">
                        <p class="text-xs font-bold text-slate">Profit</p>
                        <p class="money mt-1 font-black text-ink">
                            {{ money(summary.total_profit) }}
                        </p>
                    </div>
                    <div class="rounded-lg border border-line p-4">
                        <p class="text-xs font-bold text-slate">
                            Pending Cash Actions
                        </p>
                        <p class="mt-1 font-black text-ink">
                            {{
                                summary.pending_cash_in_count +
                                summary.pending_send_money_count
                            }}
                        </p>
                    </div>
                </div>
            </section>

            <section
                v-if="summary"
                class="rounded-xl border border-line bg-card p-5 shadow-sm"
            >
                <div>
                    <h2 class="text-lg font-black text-ink">Close Day</h2>
                    <p class="mt-1 text-sm font-semibold text-slate">
                        This stores a reconciliation snapshot for
                        {{ selectedBranch?.name ?? 'the selected branch' }}.
                    </p>
                </div>

                <form class="mt-4 grid gap-4" @submit.prevent="closeDay">
                    <label>
                        <span class="bank-label">Closing Notes</span>
                        <textarea
                            v-model.trim="closeForm.notes"
                            rows="3"
                            class="bank-input resize-none"
                            placeholder="Optional reconciliation notes"
                        />
                    </label>

                    <p
                        v-if="closeForm.errors.branch_id"
                        class="text-sm font-bold text-brand"
                    >
                        {{ closeForm.errors.branch_id }}
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            type="submit"
                            class="bank-button bank-button-danger"
                            :disabled="
                                closeForm.processing ||
                                !selectedBranch?.is_active ||
                                selectedBranchId <= 0
                            "
                        >
                            {{
                                closeForm.processing
                                    ? 'Closing…'
                                    : 'Close Selected Branch'
                            }}
                        </button>

                        <span
                            v-if="selectedBranch && !selectedBranch.is_active"
                            class="text-xs font-bold text-brand"
                        >
                            Inactive branches cannot be closed.
                        </span>
                    </div>
                </form>
            </section>
        </div>
    </BankLayout>
</template>
