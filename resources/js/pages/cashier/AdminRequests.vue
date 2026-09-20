<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BankLayout from '@/layouts/BankLayout.vue';

type AdjustmentRow = {
    id: number;
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
    confirmed_by_name: string | null;
    rejected_by_name: string | null;
    created_at: string | null;
};

type WorkflowTab = 'review' | 'history';

const props = defineProps<{
    role: 'cashier';
    notificationCount?: number;
    selectedDirection: 'deposit' | 'withdraw';
    branch: { id: number; code?: string | null; name: string };
    rows: AdjustmentRow[];
}>();

const activeTab = ref<WorkflowTab>('review');
const selectedDecision = ref<AdjustmentRow | null>(null);
const decisionAction = ref<'confirm' | 'reject'>('confirm');
const decisionForm = useForm({ pin: '', note: '' });

const directionRows = computed(() =>
    props.rows.filter(
        (row) =>
            row.direction === props.selectedDirection &&
            row.requester_role === 'admin',
    ),
);

const toReview = computed(() =>
    directionRows.value.filter((row) => row.status === 'PENDING'),
);

const history = computed(() =>
    directionRows.value.filter(
        (row) =>
            row.status === 'CONFIRMED' ||
            row.status === 'REJECTED',
    ),
);

const title = computed(() =>
    props.selectedDirection === 'deposit' ? 'Deposit' : 'Withdraw',
);

function money(
    value: string | number | null | undefined,
): string {
    return Number(value ?? 0).toLocaleString();
}

function dateTime(value?: string | null): string {
    return value ? new Date(value).toLocaleString() : '-';
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
                    Admin → Cashier
                </p>
                <h1 class="mt-1 text-2xl font-black text-ink">
                    {{ title }} Requests
                </h1>
                <p class="mt-1 text-sm font-semibold text-slate">
                    {{ branch.name }}
                    <span v-if="branch.code"> · {{ branch.code }}</span>
                </p>
                <p class="mt-1 text-xs font-semibold text-slate">
                    Cashier reviews Admin requests only. Cashier does not create
                    Deposit or Withdraw requests.
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
                class="grid grid-cols-2 overflow-hidden rounded-2xl border border-line bg-card p-1 shadow-sm"
                aria-label="Cashier adjustment workflow"
            >
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
                        v-if="toReview.length"
                        class="ml-1 inline-flex min-w-5 items-center justify-center rounded-full bg-white/20 px-1.5 py-0.5 text-[10px]"
                    >
                        {{ toReview.length }}
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

            <section
                v-if="activeTab === 'review'"
                class="rounded-2xl border border-line bg-card p-5 shadow-sm"
            >
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p
                            class="text-xs font-black tracking-[0.12em] text-slate uppercase"
                        >
                            Action Required
                        </p>
                        <h2 class="mt-1 text-lg font-black text-ink">
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
                                <h3 class="mt-1 font-black text-ink">
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
                                    {{ row.requested_by_name ?? 'Admin' }}
                                    · {{ dateTime(row.created_at) }}
                                </p>
                            </div>
                            <p
                                class="money shrink-0 text-xl font-black text-ink"
                            >
                                {{ money(row.amount) }} MMK
                            </p>
                        </div>

                        <div class="mt-4 flex justify-end gap-2">
                            <button
                                type="button"
                                class="bank-button bank-button-danger"
                                @click="openDecision(row, 'reject')"
                            >
                                Reject
                            </button>
                            <button
                                type="button"
                                class="bank-button bank-button-primary"
                                @click="openDecision(row, 'confirm')"
                            >
                                Confirm with PIN
                            </button>
                        </div>
                    </article>

                    <div
                        v-if="!toReview.length"
                        class="rounded-xl border border-dashed border-line px-5 py-10 text-center text-sm font-semibold text-slate"
                    >
                        No Admin requests need your review.
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
                                ? `Confirm ${title}`
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
                        v-if="selectedDecision.target_type === 'account'"
                        class="mt-3 grid grid-cols-2 gap-3 rounded-lg border border-line p-3 text-sm"
                    >
                        <div>
                            <p class="text-xs font-bold text-slate">
                                Current Balance
                            </p>
                            <p class="money mt-1 font-black">
                                {{ money(selectedDecision.account_balance) }}
                                MMK
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-slate">
                                After Confirmation
                            </p>
                            <p class="money mt-1 font-black">
                                {{ money(rowAfterBalance(selectedDecision)) }}
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
                            <span class="bank-label">Reject Reason</span>
                            <textarea
                                v-model.trim="decisionForm.note"
                                rows="3"
                                class="bank-input resize-none"
                            />
                        </label>

                        <div
                            v-if="Object.keys(decisionForm.errors).length"
                            class="rounded-lg border border-brand/20 bg-brand-soft p-3 text-sm font-bold text-brand"
                        >
                            {{ Object.values(decisionForm.errors)[0] }}
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
                                          ? 'Confirm'
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
