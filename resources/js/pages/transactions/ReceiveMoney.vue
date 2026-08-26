<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AccountTile from '@/components/bank/AccountTile.vue';
import BigAmountInput from '@/components/bank/BigAmountInput.vue';
import CashOutSettlementDrawer from '@/components/bank/CashOutSettlementDrawer.vue';
import TransactionHistoryTable from '@/components/teller/TransactionHistoryTable.vue';
import BankLayout from '@/layouts/BankLayout.vue';
import type { TransactionHistoryRow } from '@/types/domain';

type AgentAccount = {
    id: number;
    company: string;
    company_id?: number | null;
    company_logo_url?: string | null;
    name: string;
    number?: string;
    balance: string;
    account_type?: string;
    is_agent?: boolean;
};

type Quote = {
    amount: string;
    payout: string;
    customer_total: string;
};

type Denoms = Record<number, number>;

const props = withDefaults(
    defineProps<{
        role: 'admin' | 'cashier' | 'teller';
        view?: 'entry' | 'history';
        announcement?: string | null;
        notificationCount?: number;
        float: { status: string; current_balance?: string } | null;
        notes: number[];
        floatStock: Record<number, number>;
        accounts: AgentAccount[];
        receiveMoneyQuote?: Quote | null;
        commission: string;
        completed?: { id: number; amount: string; status: string } | null;
        history?: TransactionHistoryRow[];
    }>(),
    { view: 'entry', history: () => [] },
);

const accountId = ref<number | null>(null);
const selectedCompany = ref('');
const amount = ref(0);
const customerName = ref('');
const customerPhone = ref('');
const sourceAccountNumber = ref('');
const note = ref('');
const denoms = ref<Denoms>({});
const showCashDenoms = ref(true);
const step = ref<'form' | 'review'>('form');
const submitting = ref(false);
const errors = ref<Record<string, string>>({});
const failedCompanyLogos = ref<Set<string>>(new Set());

const account = computed(() =>
    props.accounts.find((candidate) => candidate.id === accountId.value),
);
const quote = computed<Quote>(
    () =>
        props.receiveMoneyQuote ?? {
            amount: String(amount.value),
            payout: String(amount.value),
            customer_total: String(amount.value),
        },
);
const payout = computed(() => Number(quote.value.payout ?? 0));
const commissionNum = computed(() => Number(props.commission ?? 0));
const agentCreditTotal = computed(() => amount.value + commissionNum.value);
const cashierLocked = computed(() => props.role === 'cashier');
const floatLocked = computed(
    () => props.role === 'teller' && props.float?.status !== 'ACTIVE',
);
const companies = computed(() => {
    const unique = new Map<
        string,
        { id: number | null; name: string; logoUrl: string | null }
    >();

    for (const candidate of props.accounts) {
        if (!candidate.company || unique.has(candidate.company)) continue;

        unique.set(candidate.company, {
            id: candidate.company_id ?? null,
            name: candidate.company,
            logoUrl: candidate.company_logo_url ?? null,
        });
    }

    return Array.from(unique.values());
});
const visibleAccounts = computed(() =>
    props.accounts.filter(
        (candidate) =>
            !selectedCompany.value ||
            candidate.company === selectedCompany.value,
    ),
);

function companyKey(company: { id: number | null; name: string }): string {
    return company.id !== null ? `id:${company.id}` : `name:${company.name}`;
}
function hasCompanyLogo(company: {
    id: number | null;
    name: string;
    logoUrl: string | null;
}): boolean {
    return Boolean(
        company.logoUrl && !failedCompanyLogos.value.has(companyKey(company)),
    );
}
function markCompanyLogoFailed(company: {
    id: number | null;
    name: string;
    logoUrl: string | null;
}): void {
    failedCompanyLogos.value = new Set([
        ...failedCompanyLogos.value,
        companyKey(company),
    ]);
}

watch(
    companies,
    (values) => {
        if (!values.some((company) => company.name === selectedCompany.value)) {
            selectedCompany.value = values[0]?.name ?? '';
        }
    },
    { immediate: true },
);
watch(selectedCompany, () => {
    if (
        !visibleAccounts.value.some(
            (candidate) => candidate.id === accountId.value,
        )
    ) {
        accountId.value = null;
    }
});

