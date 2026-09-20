<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BankLayout from '@/layouts/BankLayout.vue';

type AdjustmentRow = {
    id: number;
    branch_id: number;
    branch_name: string | null;
    target_type: 'cash' | 'account';
    account_name: string | null;
    account_type: string | null;
    direction: 'deposit' | 'withdraw';
    amount: string | number;
    denominations: Record<number, number>;
    note: string | null;
    status: 'PENDING' | 'CONFIRMED' | 'REJECTED';
    requested_by_name: string | null;
    confirmed_by_name: string | null;
    rejected_by_name: string | null;
    created_at: string | null;
};

const props = defineProps<{
    role: 'cashier';
    notificationCount?: number;
    rows: AdjustmentRow[];
}>();

const selected = ref<AdjustmentRow | null>(null);
const action = ref<'confirm' | 'reject'>('confirm');

const form = useForm({
    pin: '',
    note: '',
});

const pending = computed(() =>
    props.rows.filter((row) => row.status === 'PENDING'),
);

const history = computed(() =>
    props.rows.filter((row) => row.status !== 'PENDING'),
);

const money = (value: string | number | null | undefined) =>
    Number(value ?? 0).toLocaleString();

const dateTime = (value?: string | null) =>
    value ? new Date(value).toLocaleString() : '-';

function targetLabel(row: AdjustmentRow): string {
    return row.target_type === 'cash'
        ? 'Cash Vault'
        : `${row.account_type ?? 'Account'} · ${row.account_name ?? '-'}`;
}

function open(row: AdjustmentRow, nextAction: 'confirm' | 'reject'): void {
    selected.value = row;
    action.value = nextAction;
    form.reset();
    form.clearErrors();
}

function close(): void {
    if (!form.processing) {
        selected.value = null;
        form.reset();
        form.clearErrors();
    }
}

function submit(): void {
    if (!selected.value) return;

    form.post(
        `/cashier/admin-requests/${selected.value.id}/${action.value}`,
        {
            preserveScroll: true,
            onSuccess: () => close(),
        },
    );
}
</script>

