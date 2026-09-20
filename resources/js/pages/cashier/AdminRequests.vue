<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DenomDrawer from '@/components/bank/DenomDrawer.vue';
import AppMenuIcon from '@/components/ui/AppMenuIcon.vue';
import BankLayout from '@/layouts/BankLayout.vue';

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
    provider_name?: string | null;
    account_name: string | null;
    account_identifier?: string | null;
    account_balance?: string | number | null;
    account_type: string | null;
    direction: 'deposit' | 'withdraw';
    amount: string | number;
    denominations: Record<number, number>;
    note: string | null;
    status: 'PENDING' | 'APPROVED' | 'CONFIRMED' | 'REJECTED';
    requested_by_name: string | null;
    requester_role: string | null;
    approver_name: string | null;
    approver_role: string | null;
    confirmed_by_name: string | null;
    rejected_by_name: string | null;
    created_at: string | null;
};

type AssetType = 'cash' | 'pay' | 'bank';
type WorkflowTab = 'new' | 'review' | 'waiting' | 'history';

const props = defineProps<{
    role: 'cashier';
    notificationCount?: number;
    selectedDirection: 'deposit' | 'withdraw';
    branch: { id: number; code?: string | null; name: string };
    accounts: Account[];
    rows: AdjustmentRow[];
}>();

const assetType = ref<AssetType>('cash');
const providerId = ref<number | null>(null);
const cashRequestedTotal = ref(0);
const reviewing = ref(false);
const activeTab = ref<WorkflowTab>('new');
const selectedDecision = ref<AdjustmentRow | null>(null);
const decisionAction = ref<'confirm' | 'reject'>('confirm');
const decisionForm = useForm({ pin: '', note: '' });
const notes = [20000, 10000, 5000, 1000, 500, 200, 100, 50];

const requestForm = useForm({
    target_type: 'cash' as 'cash' | 'account',
    account_id: null as number | null,
    direction: props.selectedDirection,
    amount: 0,
    denominations: {} as Record<number, number>,
    note: '',
});

