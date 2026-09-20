<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DenomDrawer from '@/components/bank/DenomDrawer.vue';
import AppMenuIcon from '@/components/ui/AppMenuIcon.vue';
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
    company?: { id?: number; name?: string | null } | null;
};

type AdjustmentRow = {
    id: number;
    branch_id: number;
    branch_name: string | null;
    target_type: 'cash' | 'account';
    account_id: number | null;
    account_name: string | null;
    account_identifier?: string | null;
    provider_name?: string | null;
    account_balance?: string | number | null;
    account_type: string | null;
    direction: 'deposit' | 'withdraw';
    amount: string | number;
    denominations?: Record<number, number>;
    note: string | null;
    status: 'PENDING' | 'APPROVED' | 'CONFIRMED' | 'REJECTED';
    assigned_cashier_name: string | null;
    requested_by_name: string | null;
    requester_role: string | null;
    confirmed_by_name?: string | null;
    rejected_by_name?: string | null;
    created_at: string | null;
};

type AssetType = 'cash' | 'pay' | 'bank';
type WorkflowTab = 'new' | 'waiting' | 'history';

const props = defineProps<{
    role: 'admin';
    branches: Branch[];
    selectedBranchId: number;
    selectedAccountId?: number | null;
    selectedDirection: 'deposit' | 'withdraw';
    accounts: Account[];
    rows: AdjustmentRow[];
}>();

const selectedBranchId = ref(props.selectedBranchId);
const preselectedAccount = props.accounts.find(
    (account) => account.id === props.selectedAccountId,
);
const assetType = ref<AssetType>(
    preselectedAccount?.account_type === 'BANK'
        ? 'bank'
        : preselectedAccount?.account_type === 'PAY'
          ? 'pay'
          : 'cash',
);
const providerId = ref<number | null>(preselectedAccount?.company?.id ?? null);
const cashRequestedTotal = ref(0);
const reviewing = ref(false);
const activeTab = ref<WorkflowTab>('new');
const notes = [20000, 10000, 5000, 1000, 500, 200, 100, 50];

