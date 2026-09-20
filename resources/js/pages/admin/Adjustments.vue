<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DenomDrawer from '@/components/bank/DenomDrawer.vue';
import BankLayout from '@/layouts/BankLayout.vue';

type Branch = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
};

type Account = {
    id: number;
    branch_id: number | null;
    account_name: string;
    account_type: 'PAY' | 'BANK' | string;
    account_identifier: string;
    balance: string | number;
    is_active: boolean;
    company?: { name?: string | null } | null;
};

type AdjustmentRow = {
    id: number;
    branch_id: number;
    branch_name: string | null;
    branch_code: string | null;
    target_type: 'cash' | 'account';
    account_id: number | null;
    account_name: string | null;
    account_type: string | null;
    direction: 'deposit' | 'withdraw';
    amount: string | number;
    denominations: Record<number, number>;
    note: string | null;
    status: 'PENDING' | 'CONFIRMED' | 'REJECTED';
    requested_by_name: string | null;
    assigned_cashier_name: string | null;
    confirmed_by_name: string | null;
    rejected_by_name: string | null;
    created_at: string | null;
};

const props = defineProps<{
    role: 'admin';
    branches: Branch[];
    selectedBranchId: number;
    accounts: Account[];
    rows: AdjustmentRow[];
}>();

const selectedBranchId = ref(props.selectedBranchId);
const notes = [20000, 10000, 5000, 1000, 500, 200, 100, 50];

const form = useForm({
    branch_id: props.selectedBranchId,
    target_type: 'cash' as 'cash' | 'account',
    account_id: null as number | null,
    direction: 'deposit' as 'deposit' | 'withdraw',
    amount: 0,
    denominations: {} as Record<number, number>,
    note: '',
});

const selectedBranch = computed(
    () =>
        props.branches.find(
            (branch) => branch.id === selectedBranchId.value,
        ) ?? null,
);

const visibleAccounts = computed(() =>
    props.accounts.filter(
        (account) =>
            account.is_active &&
            (account.branch_id === null ||
                account.branch_id === selectedBranchId.value),
    ),
);

const cashTotal = computed(() =>
    Object.entries(form.denominations).reduce(
        (sum, [denomination, quantity]) =>
            sum + Number(denomination) * Number(quantity ?? 0),
        0,
    ),
);

const pendingCount = computed(
    () => props.rows.filter((row) => row.status === 'PENDING').length,
);

function loadBranch(): void {
    form.branch_id = selectedBranchId.value;
    form.account_id = null;

    router.get(
        '/admin/adjustments',
        { branch_id: selectedBranchId.value },
        {
            preserveScroll: true,
            preserveState: false,
            replace: true,
        },
    );
}

function submit(): void {
    form.branch_id = selectedBranchId.value;

    form.post('/admin/adjustments', {
        preserveScroll: true,
        onSuccess: () => {
            form.account_id = null;
            form.amount = 0;
            form.denominations = {};
            form.note = '';
        },
    });
}

const money = (value: string | number | null | undefined) =>
    Number(value ?? 0).toLocaleString();

const dateTime = (value?: string | null) =>
    value ? new Date(value).toLocaleString() : '-';

const targetLabel = (row: AdjustmentRow) =>
    row.target_type === 'cash'
        ? 'Cash Vault'
        : `${row.account_type ?? 'Account'} · ${row.account_name ?? '-'}`;
</script>

