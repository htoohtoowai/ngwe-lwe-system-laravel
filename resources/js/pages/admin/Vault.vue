<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DenomDrawer from '@/components/bank/DenomDrawer.vue';
import BankLayout from '@/layouts/BankLayout.vue';

type Denoms = Record<number, number>;
type MoneyValue = string | number | null | undefined;

type Branch = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
};

type User = {
    id: number;
    full_name: string;
    username: string;
    role: string;
    branch_id: number | null;
    is_active: boolean;
};

type CashFloat = {
    id: number;
    branch_id?: number | null;
    employee_id: number;
    employee_name: string | null;
    issued_by_name: string | null;
    status: string;
    total_amount: MoneyValue;
    current_balance: MoneyValue;
};

type VaultInventory = {
    branch_id: number;
    main_vault: Record<string, number>;
    main_vault_total: MoneyValue;
    total_employee_cash: MoneyValue;
    grand_physical_total: MoneyValue;
};

type AdminData = {
    branches: Branch[];
    selectedBranchId: number;
    selectedBranch?: Branch | null;
    users: User[];
    cashFloats: CashFloat[];
    vaultInventory: VaultInventory | null;
};

const props = defineProps<{
    role: 'admin';
    announcement?: string | null;
    notificationCount?: number;
    adminData?: AdminData;
}>();

const branches = computed(() => props.adminData?.branches ?? []);
const users = computed(() => props.adminData?.users ?? []);
const cashFloats = computed(() => props.adminData?.cashFloats ?? []);
const vaultInventory = computed(
    () => props.adminData?.vaultInventory ?? null,
);

const selectedBranchId = ref(
    props.adminData?.selectedBranchId ?? branches.value[0]?.id ?? 0,
);

watch(
    () => props.adminData?.selectedBranchId,
    (value) => {
        if (value) selectedBranchId.value = value;
    },
);

const selectedBranch = computed(
    () =>
        branches.value.find(
            (branch) => branch.id === selectedBranchId.value,
        ) ?? null,
);

const activeCashier = computed(
    () =>
        users.value.find(
            (user) =>
                user.role === 'cashier' &&
                user.is_active &&
                user.branch_id === selectedBranchId.value,
        ) ?? null,
);

const notes = computed(() => {
    const values = Object.keys(vaultInventory.value?.main_vault ?? {})
        .map(Number)
        .filter((value) => Number.isFinite(value) && value > 0)
        .sort((left, right) => right - left);

    return values.length
        ? values
        : [20000, 10000, 5000, 1000, 500, 200, 100, 50];
});

const vaultStock = computed<Denoms>(() => {
    const stock: Denoms = {};

    for (const note of notes.value) {
        stock[note] = Number(
            vaultInventory.value?.main_vault?.[String(note)] ?? 0,
        );
    }

    return stock;
});

const denominationRows = computed(() =>
    notes.value.map((denomination) => {
        const quantity = vaultStock.value[denomination] ?? 0;

        return {
            denomination,
            quantity,
            total: denomination * quantity,
        };
    }),
);

const modalOpen = ref(false);
const mode = ref<'deposit' | 'withdraw'>('deposit');

const form = useForm({
    branch_id: selectedBranchId.value,
    entry_type: 'vault_in',
    denominations: {} as Denoms,
    note: '',
});

watch(selectedBranchId, (value) => {
    form.branch_id = value;
});

const entryTotal = computed(() =>
    Object.entries(form.denominations).reduce(
        (sum, [denomination, quantity]) =>
            sum + Number(denomination) * Number(quantity ?? 0),
        0,
    ),
);

const money = (value: MoneyValue) =>
    Number(value ?? 0).toLocaleString();

function loadBranch(): void {
    router.get(
        '/admin/vault',
        { branch_id: selectedBranchId.value },
        {
            preserveScroll: true,
            preserveState: false,
            replace: true,
        },
    );
}

function openEntry(nextMode: 'deposit' | 'withdraw'): void {
    form.clearErrors();

    if (!activeCashier.value) {
        form.setError(
            'branch_id',
            'This branch needs an active Cashier before vault management.',
        );

        return;
    }

    mode.value = nextMode;
    form.entry_type = nextMode === 'deposit' ? 'vault_in' : 'vault_out';
    form.denominations = {};
    form.note = '';
    modalOpen.value = true;
}

function closeEntry(): void {
    if (!form.processing) {
        modalOpen.value = false;
        form.clearErrors();
    }
}