const form = useForm({
    branch_id: props.selectedBranchId,
    target_type: (assetType.value === 'cash' ? 'cash' : 'account') as
        | 'cash'
        | 'account',
    account_id: props.selectedAccountId ?? null,
    direction: props.selectedDirection,
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

const typedAccounts = computed(() => {
    const type = assetType.value === 'bank' ? 'BANK' : 'PAY';

    return visibleAccounts.value.filter(
        (account) => account.account_type === type,
    );
});

const providers = computed(() => {
    const seen = new Map<number, string>();

    for (const account of typedAccounts.value) {
        const id = account.company?.id;
        const name = account.company?.name;

        if (id && name) {
            seen.set(id, name);
        }
    }

    return [...seen.entries()].map(([id, name]) => ({ id, name }));
});

const providerAccounts = computed(() =>
    typedAccounts.value.filter(
        (account) => account.company?.id === providerId.value,
    ),
);

const selectedAccount = computed(
    () =>
        props.accounts.find((account) => account.id === form.account_id) ??
        null,
);

const cashAllocated = computed(() =>
    Object.entries(form.denominations).reduce(
        (sum, [denomination, quantity]) =>
            sum + Number(denomination) * Number(quantity ?? 0),
        0,
    ),
);

const cashRemaining = computed(
    () => cashRequestedTotal.value - cashAllocated.value,
);

const accountAfter = computed(() => {
    const current = Number(selectedAccount.value?.balance ?? 0);
    const amount = Number(form.amount ?? 0);

    return form.direction === 'deposit'
        ? current + amount
        : current - amount;
});

const directionRows = computed(() =>
    props.rows.filter(
        (row) =>
            row.direction === props.selectedDirection &&
            row.requester_role === 'admin',
    ),
);

const waiting = computed(() =>
    directionRows.value.filter((row) => row.status === 'PENDING'),
);

const history = computed(() =>
    directionRows.value.filter(
        (row) =>
            row.status === 'CONFIRMED' ||
            row.status === 'REJECTED',
    ),
);

const formDenominationEntries = computed(() =>
    Object.entries(form.denominations)
        .map(
            ([denomination, quantity]) =>
                [denomination, Number(quantity)] as [string, number],
        )
        .filter(([, quantity]) => quantity > 0)
        .sort((a, b) => Number(b[0]) - Number(a[0])),
);

const canReview = computed(() => {
    if (!selectedBranch.value?.is_active || form.note.trim() === '') {
        return false;
    }

    if (assetType.value === 'cash') {
        return (
            cashRequestedTotal.value > 0 &&
            cashRemaining.value === 0
        );
    }

    return (
        form.account_id !== null &&
        Number(form.amount) > 0 &&
        accountAfter.value >= 0
    );
});

watch(assetType, () => {
    reviewing.value = false;
    providerId.value = null;
    form.account_id = null;
    form.amount = 0;
    form.denominations = {};
    cashRequestedTotal.value = 0;
    form.target_type =
        assetType.value === 'cash' ? 'cash' : 'account';
});

watch(providerId, () => {
    if (
        !providerAccounts.value.some(
            (account) => account.id === form.account_id,
        )
    ) {
        form.account_id = null;
    }
});

function money(
    value: string | number | null | undefined,
): string {
    return Number(value ?? 0).toLocaleString();
}

function dateTime(value?: string | null): string {
    return value ? new Date(value).toLocaleString() : '-';
}

function directionTitle(): string {
    return props.selectedDirection === 'deposit'
        ? 'Deposit'
        : 'Withdraw';
}

function assetLabel(): string {
    if (assetType.value === 'cash') {
        return 'Cash';
    }

    return assetType.value === 'pay' ? 'Pay' : 'Bank';
}

function targetLabel(row: AdjustmentRow): string {
    if (row.target_type === 'cash') {
        return 'Cash';
    }

    return [
        row.account_type ?? 'Account',
        row.provider_name,
        row.account_name,
    ]
        .filter(Boolean)
        .join(' · ');
}

function directionHref(
    direction: 'deposit' | 'withdraw',
): string {
    const params = new URLSearchParams({
        branch_id: String(selectedBranchId.value),
        direction,
    });

    if (form.account_id !== null) {
        params.set('account_id', String(form.account_id));
    }

    return `/admin/adjustments?${params.toString()}`;
}

function loadBranch(): void {
    router.get(
        '/admin/adjustments',
        {
            branch_id: selectedBranchId.value,
            direction: props.selectedDirection,
        },
        {
            preserveScroll: true,
            preserveState: false,
            replace: true,
        },
    );
}

function submit(): void {
    if (!canReview.value) {
        return;
    }

    form.branch_id = selectedBranchId.value;
    form.direction = props.selectedDirection;
    form.target_type =
        assetType.value === 'cash' ? 'cash' : 'account';
    form.amount =
        assetType.value === 'cash'
            ? cashRequestedTotal.value
            : form.amount;

    form.post('/admin/adjustments', {
        preserveScroll: true,
        onSuccess: () => {
            reviewing.value = false;
            form.account_id = null;
            form.amount = 0;
            form.denominations = {};
            form.note = '';
            cashRequestedTotal.value = 0;
            providerId.value = null;
            activeTab.value = 'waiting';
        },
    });
}
</script>

<template>
    <BankLayout :role="role">
        <div class="mx-auto grid w-full max-w-5xl gap-5 pb-8">
            <header class="border-b border-line pb-4">
                <p
                    class="text-xs font-black tracking-[0.16em] text-slate uppercase"
                >
                    Admin → Cashier
                </p>
                <h1 class="mt-1 text-2xl font-black text-ink">
                    {{ directionTitle() }}
                </h1>
                <p class="mt-1 text-sm font-semibold text-slate">
                    Admin creates the request. The selected branch Cashier
                    reviews and confirms it with PIN before any balance changes.
                </p>

                <div class="mt-4 flex flex-wrap gap-2">
                    <Link
                        :href="directionHref('deposit')"
                        class="bank-button"
                        :class="
                            selectedDirection === 'deposit'
                                ? 'bank-button-primary'
                                : 'bank-button-secondary'
                        "
                    >
                        Deposit
                    </Link>
                    <Link
                        :href="directionHref('withdraw')"
                        class="bank-button"
                        :class="
                            selectedDirection === 'withdraw'
                                ? 'bank-button-primary'
                                : 'bank-button-secondary'
                        "
                    >
                        Withdraw
                    </Link>
                </div>
            </header>

            <nav
                class="grid grid-cols-3 overflow-hidden rounded-2xl border border-line bg-card p-1 shadow-sm"
                aria-label="Adjustment workflow"
            >
                <button
                    type="button"
                    class="min-h-12 rounded-xl px-2 py-2 text-xs font-black transition sm:text-sm"
                    :class="
                        activeTab === 'new'
                            ? 'bg-brand text-white shadow-sm'
                            : 'text-slate hover:bg-mist hover:text-ink'
                    "
                    @click="activeTab = 'new'"
                >
                    New
                </button>
                <button
                    type="button"
                    class="min-h-12 rounded-xl px-2 py-2 text-xs font-black transition sm:text-sm"
                    :class="
                        activeTab === 'waiting'
                            ? 'bg-brand text-white shadow-sm'
                            : 'text-slate hover:bg-mist hover:text-ink'
                    "
                    @click="activeTab = 'waiting'"
                >
                    Waiting
                    <span
                        v-if="waiting.length"
                        class="ml-1 inline-flex min-w-5 items-center justify-center rounded-full bg-white/20 px-1.5 py-0.5 text-[10px]"
                    >
                        {{ waiting.length }}
                    </span>
                </button>
                <button
                    type="button"
                    class="min-h-12 rounded-xl px-2 py-2 text-xs font-black transition sm:text-sm"
                    :class="
                        activeTab === 'history'
                            ? 'bg-brand text-white shadow-sm'
                            : 'text-slate hover:bg-mist hover:text-ink'
                    "
                    @click="activeTab = 'history'"
                >
                    History
                    <span
                        v-if="history.length"
                        class="ml-1 inline-flex min-w-5 items-center justify-center rounded-full bg-white/20 px-1.5 py-0.5 text-[10px]"
                    >
                        {{ history.length }}
                    </span>
                </button>
            </nav>

            <template v-if="activeTab === 'new'">
                <section
                    v-if="!reviewing"
                    class="grid gap-5 rounded-2xl border border-line bg-card p-5 shadow-sm"
                >
                    <div>
                        <p
                            class="text-xs font-black tracking-[0.12em] text-slate uppercase"
                        >
                            New Request
                        </p>
                        <h2 class="mt-1 text-lg font-black text-ink">
                            {{ directionTitle() }} branch asset
                        </h2>
                    </div>

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

                    <div>
                        <span class="bank-label">Asset</span>
                        <div class="mt-2 grid grid-cols-3 gap-2 sm:gap-3">
                            <button
                                type="button"
                                class="rounded-2xl border p-2 text-center transition sm:p-4"
                                :class="
                                    assetType === 'cash'
                                        ? 'border-brand bg-brand-soft ring-2 ring-brand/20'
                                        : 'border-line bg-card hover:bg-mist'
                                "
                                @click="assetType = 'cash'"
                            >
                                <AppMenuIcon name="vault" size="launcher" />
                                <span class="mt-2 block text-sm font-black">
                                    Cash
                                </span>
                            </button>
                            <button
                                type="button"
                                class="rounded-2xl border p-2 text-center transition sm:p-4"
                                :class="
                                    assetType === 'pay'
                                        ? 'border-brand bg-brand-soft ring-2 ring-brand/20'
                                        : 'border-line bg-card hover:bg-mist'
                                "
                                @click="assetType = 'pay'"
                            >
                                <AppMenuIcon name="accounts" size="launcher" />
                                <span class="mt-2 block text-sm font-black">
                                    Pay
                                </span>
                            </button>
                            <button
                                type="button"
                                class="rounded-2xl border p-2 text-center transition sm:p-4"
                                :class="
                                    assetType === 'bank'
                                        ? 'border-brand bg-brand-soft ring-2 ring-brand/20'
                                        : 'border-line bg-card hover:bg-mist'
                                "
                                @click="assetType = 'bank'"
                            >
                                <AppMenuIcon name="companies" size="launcher" />
                                <span class="mt-2 block text-sm font-black">
                                    Bank
                                </span>
                            </button>
                        </div>
                    </div>

                    <template v-if="assetType === 'cash'">
                        <label>
                            <span class="bank-label">Total Amount</span>
                            <input
                                v-model.number="cashRequestedTotal"
                                type="number"
                                min="1"
                                step="1"
                                class="bank-input"
                                required
                            />
                        </label>

                        <DenomDrawer
                            v-model="form.denominations"
                            :notes="notes"
                            label="Denomination Breakdown"
                            id-prefix="admin-adjustment-cash"
                        />

                        <div
                            class="grid grid-cols-3 gap-3 rounded-xl bg-mist p-4 text-sm"
                        >
                            <div>
                                <p class="text-xs font-bold text-slate">Total</p>
                                <p class="money mt-1 font-black">
                                    {{ money(cashRequestedTotal) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate">
                                    Allocated
                                </p>
                                <p class="money mt-1 font-black">
                                    {{ money(cashAllocated) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate">
                                    Remaining
                                </p>
                                <p
                                    class="money mt-1 font-black"
                                    :class="
                                        cashRemaining === 0
                                            ? 'text-balance'
                                            : 'text-brand'
                                    "
                                >
                                    {{ money(cashRemaining) }}
                                </p>
                            </div>
                        </div>
                    </template>

                    <template v-else>
                        <label>
                            <span class="bank-label">Provider</span>
                            <select
                                v-model.number="providerId"
                                class="bank-input"
                                required
                            >
                                <option :value="null" disabled>
                                    Select
                                    {{ assetType === 'pay' ? 'Pay' : 'Bank' }}
                                    provider
                                </option>
                                <option
                                    v-for="provider in providers"
                                    :key="provider.id"
                                    :value="provider.id"
                                >
                                    {{ provider.name }}
                                </option>
                            </select>
                        </label>

                        <label>
                            <span class="bank-label">Account</span>
                            <select
                                v-model.number="form.account_id"
                                class="bank-input"
                                :disabled="providerId === null"
                                required
                            >
                                <option :value="null" disabled>
                                    Select account
                                </option>
                                <option
                                    v-for="account in providerAccounts"
                                    :key="account.id"
                                    :value="account.id"
                                >
                                    {{ account.account_name }} ·
                                    {{ account.account_identifier }} ·
                                    {{ money(account.balance) }} MMK
                                </option>
                            </select>
                        </label>

                        <div
                            v-if="selectedAccount"
                            class="grid grid-cols-2 gap-3 rounded-xl bg-mist p-4 text-sm"
                        >
                            <div>
                                <p class="text-xs font-bold text-slate">
                                    Current Balance
                                </p>
                                <p class="money mt-1 font-black">
                                    {{ money(selectedAccount.balance) }} MMK
                                </p>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate">
                                    After Confirmation
                                </p>
                                <p
                                    class="money mt-1 font-black"
                                    :class="
                                        accountAfter < 0
                                            ? 'text-brand'
                                            : 'text-ink'
                                    "
                                >
                                    {{ money(accountAfter) }} MMK
                                </p>
                            </div>
                        </div>

                        <label>
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
                    </template>

                    <label>
                        <span class="bank-label">Remark *</span>
                        <textarea
                            v-model.trim="form.note"
                            rows="3"
                            class="bank-input resize-none"
                            placeholder="Reason / instruction for the Cashier"
                            required
                        />
                    </label>

                    <div
                        v-if="Object.keys(form.errors).length"
                        class="rounded-lg border border-brand/20 bg-brand-soft p-3 text-sm font-bold text-brand"
                    >
                        {{ Object.values(form.errors)[0] }}
                    </div>

                    <button
                        type="button"
                        class="bank-button bank-button-primary"
                        :disabled="!canReview"
                        @click="reviewing = true"
                    >
                        Review {{ directionTitle() }}
                    </button>
                </section>

                <section
                    v-else
                    class="grid gap-4 rounded-2xl border border-line bg-card p-5 shadow-sm"
                >
                    <div>
                        <p
                            class="text-xs font-black tracking-[0.14em] text-slate uppercase"
                        >
                            Review
                        </p>
                        <h2 class="mt-1 text-xl font-black text-ink">
                            {{ directionTitle() }} · {{ assetLabel() }}
                        </h2>
                    </div>

                    <dl
                        class="grid gap-3 rounded-xl bg-mist p-4 text-sm sm:grid-cols-2"
                    >
                        <div>
                            <dt class="font-bold text-slate">Branch</dt>
                            <dd class="mt-1 font-black text-ink">
                                {{ selectedBranch?.name }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate">Amount</dt>
                            <dd class="money mt-1 font-black text-ink">
                                {{
                                    money(
                                        assetType === 'cash'
                                            ? cashRequestedTotal
                                            : form.amount,
                                    )
                                }}
                                MMK
                            </dd>
                        </div>
                        <div v-if="selectedAccount">
                            <dt class="font-bold text-slate">
                                Provider / Account
                            </dt>
                            <dd class="mt-1 font-black text-ink">
                                {{ selectedAccount.company?.name }} ·
                                {{ selectedAccount.account_name }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate">Remark</dt>
                            <dd class="mt-1 font-black text-ink">
                                {{ form.note }}
                            </dd>
                        </div>
                    </dl>

                    <div
                        v-if="
                            assetType === 'cash' &&
                            formDenominationEntries.length
                        "
                        class="rounded-xl border border-line p-4"
                    >
                        <p class="text-xs font-bold text-slate">
                            Denomination Breakdown
                        </p>
                        <div
                            class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3"
                        >
                            <div
                                v-for="[denomination, quantity] in formDenominationEntries"
                                :key="denomination"
                                class="flex items-center justify-between gap-2 rounded-lg bg-mist px-3 py-2 text-sm"
                            >
                                <span class="money font-bold">
                                    {{ money(denomination) }}
                                </span>
                                <span class="font-black">
                                    × {{ quantity }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <p class="text-xs font-semibold leading-5 text-slate">
                        No balance changes now. The branch Cashier must confirm
                        this request with PIN.
                    </p>

                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            class="bank-button bank-button-secondary"
                            :disabled="form.processing"
                            @click="reviewing = false"
                        >
                            Back
                        </button>
                        <button
                            type="button"
                            class="bank-button bank-button-primary"
                            :disabled="form.processing"
                            @click="submit"
                        >
                            {{
                                form.processing
                                    ? 'Sending…'
                                    : 'Send to Cashier'
                            }}
                        </button>
                    </div>
                </section>
            </template>

            <section
                v-else-if="activeTab === 'waiting'"
                class="rounded-2xl border border-line bg-card p-5 shadow-sm"
            >
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p
                            class="text-xs font-black tracking-[0.12em] text-slate uppercase"
                        >
                            Sent by Admin
                        </p>
                        <h2 class="mt-1 text-lg font-black text-ink">
                            Waiting for Cashier
                        </h2>
                    </div>
                    <span
                        class="rounded-full bg-mist px-3 py-1 text-xs font-black"
                    >
                        {{ waiting.length }}
                    </span>
                </div>

                <div class="mt-4 grid gap-3">
                    <article
                        v-for="row in waiting"
                        :key="row.id"
                        class="rounded-2xl border border-line p-4"
                    >
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div>
                                <p class="font-black text-ink">
                                    #{{ row.id }} · {{ targetLabel(row) }}
                                </p>
                                <p class="mt-1 text-xs font-black text-brand">
                                    Waiting for Cashier PIN confirmation
                                </p>
                                <p class="mt-2 text-sm font-semibold text-slate">
                                    {{ row.note }}
                                </p>
                                <p class="mt-2 text-xs font-semibold text-slate">
                                    {{ row.assigned_cashier_name ?? 'Cashier' }}
                                    · {{ dateTime(row.created_at) }}
                                </p>
                            </div>
                            <p
                                class="money shrink-0 text-xl font-black text-ink"
                            >
                                {{ money(row.amount) }} MMK
                            </p>
                        </div>
                    </article>

                    <div
                        v-if="!waiting.length"
                        class="rounded-xl border border-dashed border-line px-5 py-10 text-center text-sm font-semibold text-slate"
                    >
                        No requests are waiting for Cashier confirmation.
                    </div>
                </div>
            </section>

            <section
                v-else
                class="rounded-2xl border border-line bg-card p-5 shadow-sm"
            >
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p
                            class="text-xs font-black tracking-[0.12em] text-slate uppercase"
                        >
                            Completed
                        </p>
                        <h2 class="mt-1 text-lg font-black text-ink">
                            History
                        </h2>
                    </div>
                    <span
                        class="rounded-full bg-mist px-3 py-1 text-xs font-black"
                    >
                        {{ history.length }}
                    </span>
                </div>

                <div class="mt-4 grid gap-3">
                    <article
                        v-for="row in history"
                        :key="row.id"
                        class="rounded-2xl border border-line p-4"
                    >
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-black text-ink">
                                        #{{ row.id }} · {{ targetLabel(row) }}
                                    </p>
                                    <span
                                        class="rounded-full px-2.5 py-1 text-[10px] font-black uppercase"
                                        :class="
                                            row.status === 'CONFIRMED'
                                                ? 'bg-balance/15 text-balance'
                                                : 'bg-brand-soft text-brand'
                                        "
                                    >
                                        {{ row.status }}
                                    </span>
                                </div>
                                <p class="mt-2 text-sm font-semibold text-slate">
                                    {{ row.note }}
                                </p>
                                <p class="mt-2 text-xs font-semibold text-slate">
                                    {{ dateTime(row.created_at) }}
                                </p>
                            </div>
                            <p
                                class="money shrink-0 text-xl font-black text-ink"
                            >
                                {{ money(row.amount) }} MMK
                            </p>
                        </div>
                    </article>

                    <div
                        v-if="!history.length"
                        class="rounded-xl border border-dashed border-line px-5 py-10 text-center text-sm font-semibold text-slate"
                    >
                        No {{ directionTitle().toLowerCase() }} history yet.
                    </div>
                </div>
            </section>
        </div>
    </BankLayout>
</template>