const payoutDenomTotal = computed(() =>
    props.notes.reduce(
        (sum, denomination) =>
            sum + denomination * Number(denoms.value[denomination] ?? 0),
        0,
    ),
);
const projectedStockValid = computed(() =>
    props.notes.every(
        (denomination) =>
            Number(props.floatStock[denomination] ?? 0) -
                Number(denoms.value[denomination] ?? 0) >=
            0,
    ),
);
const tellerCashNetMovement = computed(() => -payout.value);
const ready = computed(
    () =>
        props.role === 'teller' &&
        !floatLocked.value &&
        accountId.value !== null &&
        amount.value > 0 &&
        customerName.value.trim() !== '' &&
        customerPhone.value.trim() !== '' &&
        payout.value > 0 &&
        payoutDenomTotal.value === payout.value &&
        projectedStockValid.value &&
        Number(props.float?.current_balance ?? 0) >= payout.value,
);
const readyIssue = computed(() => {
    if (cashierLocked.value) return 'Cashier review mode is read-only.';
    if (floatLocked.value) return 'An active Teller Float is required.';
    if (accountId.value === null) return 'Choose the PAY Agent account first.';
    if (amount.value <= 0) return 'Enter the Receive Money amount.';
    if (customerName.value.trim() === '') return 'Enter customer name.';
    if (customerPhone.value.trim() === '') return 'Enter customer phone.';
    if (Number(props.float?.current_balance ?? 0) < payout.value)
        return 'Teller Float balance is not enough.';
    if (payoutDenomTotal.value !== payout.value)
        return 'Count the exact customer payout denomination.';
    if (!projectedStockValid.value)
        return 'Selected denominations exceed Teller Float stock.';
    return '';
});

let quoteTimer: ReturnType<typeof setTimeout> | null = null;
watch([accountId, amount], () => {
    if (quoteTimer) clearTimeout(quoteTimer);

    quoteTimer = setTimeout(() => {
        if (!accountId.value || amount.value <= 0) return;

        router.get(
            '/transactions/receive-money',
            {
                account_id: accountId.value,
                amount: amount.value,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['receiveMoneyQuote', 'commission'],
            },
        );
    }, 180);
});
watch(payout, () => {
    denoms.value = {};
});

const amountHint = computed(() => {
    let remaining = Math.floor(Number(amount.value) || 0);
    if (remaining < 100) return '';

    const units: Array<[number, string]> = [
        [100_000, 'သိန်း'],
        [10_000, 'သောင်း'],
        [1_000, 'ထောင်'],
        [100, 'ရာ'],
    ];
    const parts: string[] = [];

    for (const [unit, label] of units) {
        const quantity = Math.floor(remaining / unit);
        if (quantity > 0) {
            parts.push(`${quantity.toLocaleString()} ${label}`);
            remaining -= quantity * unit;
        }
    }
    if (remaining > 0) parts.push(remaining.toLocaleString());
    return parts.join(' ');
});

function money(value: string | number | null | undefined): string {
    return Number(value ?? 0).toLocaleString();
}
function firstError(
    input: Record<string, string | string[]>,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(input).map(([key, value]) => [
            key,
            Array.isArray(value) ? String(value[0] ?? '') : String(value),
        ]),
    );
}
function submit(): void {
    if (!ready.value || submitting.value) return;

    submitting.value = true;
    errors.value = {};
    router.post(
        '/transactions/receive-money',
        {
            account_id: accountId.value,
            amount: amount.value,
            customer_name: customerName.value.trim(),
            customer_phone: customerPhone.value.trim(),
            source_account_number: sourceAccountNumber.value.trim() || null,
            note: note.value.trim() || null,
            denominations: denoms.value,
        },
        {
            preserveScroll: true,
            onError: (input) => {
                errors.value = firstError(input);
                step.value = 'form';
            },
            onFinish: () => (submitting.value = false),
        },
    );
}
</script>