function saveEntry(): void {
    form.branch_id = selectedBranchId.value;
    form.entry_type = mode.value === 'deposit' ? 'vault_in' : 'vault_out';

    form.post('/admin/branch-vault/entries', {
        preserveScroll: true,
        onSuccess: () => {
            modalOpen.value = false;
            form.denominations = {};
            form.note = '';
        },
    });
}

const vaultLogHref = computed(
    () => `/admin/vault/log?branch_id=${selectedBranchId.value}`,
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
                        Branch Vault
                    </p>
                    <h1 class="text-2xl font-black text-ink">
                        Cashier Main Vault
                    </h1>
                    <p class="mt-1 text-sm font-semibold text-slate">
                        {{ selectedBranch?.name ?? 'Selected branch' }}
                        <span v-if="selectedBranch">
                            ({{ selectedBranch.code }})
                        </span>
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Link
                        :href="`/admin/adjustments?branch_id=${selectedBranchId}`"
                        class="bank-button bank-button-primary"
                    >
                        Adjustment Requests
                    </Link>
                    <Link
                        :href="vaultLogHref"
                        class="bank-button bank-button-secondary"
                    >
                        Vault Log
                    </Link>
                </div>
            </header>

            <section
                class="rounded-xl border border-line bg-card p-5 shadow-sm"
            >
                <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
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
                                {{ branch.is_active ? '' : ' - Inactive' }}
                            </option>
                        </select>
                    </label>

                    <div class="flex items-end gap-2">
                        <button
                            type="button"
                            class="bank-button bank-button-primary"
                            :disabled="
                                !selectedBranch?.is_active ||
                                !activeCashier
                            "
                            @click="openEntry('deposit')"
                        >
                            Deposit
                        </button>
                        <button
                            type="button"
                            class="bank-button bank-button-secondary"
                            :disabled="
                                !selectedBranch?.is_active ||
                                !activeCashier ||
                                Number(
                                    vaultInventory?.main_vault_total ?? 0,
                                ) <= 0
                            "
                            @click="openEntry('withdraw')"
                        >
                            Withdraw
                        </button>
                    </div>
                </div>

                <p class="mt-3 text-xs font-semibold text-slate">
                    Admin submits a request only. The vault changes after this
                    branch's Cashier confirms with PIN.
                </p>

                <p
                    class="mt-2 text-sm font-bold"
                    :class="activeCashier ? 'text-balance' : 'text-brand'"
                >
                    {{
                        activeCashier
                            ? `Active Cashier: ${activeCashier.full_name}`
                            : 'No active Cashier assigned to this branch.'
                    }}
                </p>

                <p
                    v-if="form.errors.branch_id"
                    class="mt-2 text-sm font-bold text-brand"
                >
                    {{ form.errors.branch_id }}
                </p>
            </section>

            <section class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border border-line bg-card p-4 shadow-sm">
                    <p class="text-xs font-bold text-slate">Main Vault</p>
                    <p class="money mt-1 text-2xl font-black text-ink">
                        {{ money(vaultInventory?.main_vault_total) }}
                    </p>
                </div>
                <div class="rounded-xl border border-line bg-card p-4 shadow-sm">
                    <p class="text-xs font-bold text-slate">Employee Cash</p>
                    <p class="money mt-1 text-2xl font-black text-ink">
                        {{ money(vaultInventory?.total_employee_cash) }}
                    </p>
                </div>
                <div class="rounded-xl border border-line bg-card p-4 shadow-sm">
                    <p class="text-xs font-bold text-slate">
                        Total Physical Cash
                    </p>
                    <p class="money mt-1 text-2xl font-black text-ink">
                        {{ money(vaultInventory?.grand_physical_total) }}
                    </p>
                </div>
            </section>

            <section class="grid gap-5 lg:grid-cols-[0.8fr_1.2fr]">
                <div class="rounded-xl border border-line bg-card p-5 shadow-sm">
                    <h2 class="text-lg font-black text-ink">
                        Denomination Stock
                    </h2>
                    <div
                        class="mt-4 overflow-hidden rounded-lg border border-line"
                    >
                        <table class="w-full text-left text-sm">
                            <thead class="bg-mist text-xs text-slate uppercase">
                                <tr>
                                    <th class="px-4 py-3">Note</th>
                                    <th class="px-4 py-3 text-right">Qty</th>
                                    <th class="px-4 py-3 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr
                                    v-for="row in denominationRows"
                                    :key="row.denomination"
                                >
                                    <td class="money px-4 py-3 font-bold">
                                        {{ money(row.denomination) }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        {{ row.quantity }}
                                    </td>
                                    <td
                                        class="money px-4 py-3 text-right font-bold"
                                    >
                                        {{ money(row.total) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-xl border border-line bg-card p-5 shadow-sm">
                    <h2 class="text-lg font-black text-ink">Cash Floats</h2>
                    <div
                        class="mt-4 overflow-auto rounded-lg border border-line"
                    >
                        <table class="w-full min-w-[680px] text-left text-sm">
                            <thead class="bg-mist text-xs text-slate uppercase">
                                <tr>
                                    <th class="px-4 py-3">Float</th>
                                    <th class="px-4 py-3">Employee</th>
                                    <th class="px-4 py-3">Issued By</th>
                                    <th class="px-4 py-3 text-right">
                                        Current
                                    </th>
                                    <th class="px-4 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr
                                    v-for="cashFloat in cashFloats"
                                    :key="cashFloat.id"
                                >
                                    <td class="px-4 py-3 font-black">
                                        #{{ cashFloat.id }}
                                    </td>
                                    <td class="px-4 py-3">
                                        {{
                                            cashFloat.employee_name ??
                                            `Employee #${cashFloat.employee_id}`
                                        }}
                                    </td>
                                    <td class="px-4 py-3">
                                        {{ cashFloat.issued_by_name ?? '-' }}
                                    </td>
                                    <td
                                        class="money px-4 py-3 text-right font-bold"
                                    >
                                        {{ money(cashFloat.current_balance) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        {{ cashFloat.status }}
                                    </td>
                                </tr>
                                <tr v-if="!cashFloats.length">
                                    <td
                                        colspan="5"
                                        class="px-4 py-8 text-center text-slate"
                                    >
                                        No cash floats for this branch.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>

        <Teleport to="body">
            <div
                v-if="modalOpen"
                class="fixed inset-0 z-[90] grid place-items-center bg-ink/60 p-4 backdrop-blur-sm"
                @click.self="closeEntry"
            >
                <section
                    class="max-h-[calc(100vh-2rem)] w-full max-w-xl overflow-y-auto rounded-2xl border border-line bg-card shadow-2xl"
                >
                    <header
                        class="flex items-start justify-between gap-3 border-b border-line px-5 py-4"
                    >
                        <div>
                            <h2 class="text-lg font-black text-ink">
                                {{
                                    mode === 'deposit'
                                        ? 'Request Branch Vault Deposit'
                                        : 'Request Branch Vault Withdrawal'
                                }}
                            </h2>
                            <p class="mt-1 text-xs font-semibold text-slate">
                                {{ selectedBranch?.name }} ·
                                {{ activeCashier?.full_name }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="text-xl text-slate"
                            :disabled="form.processing"
                            @click="closeEntry"
                        >
                            ×
                        </button>
                    </header>

                    <div class="space-y-4 p-5">
                        <DenomDrawer
                            v-model="form.denominations"
                            :notes="notes"
                            :stock="mode === 'withdraw' ? vaultStock : null"
                            :enforce-stock="mode === 'withdraw'"
                            :label="
                                mode === 'deposit'
                                    ? 'Cash to deposit'
                                    : 'Cash to withdraw'
                            "
                            id-prefix="admin-branch-vault-entry"
                        />

                        <label>
                            <span class="bank-label">Remark *</span>
                            <textarea
                                v-model.trim="form.note"
                                rows="3"
                                class="bank-input resize-none"
                                required
                            />
                        </label>

                        <p
                            v-if="form.errors.denominations"
                            class="text-sm font-bold text-brand"
                        >
                            {{ form.errors.denominations }}
                        </p>
                    </div>

                    <footer
                        class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-4"
                    >
                        <p class="money text-sm font-black text-ink">
                            Total: {{ money(entryTotal) }} MMK
                        </p>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="bank-button bank-button-secondary"
                                :disabled="form.processing"
                                @click="closeEntry"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                class="bank-button bank-button-primary"
                                :disabled="
                                    form.processing || entryTotal <= 0 || form.note.trim() === ''
                                "
                                @click="saveEntry"
                            >
                                {{
                                    form.processing
                                        ? 'Sending…'
                                        : 'Send Request'
                                }}
                            </button>
                        </div>
                    </footer>
                </section>
            </div>
        </Teleport>
    </BankLayout>
</template>