<template>
    <BankLayout :role="role">
        <div class="mx-auto grid w-full max-w-7xl gap-5">
            <header
                class="flex flex-col gap-3 border-b border-line pb-4 lg:flex-row lg:items-end lg:justify-between"
            >
                <div>
                    <p
                        class="text-xs font-black tracking-[0.18em] text-slate uppercase"
                    >
                        Admin → Cashier
                    </p>
                    <h1 class="text-2xl font-black text-ink">
                        Adjustment Requests
                    </h1>
                    <p class="mt-1 text-sm font-semibold text-slate">
                        Admin requests only. Balances change after the assigned
                        branch Cashier confirms with PIN.
                    </p>
                </div>
                <div
                    class="rounded-lg bg-mist px-4 py-2 text-sm font-black text-ink"
                >
                    Pending: {{ pendingCount }}
                </div>
            </header>

            <section
                class="rounded-xl border border-line bg-card p-5 shadow-sm"
            >
                <form class="grid gap-4" @submit.prevent="submit">
                    <div class="grid gap-4 md:grid-cols-3">
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
                            <span class="bank-label">Target</span>
                            <select
                                v-model="form.target_type"
                                class="bank-input"
                            >
                                <option value="cash">Cash Vault</option>
                                <option value="account">
                                    Bank / Pay Account
                                </option>
                            </select>
                        </label>

                        <label>
                            <span class="bank-label">Action</span>
                            <select
                                v-model="form.direction"
                                class="bank-input"
                            >
                                <option value="deposit">Deposit</option>
                                <option value="withdraw">Withdraw</option>
                            </select>
                        </label>
                    </div>

                    <label v-if="form.target_type === 'account'">
                        <span class="bank-label">Bank / Pay Account</span>
                        <select
                            v-model.number="form.account_id"
                            class="bank-input"
                            required
                        >
                            <option :value="null" disabled>
                                Select account
                            </option>
                            <option
                                v-for="account in visibleAccounts"
                                :key="account.id"
                                :value="account.id"
                            >
                                {{ account.account_type }} ·
                                {{ account.account_name }} ·
                                {{ account.company?.name ?? 'Provider' }} ·
                                {{ money(account.balance) }}
                            </option>
                        </select>
                    </label>

                    <label v-if="form.target_type === 'account'">
                        <span class="bank-label">Amount</span>
                        <input
                            v-model.number="form.amount"
                            type="number"
                            min="0.01"
                            step="0.01"
                            class="bank-input"
                            required
                        />
                    </label>

                    <div v-else>
                        <DenomDrawer
                            v-model="form.denominations"
                            :notes="notes"
                            label="Cash denomination request"
                            id-prefix="admin-adjustment-request"
                        />
                        <p class="money mt-2 text-sm font-black text-ink">
                            Total: {{ money(cashTotal) }} MMK
                        </p>
                    </div>

                    <label>
                        <span class="bank-label">Request Note</span>
                        <textarea
                            v-model.trim="form.note"
                            rows="3"
                            class="bank-input resize-none"
                            placeholder="Reason / instruction for the Cashier"
                        />
                    </label>

                    <div
                        v-if="Object.keys(form.errors).length"
                        class="rounded-lg border border-brand/20 bg-brand-soft p-3 text-sm font-bold text-brand"
                    >
                        {{
                            Object.values(form.errors)[0]
                        }}
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            type="submit"
                            class="bank-button bank-button-primary"
                            :disabled="
                                form.processing ||
                                !selectedBranch?.is_active ||
                                (form.target_type === 'cash' &&
                                    cashTotal <= 0)
                            "
                        >
                            {{
                                form.processing
                                    ? 'Sending…'
                                    : 'Send Request to Cashier'
                            }}
                        </button>
                        <span class="text-xs font-semibold text-slate">
                            No balance is changed at this step.
                        </span>
                    </div>
                </form>
            </section>

            <section
                class="rounded-xl border border-line bg-card p-5 shadow-sm"
            >
                <h2 class="text-lg font-black text-ink">
                    {{ selectedBranch?.name ?? 'Branch' }} Requests
                </h2>

                <div class="mt-4 overflow-auto rounded-lg border border-line">
                    <table class="w-full min-w-[1050px] text-left text-sm">
                        <thead class="bg-mist text-xs text-slate uppercase">
                            <tr>
                                <th class="px-4 py-3">ID</th>
                                <th class="px-4 py-3">Target</th>
                                <th class="px-4 py-3">Action</th>
                                <th class="px-4 py-3 text-right">Amount</th>
                                <th class="px-4 py-3">Cashier</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Requested</th>
                                <th class="px-4 py-3">Note</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in rows" :key="row.id">
                                <td class="px-4 py-3 font-black">
                                    #{{ row.id }}
                                </td>
                                <td class="px-4 py-3">
                                    {{ targetLabel(row) }}
                                </td>
                                <td class="px-4 py-3 font-bold capitalize">
                                    {{ row.direction }}
                                </td>
                                <td
                                    class="money px-4 py-3 text-right font-bold"
                                >
                                    {{ money(row.amount) }}
                                </td>
                                <td class="px-4 py-3">
                                    {{ row.assigned_cashier_name ?? '-' }}
                                </td>
                                <td class="px-4 py-3 font-black">
                                    {{ row.status }}
                                </td>
                                <td class="px-4 py-3 text-xs text-slate">
                                    {{ dateTime(row.created_at) }}
                                </td>
                                <td class="px-4 py-3 text-slate">
                                    {{ row.note ?? '-' }}
                                </td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td
                                    colspan="8"
                                    class="px-4 py-8 text-center text-slate"
                                >
                                    No adjustment requests for this branch.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </BankLayout>
</template>