const typedAccounts = computed(() => {
    const type = assetType.value === 'bank' ? 'BANK' : 'PAY';

    return props.accounts.filter(
        (account) =>
            account.is_active &&
            account.account_type === type,
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
        props.accounts.find(
            (account) => account.id === requestForm.account_id,
        ) ?? null,
);

const cashAllocated = computed(() =>
    Object.entries(requestForm.denominations).reduce(
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
    const amount = Number(requestForm.amount ?? 0);

    return props.selectedDirection === 'deposit'
        ? current + amount
        : current - amount;
});

const directionRows = computed(() =>
    props.rows.filter(
        (row) => row.direction === props.selectedDirection,
    ),
);

const toReview = computed(() =>
    directionRows.value.filter(
        (row) =>
            row.status === 'PENDING' &&
            row.approver_role === 'cashier',
    ),
);

const waiting = computed(() =>
    directionRows.value.filter(
        (row) =>
            row.status === 'PENDING' &&
            row.requester_role === 'cashier',
    ),
);

const readyHandover = computed(() =>
    directionRows.value.filter(
        (row) =>
            row.status === 'APPROVED' &&
            row.requester_role === 'cashier' &&
            row.target_type === 'cash',
    ),
);

const reviewCount = computed(
    () => toReview.value.length + readyHandover.value.length,
);

const history = computed(() =>
    directionRows.value.filter(
        (row) =>
            row.status === 'CONFIRMED' ||
            row.status === 'REJECTED',
    ),
);

const formDenominationEntries = computed(() =>
    Object.entries(requestForm.denominations)
        .map(
            ([denomination, quantity]) =>
                [denomination, Number(quantity)] as [string, number],
        )
        .filter(([, quantity]) => quantity > 0)
        .sort((a, b) => Number(b[0]) - Number(a[0])),
);

const canReviewNew = computed(() => {
    if (requestForm.note.trim() === '') {
        return false;
    }

    if (assetType.value === 'cash') {
        return (
            cashRequestedTotal.value > 0 &&
            cashRemaining.value === 0
        );
    }

    return (
        requestForm.account_id !== null &&
        Number(requestForm.amount) > 0 &&
        accountAfter.value >= 0
    );
});

const title = computed(() =>
    props.selectedDirection === 'deposit'
        ? 'Deposit'
        : 'Withdraw',
);

watch(assetType, () => {
    providerId.value = null;
    requestForm.account_id = null;
    requestForm.amount = 0;
    requestForm.denominations = {};
    cashRequestedTotal.value = 0;
    requestForm.target_type =
        assetType.value === 'cash' ? 'cash' : 'account';
    reviewing.value = false;
});

watch(providerId, () => {
    if (
        !providerAccounts.value.some(
            (account) => account.id === requestForm.account_id,
        )
    ) {
        requestForm.account_id = null;
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

function rowAfterBalance(row: AdjustmentRow): number {
    const current = Number(row.account_balance ?? 0);
    const amount = Number(row.amount ?? 0);

    return row.direction === 'deposit'
        ? current + amount
        : current - amount;
}

function denominationEntries(
    row: AdjustmentRow,
): Array<[string, number]> {
    return Object.entries(row.denominations ?? {})
        .map(
            ([denomination, quantity]) =>
                [denomination, Number(quantity)] as [string, number],
        )
        .filter(([, quantity]) => quantity > 0)
        .sort((a, b) => Number(b[0]) - Number(a[0]));
}

function submitNew(): void {
    if (!canReviewNew.value) {
        return;
    }

    requestForm.direction = props.selectedDirection;
    requestForm.target_type =
        assetType.value === 'cash' ? 'cash' : 'account';
    requestForm.amount =
        assetType.value === 'cash'
            ? cashRequestedTotal.value
            : requestForm.amount;

    requestForm.post('/cashier/adjustments', {
        preserveScroll: true,
        onSuccess: () => {
            reviewing.value = false;
            requestForm.account_id = null;
            requestForm.amount = 0;
            requestForm.denominations = {};
            requestForm.note = '';
            cashRequestedTotal.value = 0;
            providerId.value = null;
            activeTab.value = 'waiting';
        },
    });
}

function openDecision(
    row: AdjustmentRow,
    nextAction: 'confirm' | 'reject',
): void {
    selectedDecision.value = row;
    decisionAction.value = nextAction;
    decisionForm.reset();
    decisionForm.clearErrors();
}

function closeDecision(): void {
    if (!decisionForm.processing) {
        selectedDecision.value = null;
        decisionForm.reset();
        decisionForm.clearErrors();
    }
}

function submitDecision(): void {
    if (!selectedDecision.value) {
        return;
    }

    decisionForm.post(
        `/cashier/admin-requests/${selectedDecision.value.id}/${decisionAction.value}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                closeDecision();
                activeTab.value = 'history';
            },
        },
    );
}
</script>

<template>
    <BankLayout
        :role="role"
        :notification-count="notificationCount"
    >
        <div class="mx-auto grid w-full max-w-5xl gap-5 pb-8">
            <header class="border-b border-line pb-4">
                <p
                    class="text-xs font-black tracking-[0.16em] text-slate uppercase"
                >
                    Cashier ↔ Admin
                </p>
                <h1 class="mt-1 text-2xl font-black text-ink">
                    {{ title }}
                </h1>
                <p class="mt-1 text-sm font-semibold text-slate">
                    {{ branch.name }}
                    <span v-if="branch.code"> · {{ branch.code }}</span>
                </p>

                <div class="mt-4 flex flex-wrap gap-2">
                    <Link
                        href="/cashier/admin-requests?direction=deposit"
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
                        href="/cashier/admin-requests?direction=withdraw"
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
                class="grid grid-cols-4 overflow-hidden rounded-2xl border border-line bg-card p-1 shadow-sm"
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
                        activeTab === 'review'
                            ? 'bg-brand text-white shadow-sm'
                            : 'text-slate hover:bg-mist hover:text-ink'
                    "
                    @click="activeTab = 'review'"
                >
                    To Review
                    <span
                        v-if="reviewCount"
                        class="ml-1 inline-flex min-w-5 items-center justify-center rounded-full bg-white/20 px-1.5 py-0.5 text-[10px]"
                    >
                        {{ reviewCount }}
                    </span>
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
                            Request {{ title }} from Admin
                        </h2>
                    </div>

                    <div>
                        <span class="bank-label">Asset</span>
                        <div class="mt-2 grid grid-cols-3 gap-2 sm:gap-3">
                            <button
                                type="button"
                                class="rounded-2xl border p-2 text-center transition sm:p-4"
                                :class="
                                    assetType === 'cash'
                                        ? 'border-brand bg-brand-soft ring-2 ring-brand/20'
                                        : 'border-line hover:bg-mist'
                                "
                                @click="assetType = 'cash'"
                            >
                                <AppMenuIcon
                                    name="vault"
                                    size="launcher"
                                />
                                <span
                                    class="mt-2 block text-sm font-black"
                                >
                                    Cash
                                </span>
                            </button>
                            <button
                                type="button"
                                class="rounded-2xl border p-2 text-center transition sm:p-4"
                                :class="
                                    assetType === 'pay'
                                        ? 'border-brand bg-brand-soft ring-2 ring-brand/20'
                                        : 'border-line hover:bg-mist'
                                "
                                @click="assetType = 'pay'"
                            >
                                <AppMenuIcon
                                    name="accounts"
                                    size="launcher"
                                />
                                <span
                                    class="mt-2 block text-sm font-black"
                                >
                                    Pay
                                </span>
                            </button>
                            <button
                                type="button"
                                class="rounded-2xl border p-2 text-center transition sm:p-4"
                                :class="
                                    assetType === 'bank'
                                        ? 'border-brand bg-brand-soft ring-2 ring-brand/20'
                                        : 'border-line hover:bg-mist'
                                "
                                @click="assetType = 'bank'"
                            >
                                <AppMenuIcon
                                    name="companies"
                                    size="launcher"
                                />
                                <span
                                    class="mt-2 block text-sm font-black"
                                >
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
                            v-model="requestForm.denominations"
                            :notes="notes"
                            label="Denomination Breakdown"
                            id-prefix="cashier-adjustment-cash"
                        />

                        <div
                            class="grid grid-cols-3 gap-3 rounded-xl bg-mist p-4 text-sm"
                        >
                            <div>
                                <p class="text-xs font-bold text-slate">
                                    Total
                                </p>
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
                                    {{
                                        assetType === 'pay'
                                            ? 'Pay'
                                            : 'Bank'
                                    }}
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
                                v-model.number="requestForm.account_id"
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
                                    {{ money(selectedAccount.balance) }}
                                    MMK
                                </p>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate">
                                    After Approval
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
                                v-model.number="requestForm.amount"
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
                            v-model.trim="requestForm.note"
                            rows="3"
                            class="bank-input resize-none"
                            placeholder="Reason for Admin approval"
                            required
                        />
                    </label>

                    <div
                        v-if="Object.keys(requestForm.errors).length"
                        class="rounded-lg border border-brand/20 bg-brand-soft p-3 text-sm font-bold text-brand"
                    >
                        {{ Object.values(requestForm.errors)[0] }}
                    </div>

                    <button
                        type="button"
                        class="bank-button bank-button-primary"
                        :disabled="!canReviewNew"
                        @click="reviewing = true"
                    >
                        Review {{ title }}
                    </button>
                </section>

                <section
                    v-else
                    class="grid gap-4 rounded-2xl border border-line bg-card p-5 shadow-sm"
                >
                    <p
                        class="text-xs font-black tracking-[0.12em] text-slate uppercase"
                    >
                        Review New Request
                    </p>
                    <h2 class="text-xl font-black text-ink">
                        {{ title }} · {{ assetLabel() }}
                    </h2>

                    <dl
                        class="grid gap-3 rounded-xl bg-mist p-4 text-sm sm:grid-cols-2"
                    >
                        <div>
                            <dt class="font-bold text-slate">Branch</dt>
                            <dd class="mt-1 font-black">
                                {{ branch.name }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate">Amount</dt>
                            <dd class="money mt-1 font-black">
                                {{
                                    money(
                                        assetType === 'cash'
                                            ? cashRequestedTotal
                                            : requestForm.amount,
                                    )
                                }}
                                MMK
                            </dd>
                        </div>
                        <div v-if="selectedAccount">
                            <dt class="font-bold text-slate">
                                Provider / Account
                            </dt>
                            <dd class="mt-1 font-black">
                                {{ selectedAccount.company?.name }} ·
                                {{ selectedAccount.account_name }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-bold text-slate">Remark</dt>
                            <dd class="mt-1 font-black">
                                {{ requestForm.note }}
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

                    <div
                        v-if="selectedAccount"
                        class="grid grid-cols-2 gap-3 rounded-xl border border-line p-4 text-sm"
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
                                After Approval
                            </p>
                            <p class="money mt-1 font-black">
                                {{ money(accountAfter) }} MMK
                            </p>
                        </div>
                    </div>

                    <p class="text-xs font-semibold leading-5 text-slate">
                        {{
                            assetType === 'cash'
                                ? 'Admin approves first. After approval, count the physical cash and confirm the handover with your Cashier PIN before the vault changes.'
                                : 'The account balance changes only after Admin approves this request.'
                        }}
                    </p>

                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            class="bank-button bank-button-secondary"
                            :disabled="requestForm.processing"
                            @click="reviewing = false"
                        >
                            Back
                        </button>
                        <button
                            type="button"
                            class="bank-button bank-button-primary"
                            :disabled="requestForm.processing"
                            @click="submitNew"
                        >
                            {{
                                requestForm.processing
                                    ? 'Sending…'
                                    : 'Send to Admin'
                            }}
                        </button>
                    </div>
                </section>
            </template>

            <section
                v-else-if="activeTab === 'review'"
                class="grid gap-5"
            >
                <section
                    v-if="readyHandover.length"
                    class="rounded-2xl border border-brand/25 bg-brand-soft/40 p-5 shadow-sm"
                >
                    <div
                        class="flex items-center justify-between gap-3"
                    >
                        <div>
                            <p
                                class="text-xs font-black tracking-[0.12em] text-brand uppercase"
                            >
                                Admin Approved
                            </p>
                            <h2
                                class="mt-1 text-lg font-black text-ink"
                            >
                                Confirm Cash Handover
                            </h2>
                        </div>
                        <span
                            class="rounded-full bg-brand px-3 py-1 text-xs font-black text-white"
                        >
                            {{ readyHandover.length }}
                        </span>
                    </div>

                    <p
                        class="mt-2 text-sm font-semibold leading-6 text-slate"
                    >
                        The vault has not changed yet. Count the physical
                        cash, then confirm the handover with your Cashier
                        PIN.
                    </p>

                    <div class="mt-4 grid gap-3">
                        <article
                            v-for="row in readyHandover"
                            :key="row.id"
                            class="rounded-2xl border border-line bg-card p-4"
                        >
                            <div
                                class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                            >
                                <div class="min-w-0">
                                    <p
                                        class="text-xs font-black tracking-[0.12em] text-slate uppercase"
                                    >
                                        Approved Request #{{ row.id }}
                                    </p>
                                    <h3
                                        class="mt-1 font-black text-ink"
                                    >
                                        Cash · {{ row.direction }}
                                    </h3>
                                    <p
                                        class="mt-1 text-sm font-semibold text-slate"
                                    >
                                        {{ row.note }}
                                    </p>
                                </div>
                                <p
                                    class="money shrink-0 text-xl font-black text-ink"
                                >
                                    {{ money(row.amount) }} MMK
                                </p>
                            </div>

                            <div
                                class="mt-4 flex justify-end gap-2"
                            >
                                <button
                                    type="button"
                                    class="bank-button bank-button-danger"
                                    @click="
                                        openDecision(row, 'reject')
                                    "
                                >
                                    Reject Handover
                                </button>
                                <button
                                    type="button"
                                    class="bank-button bank-button-primary"
                                    @click="
                                        openDecision(row, 'confirm')
                                    "
                                >
                                    Confirm Handover with PIN
                                </button>
                            </div>
                        </article>
                    </div>
                </section>

                <section
                    class="rounded-2xl border border-line bg-card p-5 shadow-sm"
                >
                    <div
                        class="flex items-center justify-between gap-3"
                    >
                        <div>
                            <p
                                class="text-xs font-black tracking-[0.12em] text-slate uppercase"
                            >
                                Action Required
                            </p>
                            <h2
                                class="mt-1 text-lg font-black text-ink"
                            >
                                Admin Requests
                            </h2>
                        </div>
                        <span
                            class="rounded-full bg-mist px-3 py-1 text-xs font-black"
                        >
                            {{ toReview.length }}
                        </span>
                    </div>

                    <div class="mt-4 grid gap-3">
                        <article
                            v-for="row in toReview"
                            :key="row.id"
                            class="rounded-2xl border border-line p-4"
                        >
                            <div
                                class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                            >
                                <div class="min-w-0">
                                    <p
                                        class="text-xs font-black tracking-[0.12em] text-slate uppercase"
                                    >
                                        Admin Request #{{ row.id }}
                                    </p>
                                    <h3
                                        class="mt-1 font-black text-ink"
                                    >
                                        {{ targetLabel(row) }}
                                    </h3>
                                    <p
                                        class="mt-1 text-sm font-semibold text-slate"
                                    >
                                        {{ row.note }}
                                    </p>
                                    <p
                                        class="mt-2 text-xs font-semibold text-slate"
                                    >
                                        {{
                                            row.requested_by_name ??
                                            'Admin'
                                        }}
                                        · {{ dateTime(row.created_at) }}
                                    </p>
                                </div>
                                <p
                                    class="money shrink-0 text-xl font-black text-ink"
                                >
                                    {{ money(row.amount) }} MMK
                                </p>
                            </div>

                            <div
                                class="mt-4 flex justify-end gap-2"
                            >
                                <button
                                    type="button"
                                    class="bank-button bank-button-danger"
                                    @click="
                                        openDecision(row, 'reject')
                                    "
                                >
                                    Reject
                                </button>
                                <button
                                    type="button"
                                    class="bank-button bank-button-primary"
                                    @click="
                                        openDecision(row, 'confirm')
                                    "
                                >
                                    Confirm with PIN
                                </button>
                            </div>
                        </article>

                        <div
                            v-if="
                                !toReview.length &&
                                !readyHandover.length
                            "
                            class="rounded-xl border border-dashed border-line px-5 py-10 text-center text-sm font-semibold text-slate"
                        >
                            No requests need your review.
                        </div>
                    </div>
                </section>
            </section>

            <section
                v-else-if="activeTab === 'waiting'"
                class="rounded-2xl border border-line bg-card p-5 shadow-sm"
            >
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p
                            class="text-xs font-black tracking-[0.12em] text-slate uppercase"
                        >
                            Sent by You
                        </p>
                        <h2 class="mt-1 text-lg font-black text-ink">
                            Waiting for Admin
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
                            <div class="min-w-0">
                                <p class="font-black text-ink">
                                    #{{ row.id }} ·
                                    {{ targetLabel(row) }}
                                </p>
                                <p
                                    class="mt-1 text-xs font-black text-brand"
                                >
                                    Waiting for Admin approval
                                </p>
                                <p
                                    class="mt-2 text-sm font-semibold text-slate"
                                >
                                    {{ row.note }}
                                </p>
                                <p
                                    class="mt-2 text-xs font-semibold text-slate"
                                >
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
                        v-if="!waiting.length"
                        class="rounded-xl border border-dashed border-line px-5 py-10 text-center text-sm font-semibold text-slate"
                    >
                        No requests are waiting for Admin.
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
                            <div class="min-w-0">
                                <div
                                    class="flex flex-wrap items-center gap-2"
                                >
                                    <p class="font-black text-ink">
                                        #{{ row.id }} ·
                                        {{ targetLabel(row) }}
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
                                <p
                                    class="mt-2 text-sm font-semibold text-slate"
                                >
                                    {{ row.note }}
                                </p>
                                <p
                                    class="mt-2 text-xs font-semibold text-slate"
                                >
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
                        No {{ title.toLowerCase() }} history yet.
                    </div>
                </div>
            </section>
        </div>

        <Teleport to="body">
            <div
                v-if="selectedDecision"
                class="fixed inset-0 z-[100] grid place-items-center bg-ink/60 p-4 backdrop-blur-sm"
                @click.self="closeDecision"
            >
                <section
                    class="w-full max-w-md rounded-2xl border border-line bg-card p-5 shadow-2xl"
                >
                    <h2 class="text-lg font-black text-ink">
                        {{
                            decisionAction === 'confirm'
                                ? selectedDecision?.status ===
                                  'APPROVED'
                                    ? 'Confirm Cash Handover'
                                    : `Confirm ${title}`
                                : selectedDecision?.status ===
                                    'APPROVED'
                                  ? 'Reject Cash Handover'
                                  : `Reject ${title}`
                        }}
                    </h2>

                    <p class="mt-2 text-sm font-semibold text-slate">
                        #{{ selectedDecision.id }} ·
                        {{ targetLabel(selectedDecision) }} ·
                        {{ money(selectedDecision.amount) }} MMK
                    </p>

                    <p
                        class="mt-3 rounded-lg bg-mist p-3 text-sm font-semibold text-ink"
                    >
                        {{ selectedDecision.note }}
                    </p>

                    <div
                        v-if="
                            selectedDecision.target_type === 'account'
                        "
                        class="mt-3 grid grid-cols-2 gap-3 rounded-lg border border-line p-3 text-sm"
                    >
                        <div>
                            <p class="text-xs font-bold text-slate">
                                Current Balance
                            </p>
                            <p class="money mt-1 font-black">
                                {{
                                    money(
                                        selectedDecision.account_balance,
                                    )
                                }}
                                MMK
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-slate">
                                After Confirmation
                            </p>
                            <p class="money mt-1 font-black">
                                {{
                                    money(
                                        rowAfterBalance(
                                            selectedDecision,
                                        ),
                                    )
                                }}
                                MMK
                            </p>
                        </div>
                    </div>

                    <div
                        v-else-if="
                            denominationEntries(selectedDecision).length
                        "
                        class="mt-3 rounded-lg border border-line p-3 text-sm"
                    >
                        <p class="text-xs font-bold text-slate">
                            Denominations
                        </p>
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <div
                                v-for="[denomination, quantity] in denominationEntries(
                                    selectedDecision,
                                )"
                                :key="denomination"
                                class="flex justify-between gap-2 rounded-lg bg-mist px-3 py-2"
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

                    <form
                        class="mt-5 grid gap-4"
                        @submit.prevent="submitDecision"
                    >
                        <label>
                            <span class="bank-label">Cashier PIN</span>
                            <input
                                v-model="decisionForm.pin"
                                type="password"
                                inputmode="numeric"
                                maxlength="8"
                                class="bank-input"
                                autocomplete="off"
                                required
                            />
                        </label>

                        <label v-if="decisionAction === 'reject'">
                            <span class="bank-label">
                                Reject Reason
                            </span>
                            <textarea
                                v-model.trim="decisionForm.note"
                                rows="3"
                                class="bank-input resize-none"
                            />
                        </label>

                        <div
                            v-if="
                                Object.keys(decisionForm.errors).length
                            "
                            class="rounded-lg border border-brand/20 bg-brand-soft p-3 text-sm font-bold text-brand"
                        >
                            {{
                                Object.values(
                                    decisionForm.errors,
                                )[0]
                            }}
                        </div>

                        <div class="flex justify-end gap-2">
                            <button
                                type="button"
                                class="bank-button bank-button-secondary"
                                :disabled="decisionForm.processing"
                                @click="closeDecision"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="bank-button"
                                :class="
                                    decisionAction === 'confirm'
                                        ? 'bank-button-primary'
                                        : 'bank-button-danger'
                                "
                                :disabled="decisionForm.processing"
                            >
                                {{
                                    decisionForm.processing
                                        ? 'Saving…'
                                        : decisionAction === 'confirm'
                                          ? selectedDecision?.status ===
                                            'APPROVED'
                                              ? 'Confirm Handover'
                                              : 'Confirm'
                                          : 'Reject'
                                }}
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </Teleport>
    </BankLayout>
</template>