<template>
    <BankLayout
        :role="role"
        :announcement="announcement"
        :notification-count="notificationCount"
    >
        <h1 class="text-2xl font-bold tracking-tight text-debit">
            Receive Money
        </h1>

        <TransactionHistoryTable
            v-if="view === 'history'"
            :rows="history"
            title="Receive Money History"
            empty-text="No Receive Money transactions yet."
        />

        <p
            v-if="view === 'entry' && cashierLocked"
            class="mt-4 max-w-3xl rounded-field bg-brand-soft px-4 py-3 text-sm font-semibold text-brand-deep"
        >
            Cashier review mode is read-only.
        </p>

        <section
            v-if="view === 'entry' && completed"
            class="mt-5 max-w-xl rounded-2xl border border-line bg-card p-7 shadow-sm sm:p-9"
        >
            <div class="text-center">
                <span
                    class="mx-auto grid size-14 place-items-center rounded-full bg-balance/10 text-2xl text-balance"
                    >✓</span
                >
                <h2 class="mt-3 text-xl font-bold">Receive Money successful</h2>
                <p class="money mt-1 text-sm text-slate">
                    Ref #{{ String(completed.id).padStart(6, '0') }}
                </p>
            </div>
            <dl class="mt-6 divide-y divide-line border-t border-line">
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">Agent account</dt>
                    <dd class="text-right font-bold">
                        {{ account?.name || '-' }}
                        <span
                            class="block text-[11px] font-medium text-slate"
                            >{{ account?.company || '-' }}</span
                        >
                    </dd>
                </div>
                <div class="flex justify-between py-3">
                    <dt class="text-sm text-slate">Transfer amount</dt>
                    <dd class="money text-lg font-bold">
                        {{ money(completed.amount) }}
                        <span class="text-[11px] text-slate">MMK</span>
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">Status</dt>
                    <dd class="font-bold">{{ completed.status }}</dd>
                </div>
            </dl>
        </section>

        <section
            v-else-if="view === 'entry' && step === 'form'"
            class="bank-form-shell mt-5 max-w-5xl p-5 sm:p-6"
            :class="cashierLocked ? 'pointer-events-none opacity-50' : ''"
        >
            <h2 class="text-base font-bold">Enter Receive Money details</h2>

            <div class="mt-4 space-y-4">
                <div
                    class="grid items-start gap-3 md:grid-cols-2 xl:grid-cols-3"
                >
                    <section
                        class="space-y-2 md:col-span-2 xl:col-span-3"
                        aria-labelledby="receive-money-provider-title"
                    >
                        <h3
                            id="receive-money-provider-title"
                            class="text-xs font-black text-slate"
                        >
                            Company
                        </h3>
                        <div
                            class="flex gap-2 overflow-x-auto pb-1.5"
                            role="radiogroup"
                            aria-label="Company"
                        >
                            <button
                                v-for="company in companies"
                                :key="company.id ?? company.name"
                                type="button"
                                role="radio"
                                :aria-checked="selectedCompany === company.name"
                                :title="company.name"
                                class="group flex min-h-12 shrink-0 items-center gap-2 rounded-field border px-2.5 py-1.5 text-left transition"
                                :class="[
                                    selectedCompany === company.name
                                        ? 'border-brand bg-brand-soft text-brand shadow-sm ring-2 ring-brand/15'
                                        : 'border-line bg-mist/40 text-ink hover:border-brand/40 hover:bg-brand-soft/40',
                                    hasCompanyLogo(company)
                                        ? 'min-w-16 justify-center'
                                        : 'min-w-36',
                                ]"
                                @click="selectedCompany = company.name"
                            >
                                <span
                                    v-if="hasCompanyLogo(company)"
                                    class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-lg border border-line bg-card text-xs font-black shadow-sm"
                                >
                                    <img
                                        :src="company.logoUrl ?? undefined"
                                        :alt="`${company.name} logo`"
                                        class="size-full object-contain p-0.5"
                                        @error="markCompanyLogoFailed(company)"
                                    />
                                </span>
                                <span v-else class="min-w-0">
                                    <span
                                        class="block truncate text-xs font-black"
                                        >{{ company.name }}</span
                                    >
                                </span>
                            </button>
                        </div>
                    </section>

                    <AccountTile
                        v-model="accountId"
                        :accounts="visibleAccounts"
                        label="Account to credit"
                        compact
                    />

                    <div>
                        <BigAmountInput
                            v-model="amount"
                            label="Receive Money amount"
                            required
                            compact
                        />
                        <p
                            v-if="amountHint"
                            class="mt-1.5 min-h-5 text-xs font-semibold text-slate"
                        >
                            Amount reading:
                            <span class="font-bold text-ink"
                                >{{ amountHint }} ကျပ်</span
                            >
                        </p>
                    </div>
                </div>

                <div
                    class="grid items-start gap-3 md:grid-cols-2 xl:grid-cols-3"
                >
                    <div>
                        <label
                            class="bank-label bank-required"
                            for="receive-customer-name"
                            >Customer name</label
                        >
                        <input
                            id="receive-customer-name"
                            v-model="customerName"
                            type="text"
                            autocomplete="name"
                            class="bank-input min-h-12 border border-line bg-mist px-3 py-2 transition focus:border-brand focus:ring-2 focus:ring-brand/20"
                        />
                    </div>
                    <div>
                        <label
                            class="bank-label bank-required"
                            for="receive-customer-phone"
                            >Customer phone</label
                        >
                        <input
                            id="receive-customer-phone"
                            v-model="customerPhone"
                            type="tel"
                            autocomplete="tel"
                            class="bank-input min-h-12 border border-line bg-mist px-3 py-2 transition focus:border-brand focus:ring-2 focus:ring-brand/20"
                        />
                    </div>
                    <div>
                        <label class="bank-label" for="receive-source-account"
                            >Source account / phone</label
                        >
                        <input
                            id="receive-source-account"
                            v-model="sourceAccountNumber"
                            type="text"
                            class="bank-input min-h-12 border border-line bg-mist px-3 py-2 transition focus:border-brand focus:ring-2 focus:ring-brand/20"
                        />
                    </div>
                </div>

                <div>
                    <label class="bank-label" for="receive-money-note"
                        >Note</label
                    >
                    <textarea
                        id="receive-money-note"
                        v-model="note"
                        maxlength="250"
                        rows="2"
                        class="bank-input min-h-12 resize-none border border-line bg-mist px-3 py-2"
                    />
                </div>

                <section
                    class="overflow-hidden rounded-field border border-brand/20 bg-card"
                    aria-labelledby="receive-money-settlement-title"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 border-b border-line bg-brand-soft/55 px-3 py-2.5 text-left transition hover:bg-brand-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/35 sm:px-4"
                        :aria-expanded="showCashDenoms"
                        aria-controls="receive-money-settlement"
                        @click="showCashDenoms = !showCashDenoms"
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <span
                                class="grid size-6 shrink-0 place-items-center rounded-lg bg-brand text-[10px] font-black text-white"
                                >01</span
                            >
                            <h3
                                id="receive-money-settlement-title"
                                class="truncate text-sm font-bold text-ink"
                            >
                                Cash settlement
                            </h3>
                        </div>
                        <div class="ml-auto shrink-0 text-right">
                            <p
                                class="text-[10px] font-bold tracking-wide text-slate uppercase"
                            >
                                Net teller cash
                            </p>
                            <p class="money text-base font-black text-brand">
                                {{ tellerCashNetMovement > 0 ? '+' : ''
                                }}{{ money(tellerCashNetMovement) }}
                                <span class="text-[10px] text-slate">MMK</span>
                            </p>
                        </div>
                        <span
                            class="grid size-7 shrink-0 place-items-center rounded-full bg-card text-sm font-black text-slate shadow-sm"
                        >
                            {{ showCashDenoms ? '⌃' : '⌄' }}
                        </span>
                    </button>
                    <div
                        v-show="showCashDenoms"
                        id="receive-money-settlement"
                        class="p-2.5 sm:p-3"
                    >
                        <CashOutSettlementDrawer
                            :notes="notes"
                            :stock="floatStock"
                            :payout="denoms"
                            :fee-received="{}"
                            :change="{}"
                            :payout-target="payout || 0"
                            :fee-due="0"
                            :cash-fee="false"
                            @update:payout="denoms = $event"
                        />
                    </div>
                </section>
            </div>

            <p
                v-for="(message, key) in errors"
                :key="key"
                class="mt-3 text-sm font-semibold text-brand"
            >
                {{ message }}
            </p>

            <div class="mt-6 border-t border-line pt-4">
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p
                        class="min-h-5 text-xs font-bold"
                        :class="ready ? 'text-balance' : 'text-brand'"
                    >
                        {{ ready ? 'Ready for review.' : readyIssue }}
                    </p>
                    <button
                        type="button"
                        :disabled="!ready"
                        class="bank-button bank-button-primary w-full px-7 sm:w-auto"
                        @click="step = 'review'"
                    >
                        Continue to Review
                    </button>
                </div>
            </div>
        </section>

        <section
            v-else-if="view === 'entry'"
            class="bank-form-shell mt-5 max-w-xl"
        >
            <h2 class="text-base font-bold">Review Receive Money</h2>
            <p class="mt-1 text-[13px] text-slate">
                Check the details before completing the payout.
            </p>

            <div
                class="mt-5 rounded-field border border-balance/25 bg-balance/5 px-4 py-4"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-black text-balance uppercase">
                            Customer receives
                        </p>
                        <p class="mt-1 text-[13px] font-semibold text-slate">
                            Cash paid by Teller
                        </p>
                    </div>
                    <p
                        class="money text-right text-2xl font-black text-balance"
                    >
                        {{ money(payout) }}
                        <span class="text-xs text-slate">MMK</span>
                    </p>
                </div>
            </div>

            <dl class="mt-5 divide-y divide-line border-y border-line">
                <div class="flex justify-between gap-6 py-3 text-sm">
                    <dt class="shrink-0 text-slate">Customer name</dt>
                    <dd class="min-w-0 text-right font-bold break-words">
                        {{ customerName }}
                    </dd>
                </div>
                <div class="flex justify-between gap-6 py-3 text-sm">
                    <dt class="shrink-0 text-slate">Customer phone</dt>
                    <dd class="money text-right font-bold">
                        {{ customerPhone }}
                    </dd>
                </div>
                <div
                    v-if="sourceAccountNumber"
                    class="flex justify-between gap-6 py-3 text-sm"
                >
                    <dt class="shrink-0 text-slate">Source account / phone</dt>
                    <dd class="min-w-0 text-right font-bold break-words">
                        {{ sourceAccountNumber }}
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">Agent account credit</dt>
                    <dd class="text-right font-bold">
                        {{ account?.name }}
                        <span
                            class="block text-[11px] font-medium text-slate"
                            >{{ account?.company }}</span
                        >
                    </dd>
                </div>
                <div class="flex justify-between py-3">
                    <dt class="text-sm text-slate">Transfer amount</dt>
                    <dd class="money text-lg font-bold">
                        {{ money(amount) }}
                        <span class="text-[11px] text-slate">MMK</span>
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">Agent IN commission · digital</dt>
                    <dd class="money font-bold text-balance">
                        +{{ money(commissionNum) }} MMK
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="text-slate">Agent digital credit total</dt>
                    <dd class="money font-bold text-balance">
                        +{{ money(agentCreditTotal) }} MMK
                    </dd>
                </div>
                <div class="flex justify-between py-3 text-sm">
                    <dt class="font-bold">Net teller cash</dt>
                    <dd
                        class="money font-bold"
                        :class="
                            tellerCashNetMovement < 0
                                ? 'text-brand'
                                : 'text-balance'
                        "
                    >
                        {{ tellerCashNetMovement > 0 ? '+' : ''
                        }}{{ money(tellerCashNetMovement) }}
                        MMK
                    </dd>
                </div>
                <div
                    v-if="note"
                    class="flex justify-between gap-6 py-3 text-sm"
                >
                    <dt class="shrink-0 text-slate">Note</dt>
                    <dd class="min-w-0 text-right break-words">{{ note }}</dd>
                </div>
            </dl>

            <p class="mt-4 text-xs font-semibold text-slate">
                IN commission is credited only to the Agent digital balance. It
                does not add to or subtract from Teller Float physical cash.
            </p>

            <div class="mt-6 flex gap-2">
                <button
                    type="button"
                    class="bank-button bank-button-secondary rounded-pill"
                    @click="step = 'form'"
                >
                    Back
                </button>
                <button
                    type="button"
                    :disabled="submitting"
                    class="bank-button bank-button-primary flex-1 rounded-pill disabled:opacity-40"
                    @click="submit"
                >
                    {{ submitting ? 'Submitting…' : 'Confirm Receive Money' }}
                </button>
            </div>
        </section>
    </BankLayout>
</template>