<template>
    <BankLayout
        :role="role"
        :notification-count="notificationCount"
    >
        <div class="mx-auto grid w-full max-w-7xl gap-5">
            <header class="border-b border-line pb-4">
                <p
                    class="text-xs font-black tracking-[0.18em] text-slate uppercase"
                >
                    Admin → Cashier
                </p>
                <h1 class="text-2xl font-black text-ink">
                    Admin Adjustment Requests
                </h1>
                <p class="mt-1 text-sm font-semibold text-slate">
                    Confirming with your PIN applies the actual Bank, Pay or
                    Cash balance change for your branch.
                </p>
            </header>

            <section
                class="rounded-xl border border-line bg-card p-5 shadow-sm"
            >
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-black text-ink">
                        Pending Requests
                    </h2>
                    <span
                        class="rounded-full bg-mist px-3 py-1 text-xs font-black"
                    >
                        {{ pending.length }}
                    </span>
                </div>

                <div class="mt-4 overflow-auto rounded-lg border border-line">
                    <table class="w-full min-w-[980px] text-left text-sm">
                        <thead class="bg-mist text-xs text-slate uppercase">
                            <tr>
                                <th class="px-4 py-3">Request</th>
                                <th class="px-4 py-3">Target</th>
                                <th class="px-4 py-3">Action</th>
                                <th class="px-4 py-3 text-right">Amount</th>
                                <th class="px-4 py-3">Admin</th>
                                <th class="px-4 py-3">Note</th>
                                <th class="px-4 py-3 text-right">Decision</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in pending" :key="row.id">
                                <td class="px-4 py-3">
                                    <p class="font-black">#{{ row.id }}</p>
                                    <p class="text-xs text-slate">
                                        {{ dateTime(row.created_at) }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    {{ targetLabel(row) }}
                                </td>
                                <td class="px-4 py-3 font-black capitalize">
                                    {{ row.direction }}
                                </td>
                                <td
                                    class="money px-4 py-3 text-right font-black"
                                >
                                    {{ money(row.amount) }}
                                </td>
                                <td class="px-4 py-3">
                                    {{ row.requested_by_name ?? 'Admin' }}
                                </td>
                                <td class="px-4 py-3 text-slate">
                                    {{ row.note ?? '-' }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            class="bank-button bank-button-primary px-3 py-2"
                                            @click="open(row, 'confirm')"
                                        >
                                            Confirm
                                        </button>
                                        <button
                                            type="button"
                                            class="bank-button bank-button-danger px-3 py-2"
                                            @click="open(row, 'reject')"
                                        >
                                            Reject
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="!pending.length">
                                <td
                                    colspan="7"
                                    class="px-4 py-8 text-center text-slate"
                                >
                                    No pending Admin requests.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section
                class="rounded-xl border border-line bg-card p-5 shadow-sm"
            >
                <h2 class="text-lg font-black text-ink">
                    Recent Decisions
                </h2>

                <div class="mt-4 overflow-auto rounded-lg border border-line">
                    <table class="w-full min-w-[820px] text-left text-sm">
                        <thead class="bg-mist text-xs text-slate uppercase">
                            <tr>
                                <th class="px-4 py-3">Request</th>
                                <th class="px-4 py-3">Target</th>
                                <th class="px-4 py-3">Action</th>
                                <th class="px-4 py-3 text-right">Amount</th>
                                <th class="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in history" :key="row.id">
                                <td class="px-4 py-3 font-black">
                                    #{{ row.id }}
                                </td>
                                <td class="px-4 py-3">
                                    {{ targetLabel(row) }}
                                </td>
                                <td class="px-4 py-3 capitalize">
                                    {{ row.direction }}
                                </td>
                                <td
                                    class="money px-4 py-3 text-right font-bold"
                                >
                                    {{ money(row.amount) }}
                                </td>
                                <td class="px-4 py-3 font-black">
                                    {{ row.status }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <Teleport to="body">
            <div
                v-if="selected"
                class="fixed inset-0 z-[100] grid place-items-center bg-ink/60 p-4 backdrop-blur-sm"
                @click.self="close"
            >
                <section
                    class="w-full max-w-md rounded-2xl border border-line bg-card p-5 shadow-2xl"
                >
                    <h2 class="text-lg font-black text-ink">
                        {{
                            action === 'confirm'
                                ? 'Confirm Admin Request'
                                : 'Reject Admin Request'
                        }}
                    </h2>
                    <p class="mt-2 text-sm font-semibold text-slate">
                        #{{ selected.id }} · {{ targetLabel(selected) }} ·
                        {{ selected.direction }} ·
                        {{ money(selected.amount) }} MMK
                    </p>

                    <form class="mt-5 grid gap-4" @submit.prevent="submit">
                        <label>
                            <span class="bank-label">Cashier PIN</span>
                            <input
                                v-model="form.pin"
                                type="password"
                                inputmode="numeric"
                                maxlength="8"
                                class="bank-input"
                                autocomplete="off"
                                required
                            />
                        </label>

                        <label v-if="action === 'reject'">
                            <span class="bank-label">Reject Reason</span>
                            <textarea
                                v-model.trim="form.note"
                                rows="3"
                                class="bank-input resize-none"
                            />
                        </label>

                        <div
                            v-if="Object.keys(form.errors).length"
                            class="rounded-lg border border-brand/20 bg-brand-soft p-3 text-sm font-bold text-brand"
                        >
                            {{ Object.values(form.errors)[0] }}
                        </div>

                        <div class="flex justify-end gap-2">
                            <button
                                type="button"
                                class="bank-button bank-button-secondary"
                                :disabled="form.processing"
                                @click="close"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="bank-button"
                                :class="
                                    action === 'confirm'
                                        ? 'bank-button-primary'
                                        : 'bank-button-danger'
                                "
                                :disabled="form.processing"
                            >
                                {{
                                    form.processing
                                        ? 'Saving…'
                                        : action === 'confirm'
                                          ? 'Confirm with PIN'
                                          : 'Reject with PIN'
                                }}
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </Teleport>
    </BankLayout>
</template>
