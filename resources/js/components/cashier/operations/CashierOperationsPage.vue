<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import DenomDrawer from '@/components/bank/DenomDrawer.vue';
import PinSeal from '@/components/teller/PinSeal.vue';
import BankLayout from '@/layouts/BankLayout.vue';
import {
    createNgweLweEcho,
    disconnectNgweLweEcho,
    subscribeToRoleChannel,
    watchNgweLweEchoConnection,
} from '@/lib/echo';
import type { RealtimeHandlers } from '@/lib/echo';
import { startSmartPolling } from '@/lib/smart-polling';

type Denoms = Record<number, number>;
type DenomRow = {
    denomination: number;
    quantity: number;
    total: number;
};
type Teller = {
    id: number;
    name: string;
    open_float_id?: number | null;
    open_float_status?: string | null;
    pending_additional_issues?: number;
};
type FloatRow = {
    id: number;
    employee_id: number;
    employee_name: string;
    status: string;
    total_amount: string;
    current_balance: string;
    closing_total: string;
    return_denominations_json: Denoms | null;
    created_at: string | null;
    received_at: string | null;
    closed_at: string | null;
    denominations: { denomination: number; quantity: number }[];
};
type VaultLog = {
    id: number;
    type: string;
    float_id: number | null;
    denomination: number;
    quantity: number;
    note: string | null;
    performed_by: string | null;
    created_at: string | null;
};
type RecentTransaction = {
    id: number;
    type: string;
    amount: string;
    fee: string;
    status: string;
    customer: string | null;
    teller: string;
    created_at: string | null;
};
type PendingCashIn = {
    id: number;
    amount: string;
    customer_name: string | null;
    teller: string;
    creator_role?: 'admin' | 'cashier' | 'teller' | string | null;
    settlement_amount?: string | null;
    customer_fee?: string | null;
    fee_payment_method?: string | null;
    received_denominations: Denoms;
    handoff_denominations: Denoms;
    change_denominations: Denoms;
    change_given: string;
    created_at: string | null;
};
type CashierSection =
    | 'dashboard'
    | 'teller-entry-notifications'
    | 'main-vault-denomination-stock'
    | 'morning-issue'
    | 'end-of-day'
    | 'teller-entry-history'
    | 'teller-entry-history-cash-in'
    | 'teller-entry-history-cash-out'
    | 'teller-entry-history-transfer'
    | 'teller-entry-history-exchange'
    | 'main-vault-audit-log';
type BreadcrumbItem = {
    label: string;
    href?: string;
};

const props = defineProps<{
    role: 'cashier';
    section: CashierSection;
    announcement?: string | null;
    notificationCount?: number;
    notes: number[];
    mainVault: Record<string, number>;
    availableVault: Record<string, number>;
    vaultTotal: number;
    vaultLogs: VaultLog[];
    floats: FloatRow[];
    tellers: Teller[];
    transactions: RecentTransaction[];
    pendingCashIns: PendingCashIn[];
}>();

const sectionLabels: Record<CashierSection, string> = {
    dashboard: 'Cashier dashboard',
    'teller-entry-notifications': 'Teller entry notifications',
    'main-vault-denomination-stock': 'Main vault denomination stock',
    'morning-issue': 'Teller float issue',
    'end-of-day': 'End-of-day',
    'teller-entry-history': 'Teller entry history',
    'teller-entry-history-cash-in': 'Cash In history',
    'teller-entry-history-cash-out': 'Cash Out history',
    'teller-entry-history-transfer': 'Transfer history',
    'teller-entry-history-exchange': 'Exchange history',
    'main-vault-audit-log': 'Main vault audit log',
};
const sectionDescriptions: Record<CashierSection, string> = {
    dashboard: 'Main vault, Teller floats and pending work at a glance.',
    'teller-entry-notifications':
        'New Teller Cash In entries waiting for cashier review.',
    'main-vault-denomination-stock':
        'Live note stock after issued Teller floats are removed.',
    'morning-issue':
        'Issue an opening float or add more cash to an ACTIVE Teller float during the day.',
    'end-of-day': 'Verify Teller float returns and add cash back to the vault.',
    'teller-entry-history': 'Read-only Teller transaction history.',
    'teller-entry-history-cash-in': 'Read-only Teller Cash In history.',
    'teller-entry-history-cash-out': 'Read-only Teller Cash Out history.',
    'teller-entry-history-transfer': 'Read-only Teller Transfer history.',
    'teller-entry-history-exchange': 'Read-only Teller Exchange history.',
    'main-vault-audit-log': 'Every main vault denomination movement.',
};
const sectionPaths: Record<CashierSection, string> = {
    dashboard: '/cashier',
    'teller-entry-notifications': '/cashier/teller-entry-notifications',
    'main-vault-denomination-stock': '/cashier/main-vault-denomination-stock',
    'morning-issue': '/cashier/morning-issue',
    'end-of-day': '/cashier/end-of-day',
    'teller-entry-history': '/cashier/teller-entry-history',
    'teller-entry-history-cash-in': '/cashier/teller-entry-history-cash-in',
    'teller-entry-history-cash-out': '/cashier/teller-entry-history-cash-out',
    'teller-entry-history-transfer': '/cashier/teller-entry-history-transfer',
    'teller-entry-history-exchange': '/cashier/teller-entry-history-exchange',
    'main-vault-audit-log': '/cashier/main-vault-audit-log',
};

const vaultEntryOpen = ref(false);
const vaultEntryType = ref<'vault_in' | 'adjustment'>('vault_in');
const vaultEntryDenoms = ref<Denoms>({});
const issueEmployeeId = ref<number | null>(null);
const issueDenoms = ref<Denoms>({});
const issueNote = ref('');
const busy = ref(false);
const error = ref('');
const notice = ref('');
const returnFloat = ref<FloatRow | null>(null);
const returnCountedDenoms = ref<Denoms>({});
const returnPinOpen = ref(false);
const returnPinBusy = ref(false);
const returnPinError = ref<string | null>(null);
const transactionSearch = ref('');
const transactionType = ref('all');
const transactionDateFrom = ref('');
const transactionDateTo = ref('');
const transactionPage = ref(1);
const transactionPageSize = ref(25);
const vaultLogSearch = ref('');
const vaultLogType = ref('all');
const vaultLogPage = ref(1);
const vaultLogPageSize = ref(25);
const pendingSearch = ref('');
const pendingPage = ref(1);
const pendingPageSize = ref(25);
const floatSearch = ref('');
const floatStatus = ref('pending');
const livePendingCashIns = ref<PendingCashIn[]>([...props.pendingCashIns]);
const unreadNotificationCount = ref(props.notificationCount ?? 0);
const pendingReview = ref<PendingCashIn | null>(null);
const pendingBusy = ref<number | null>(null);
const pendingPinOpen = ref(false);
const pendingPinBusy = ref(false);
const pendingPinError = ref<string | null>(null);
const pendingAction = ref<'confirm' | 'cancel'>('confirm');
let unsubscribeRole: (() => void) | null = null;
let unwatchEchoConnection: (() => void) | null = null;
let stopRealtimeFallback: (() => void) | null = null;
let realtimeConnected = false;

const activeSection = computed(() => props.section);
const isTransactionHistorySection = computed(() =>
    activeSection.value.startsWith('teller-entry-history'),
);
const pageTitle = computed(() => sectionLabels[activeSection.value]);
const pageDescription = computed(
    () => sectionDescriptions[activeSection.value],
);
const breadcrumbItems = computed<BreadcrumbItem[]>(() => [
    {
        label: 'Cashier',
        href:
            activeSection.value === 'teller-entry-notifications'
                ? undefined
                : sectionPaths['teller-entry-notifications'],
    },
    {
        label: pageTitle.value,
    },
]);
const availableByNumber = computed(() => {
    const result: Denoms = {};
    props.notes.forEach((note) => {
        result[note] = Number(props.availableVault[String(note)] ?? 0);
    });

    return result;
});
const vaultEntryTotal = computed(() =>
    denominationTotal(vaultEntryDenoms.value),
);
const issueTotal = computed(() => denominationTotal(issueDenoms.value));
const selectedIssueTeller = computed(() =>
    props.tellers.find((teller) => teller.id === issueEmployeeId.value),
);
const issuingAdditionalFloat = computed(
    () => selectedIssueTeller.value?.open_float_status === 'ACTIVE',
);
const issueBlockedByFloatState = computed(() => {
    const status = selectedIssueTeller.value?.open_float_status;

    return status !== null && status !== undefined && status !== 'ACTIVE';
});
const issueShortages = computed(() =>
    props.notes.filter(
        (note) =>
            Number(issueDenoms.value[note] ?? 0) >
            Number(availableByNumber.value[note] ?? 0),
    ),
);
const canIssue = computed(
    () =>
        issueEmployeeId.value !== null &&
        issueTotal.value > 0 &&
        issueShortages.value.length === 0 &&
        !issueBlockedByFloatState.value,
);
const returnDenoms = computed(
    () => returnFloat.value?.return_denominations_json ?? {},
);
const returnTotal = computed(() => denominationTotal(returnDenoms.value));
const returnCountedTotal = computed(() =>
    denominationTotal(returnCountedDenoms.value),
);
const returnCountedMatches = computed(
    () =>
        returnFloat.value !== null &&
        props.notes.every(
            (note) =>
                Number(returnCountedDenoms.value[note] ?? 0) ===
                Number(returnDenoms.value[note] ?? 0),
        ) &&
        returnCountedTotal.value === returnTotal.value,
);
const pendingReviewRows = computed(() => ({
    received: denominationRows(pendingReview.value?.received_denominations),
    handoff: denominationRows(pendingReview.value?.handoff_denominations),
    change: denominationRows(pendingReview.value?.change_denominations),
}));
const pendingReviewUsesHandoff = computed(
    () => pendingReview.value?.creator_role === 'teller',
);
const pendingReviewSettlementRows = computed(() =>
    pendingReviewUsesHandoff.value
        ? pendingReviewRows.value.handoff
        : pendingReviewRows.value.received,
);
const pendingReviewSettlementTotal = computed(() =>
    denominationRowsTotal(pendingReviewSettlementRows.value),
);
const pendingReviewExpectedSettlement = computed(() => {
    const transaction = pendingReview.value;

    if (!transaction) {
        return 0;
    }

    const settlement =
        transaction.settlement_amount === null ||
        transaction.settlement_amount === undefined
            ? Number.NaN
            : Number(transaction.settlement_amount);

    if (Number.isFinite(settlement)) {
        return settlement;
    }

    const fee =
        transaction.fee_payment_method === 'cash'
            ? Number(transaction.customer_fee ?? 0)
            : 0;

    return Number(transaction.amount ?? 0) + fee;
});
const pendingReviewBalanced = computed(
    () =>
        pendingReviewSettlementTotal.value ===
        pendingReviewExpectedSettlement.value,
);
const pendingReviewSettlementLabel = computed(() =>
    pendingReviewUsesHandoff.value ? 'Cashier handoff' : 'Main vault cash',
);
const historyTransactionTypes: Partial<Record<CashierSection, string>> = {
    'teller-entry-history-cash-in': 'cash_in',
    'teller-entry-history-cash-out': 'cash_out',
    'teller-entry-history-transfer': 'transfer',
    'teller-entry-history-exchange': 'exchange',
};
const filteredTransactions = computed(() => {
    const query = transactionSearch.value.trim().toLowerCase();
    const fixedType = historyTransactionTypes[activeSection.value] ?? null;

    return props.transactions.filter((transaction) => {
        const matchesType =
            fixedType !== null
                ? transaction.type === fixedType
                : transactionType.value === 'all' ||
                  transaction.type === transactionType.value;
        const haystack = [
            String(transaction.id),
            transaction.type,
            transaction.status,
            transaction.customer ?? '',
        ]
            .join(' ')
            .toLowerCase();
        const day = transaction.created_at
            ? transaction.created_at.slice(0, 10)
            : '';
        const matchesFrom =
            !transactionDateFrom.value || day >= transactionDateFrom.value;
        const matchesTo =
            !transactionDateTo.value || day <= transactionDateTo.value;

        return (
            matchesType &&
            matchesFrom &&
            matchesTo &&
            (!query || haystack.includes(query))
        );
    });
});
const transactionPageCount = computed(() =>
    Math.max(
        1,
        Math.ceil(
            filteredTransactions.value.length / transactionPageSize.value,
        ),
    ),
);
const paginatedTransactions = computed(() =>
    filteredTransactions.value.slice(
        (transactionPage.value - 1) * transactionPageSize.value,
        transactionPage.value * transactionPageSize.value,
    ),
);
const vaultLogTypes = computed(() => [
    ...new Set(props.vaultLogs.map((log) => log.type)),
]);
const filteredVaultLogs = computed(() => {
    const query = vaultLogSearch.value.trim().toLowerCase();
    return props.vaultLogs.filter(
        (log) =>
            (vaultLogType.value === 'all' || log.type === vaultLogType.value) &&
            (!query ||
                [
                    log.id,
                    log.type,
                    log.note,
                    log.performed_by,
                    log.denomination,
                    log.quantity,
                ].some((value) =>
                    String(value ?? '')
                        .toLowerCase()
                        .includes(query),
                )),
    );
});
const vaultLogPageCount = computed(() =>
    Math.max(
        1,
        Math.ceil(filteredVaultLogs.value.length / vaultLogPageSize.value),
    ),
);
const paginatedVaultLogs = computed(() =>
    filteredVaultLogs.value.slice(
        (vaultLogPage.value - 1) * vaultLogPageSize.value,
        vaultLogPage.value * vaultLogPageSize.value,
    ),
);
const filteredPendingCashIns = computed(() => {
    const query = pendingSearch.value.trim().toLowerCase();
    return livePendingCashIns.value.filter(
        (entry) =>
            !query ||
            [entry.id, entry.customer_name, entry.teller, entry.amount].some(
                (value) =>
                    String(value ?? '')
                        .toLowerCase()
                        .includes(query),
            ),
    );
});
const pendingCashInTotal = computed(() =>
    livePendingCashIns.value.reduce(
        (sum, entry) => sum + Number(entry.amount ?? 0),
        0,
    ),
);
const pendingPageCount = computed(() =>
    Math.max(
        1,
        Math.ceil(filteredPendingCashIns.value.length / pendingPageSize.value),
    ),
);
const paginatedPendingCashIns = computed(() =>
    filteredPendingCashIns.value.slice(
        (pendingPage.value - 1) * pendingPageSize.value,
        pendingPage.value * pendingPageSize.value,
    ),
);
const pendingReconciliationFloats = computed(() =>
    props.floats.filter((float) => float.status === 'PENDING_RECONCILIATION'),
);
const pendingReturnTotal = computed(() =>
    pendingReconciliationFloats.value.reduce(
        (sum, float) => sum + floatReturnTotal(float),
        0,
    ),
);
const filteredEndDayFloats = computed(() => {
    const query = floatSearch.value.trim().toLowerCase();
    return props.floats.filter((float) => {
        const matchesStatus =
            floatStatus.value === 'all' ||
            (floatStatus.value === 'pending'
                ? float.status === 'PENDING_RECONCILIATION'
                : float.status !== 'PENDING_RECONCILIATION');
        const matchesSearch =
            !query ||
            [float.id, float.employee_name, float.status].some((value) =>
                String(value ?? '')
                    .toLowerCase()
                    .includes(query),
            );
        return matchesStatus && matchesSearch;
    });
});
watch(
    [
        transactionSearch,
        transactionType,
        transactionDateFrom,
        transactionDateTo,
        transactionPageSize,
    ],
    () => (transactionPage.value = 1),
);
watch(
    [vaultLogSearch, vaultLogType, vaultLogPageSize],
    () => (vaultLogPage.value = 1),
);
watch([pendingSearch, pendingPageSize], () => (pendingPage.value = 1));
watch(
    () => props.pendingCashIns,
    (rows) => {
        livePendingCashIns.value = [...rows];
    },
);
watch(
    () => props.notificationCount,
    (count) => {
        unreadNotificationCount.value = count ?? 0;
    },
);
watch(
    transactionPageCount,
    (count) => (transactionPage.value = Math.min(transactionPage.value, count)),
);
watch(
    vaultLogPageCount,
    (count) => (vaultLogPage.value = Math.min(vaultLogPage.value, count)),
);
watch(
    pendingPageCount,
    (count) => (pendingPage.value = Math.min(pendingPage.value, count)),
);

function denominationTotal(denoms: Denoms): number {
    return Object.entries(denoms).reduce(
        (sum, [denomination, quantity]) =>
            sum + Number(denomination) * Number(quantity),
        0,
    );
}

function denominationRows(denoms: Denoms | null | undefined): DenomRow[] {
    return Object.entries(denoms ?? {})
        .map(([denomination, quantity]) => ({
            denomination: Number(denomination),
            quantity: Number(quantity),
            total: Number(denomination) * Number(quantity),
        }))
        .filter((row) => row.denomination > 0 && row.quantity > 0)
        .sort((left, right) => right.denomination - left.denomination);
}

function denominationRowsTotal(rows: DenomRow[]): number {
    return rows.reduce((sum, row) => sum + row.total, 0);
}

function formatMoney(value: string | number): string {
    return Number(value ?? 0).toLocaleString();
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

function floatReturnTotal(float: FloatRow): number {
    return denominationTotal(float.return_denominations_json ?? {});
}

function denominationSummary(denoms: Denoms): string {
    return (
        Object.entries(denoms ?? {})
            .filter(([, quantity]) => Number(quantity) > 0)
            .sort(([left], [right]) => Number(right) - Number(left))
            .map(
                ([denomination, quantity]) =>
                    formatMoney(denomination) + ' × ' + quantity,
            )
            .join(', ') || '—'
    );
}

function authHeaders(): Record<string, string> {
    return {};
}

function firstInertiaError(errors: Record<string, string>): string {
    return Object.values(errors)[0] ?? 'Request failed.';
}

function reload() {
    router.reload({
        only: [
            'mainVault',
            'availableVault',
            'vaultTotal',
            'vaultLogs',
            'floats',
            'tellers',
            'transactions',
            'pendingCashIns',
            'notificationCount',
        ],
        headers: authHeaders(),
    });
}

const refreshCashierData = () => reload();

function addRealtimePendingCashIn(payload: Record<string, unknown>): void {
    const transaction = payload.transaction as
        | Record<string, unknown>
        | undefined;

    if (
        !transaction ||
        transaction.transaction_type !== 'cash_in' ||
        transaction.status !== 'PENDING_CASHIER_CONFIRM'
    ) {
        return;
    }

    const id = Number(transaction.id);
    if (livePendingCashIns.value.some((entry) => entry.id === id)) {
        return;
    }

    livePendingCashIns.value.unshift({
        id,
        amount: String(transaction.amount ?? 0),
        customer_name: (transaction.customer_name as string | null) ?? null,
        teller: String(transaction.teller ?? 'Teller'),
        creator_role:
            (transaction.creator_role as PendingCashIn['creator_role']) ?? null,
        settlement_amount: String(
            transaction.settlement_amount ?? transaction.amount ?? 0,
        ),
        customer_fee: String(transaction.customer_fee ?? 0),
        fee_payment_method:
            (transaction.fee_payment_method as string | null) ?? null,
        received_denominations:
            (transaction.received_denominations as Denoms) ?? {},
        handoff_denominations:
            (transaction.handoff_denominations as Denoms) ?? {},
        change_denominations:
            (transaction.change_denominations as Denoms) ?? {},
        change_given: String(transaction.change_given ?? 0),
        created_at: (transaction.created_at as string | null) ?? null,
    });
    unreadNotificationCount.value += 1;
}

onMounted(() => {
    const echo = createNgweLweEcho();

    const handlers: RealtimeHandlers = {
        balance_update: refreshCashierData,
        new_transaction: refreshCashierData,
        cash_in_pending: addRealtimePendingCashIn,
        float_update: refreshCashierData,
        float_status_changed: refreshCashierData,
    };

    if (echo) {
        unwatchEchoConnection = watchNgweLweEchoConnection(echo, (state) => {
            realtimeConnected = state === 'connected';
        });
        unsubscribeRole = subscribeToRoleChannel(echo, 'cashier', handlers);
    }

    stopRealtimeFallback = startSmartPolling({
        refresh: refreshCashierData,
        shouldPoll: () => !realtimeConnected,
        activeIntervalMs: 5_000,
        hiddenIntervalMs: 60_000,
    });
});

onBeforeUnmount(() => {
    stopRealtimeFallback?.();
    unwatchEchoConnection?.();
    unsubscribeRole?.();
    disconnectNgweLweEcho();
});

function openVaultEntry(entryType: 'vault_in' | 'adjustment') {
    vaultEntryType.value = entryType;
    vaultEntryDenoms.value = {};
    vaultEntryOpen.value = true;
}

function recordVaultEntry() {
    if (vaultEntryTotal.value <= 0) {
        return;
    }

    busy.value = true;
    error.value = '';
    notice.value = '';

    router.post(
        '/cashier/vault/entries',
        {
            entry_type: vaultEntryType.value,
            denominations: vaultEntryDenoms.value,
            note:
                vaultEntryType.value === 'vault_in'
                    ? 'Cash received into main vault.'
                    : 'Cashier vault adjustment.',
        },
        {
            headers: authHeaders(),
            preserveScroll: true,
            onSuccess: () => {
                vaultEntryDenoms.value = {};
                vaultEntryOpen.value = false;
                notice.value = 'Cash received into the main vault.';
            },
            onError: (errors) => (error.value = firstInertiaError(errors)),
            onFinish: () => (busy.value = false),
        },
    );
}

function issueFloat() {
    if (!canIssue.value || issueEmployeeId.value === null) {
        return;
    }

    busy.value = true;
    error.value = '';
    notice.value = '';

    router.post(
        '/cashier/cash-floats',
        {
            employee_id: issueEmployeeId.value,
            denominations: issueDenoms.value,
            note: issueNote.value || null,
        },
        {
            headers: authHeaders(),
            preserveScroll: true,
            onSuccess: () => {
                issueDenoms.value = {};
                issueNote.value = '';
                notice.value = issuingAdditionalFloat.value
                    ? 'Additional float issued. Teller must count and receive it before the balance increases.'
                    : 'Teller float issued. Teller must count and receive it before use.';
            },
            onError: (errors) => (error.value = firstInertiaError(errors)),
            onFinish: () => (busy.value = false),
        },
    );
}

function openReturn(float: FloatRow) {
    returnFloat.value = float;
    returnCountedDenoms.value = {};
    returnPinOpen.value = false;
    returnPinError.value = null;
}

function closeReturnReview() {
    if (!returnPinBusy.value) {
        returnPinOpen.value = false;
        returnFloat.value = null;
        returnCountedDenoms.value = {};
    }
}

function requestReturnConfirmation() {
    if (!returnCountedMatches.value) {
        returnPinError.value =
            'Count the returned notes exactly before confirming.';

        return;
    }

    returnPinError.value = null;
    returnPinOpen.value = true;
}

function closeReturnPin() {
    if (!returnPinBusy.value) {
        returnPinOpen.value = false;
    }
}

function confirmReturn(pin: string) {
    if (!returnFloat.value) {
        return;
    }

    returnPinBusy.value = true;
    returnPinError.value = null;

    const floatId = returnFloat.value.id;
    router.post(
        `/cashier/cash-floats/${floatId}/confirm-return`,
        {
            closing_total: returnCountedTotal.value,
            pin,
            return_denominations: returnCountedDenoms.value,
        },
        {
            headers: authHeaders(),
            preserveScroll: true,
            onSuccess: () => {
                returnPinOpen.value = false;
                returnFloat.value = null;
                returnCountedDenoms.value = {};
                notice.value =
                    'Teller float return confirmed and added back to the main vault.';
            },
            onError: (errors) => {
                returnPinError.value = firstInertiaError(errors);
            },
            onFinish: () => (returnPinBusy.value = false),
        },
    );
}

function openCashInReview(entry: PendingCashIn) {
    pendingReview.value = entry;
    pendingPinError.value = null;

    router.post(
        `/cashier/notifications/${entry.id}/read`,
        {},
        {
            headers: authHeaders(),
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                unreadNotificationCount.value = Math.max(
                    0,
                    unreadNotificationCount.value - 1,
                );
            },
        },
    );
}

function closeCashInReview() {
    if (pendingBusy.value === null && !pendingPinBusy.value) {
        pendingPinOpen.value = false;
        pendingReview.value = null;
    }
}

function requestCashInReview(action: 'confirm' | 'cancel') {
    if (action === 'confirm' && !pendingReviewBalanced.value) {
        return;
    }

    pendingAction.value = action;
    pendingPinError.value = null;
    pendingPinOpen.value = true;
}

function closePendingPin() {
    if (!pendingPinBusy.value) {
        pendingPinOpen.value = false;
    }
}

function confirmPendingCashIn(pin: string) {
    if (!pendingReview.value) {
        return;
    }

    const entry = pendingReview.value;
    pendingBusy.value = entry.id;
    pendingPinBusy.value = true;
    pendingPinError.value = null;

    const action = pendingAction.value;
    router.post(
        `/cashier/transactions/${entry.id}/${action}-cash-in`,
        action === 'confirm'
            ? { pin }
            : { pin, note: 'Cancelled by cashier during review.' },
        {
            headers: authHeaders(),
            preserveScroll: true,
            onSuccess: () => {
                pendingPinOpen.value = false;
                pendingReview.value = null;
                notice.value =
                    action === 'confirm'
                        ? 'Cash In confirmed and posted to the main vault.'
                        : 'Cash In cancelled and Teller float/account state reversed.';
            },
            onError: (errors) => {
                pendingPinError.value = firstInertiaError(errors);
            },
            onFinish: () => {
                pendingPinBusy.value = false;
                pendingBusy.value = null;
            },
        },
    );
}

function statusLabel(status: string): string {
    return status.replaceAll('_', ' ');
}
</script>

<template>
    <BankLayout
        :role="role"
        :announcement="announcement"
        :notification-count="unreadNotificationCount"
    >
        <header
            class="mb-4 flex flex-col gap-2 border-b border-line pb-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <nav class="min-w-0 text-sm font-black" aria-label="Breadcrumb">
                <ol class="flex min-w-0 flex-wrap items-center gap-1.5">
                    <li
                        v-for="(item, index) in breadcrumbItems"
                        :key="`${item.label}-${index}`"
                        class="flex min-w-0 items-center gap-1.5"
                    >
                        <span
                            v-if="index > 0"
                            class="text-xs text-slate/50"
                            aria-hidden="true"
                            >/</span
                        >
                        <Link
                            v-if="item.href"
                            :href="item.href"
                            :headers="authHeaders()"
                            class="truncate text-slate transition hover:text-brand"
                        >
                            {{ item.label }}
                        </Link>
                        <span v-else class="truncate text-ink">
                            {{ item.label }}
                        </span>
                    </li>
                </ol>
                <p class="mt-1 text-xs font-semibold text-slate">
                    {{ pageDescription }}
                </p>
            </nav>
            <div class="shrink-0 text-left sm:text-right">
                <p class="text-[10px] font-black text-slate uppercase">
                    Main vault
                </p>
                <p class="money text-base font-black text-ink">
                    {{ formatMoney(vaultTotal) }} MMK
                </p>
            </div>
        </header>

        <div
            v-if="error"
            class="mb-4 rounded-xl border border-brand/20 bg-brand-soft px-4 py-3 text-sm font-semibold text-brand"
            role="alert"
        >
            {{ error }}
        </div>
        <div
            v-if="notice"
            class="mb-4 rounded-xl border border-balance/25 bg-balance/5 px-4 py-3 text-sm font-semibold text-balance"
            role="status"
        >
            {{ notice }}
        </div>

        <section v-if="activeSection === 'dashboard'" class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Link
                    href="/cashier/main-vault-denomination-stock"
                    :headers="authHeaders()"
                    class="rounded-2xl border border-line bg-card p-5 shadow-sm transition hover:border-brand/30 hover:shadow-md"
                >
                    <p class="text-xs font-black text-slate uppercase">
                        Main vault balance
                    </p>
                    <p class="money mt-3 text-2xl font-black text-ink">
                        {{ formatMoney(vaultTotal) }} MMK
                    </p>
                    <p class="mt-2 text-xs font-bold text-brand">
                        View denomination stock →
                    </p>
                </Link>
                <Link
                    href="/cashier/teller-entry-notifications"
                    :headers="authHeaders()"
                    class="rounded-2xl border border-line bg-card p-5 shadow-sm transition hover:border-brand/30 hover:shadow-md"
                >
                    <p class="text-xs font-black text-slate uppercase">
                        Pending Cash In
                    </p>
                    <p class="mt-3 text-2xl font-black text-ink">
                        {{ livePendingCashIns.length }}
                    </p>
                    <p class="money mt-2 text-xs font-bold text-brand">
                        {{ formatMoney(pendingCashInTotal) }} MMK awaiting
                        review →
                    </p>
                </Link>
                <Link
                    href="/cashier/morning-issue"
                    :headers="authHeaders()"
                    class="rounded-2xl border border-line bg-card p-5 shadow-sm transition hover:border-brand/30 hover:shadow-md"
                >
                    <p class="text-xs font-black text-slate uppercase">
                        Open Teller floats
                    </p>
                    <p class="mt-3 text-2xl font-black text-ink">
                        {{
                            floats.filter((row) => row.status !== 'closed')
                                .length
                        }}
                    </p>
                    <p class="mt-2 text-xs font-bold text-brand">
                        Issue or review floats →
                    </p>
                </Link>
                <Link
                    href="/cashier/end-of-day"
                    :headers="authHeaders()"
                    class="rounded-2xl border border-line bg-card p-5 shadow-sm transition hover:border-brand/30 hover:shadow-md"
                >
                    <p class="text-xs font-black text-slate uppercase">
                        Returns to reconcile
                    </p>
                    <p class="mt-3 text-2xl font-black text-ink">
                        {{ pendingReconciliationFloats.length }}
                    </p>
                    <p class="money mt-2 text-xs font-bold text-brand">
                        {{ formatMoney(pendingReturnTotal) }} MMK expected →
                    </p>
                </Link>
            </div>

            <div
                class="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(280px,.65fr)]"
            >
                <section
                    class="overflow-hidden rounded-2xl border border-line bg-card shadow-sm"
                >
                    <header
                        class="flex items-center justify-between border-b border-line px-5 py-4"
                    >
                        <h2 class="font-black text-ink">
                            Recent Teller transactions
                        </h2>
                        <Link
                            href="/cashier/teller-entry-history"
                            :headers="authHeaders()"
                            class="text-xs font-black text-brand"
                            >View all →</Link
                        >
                    </header>
                    <div class="divide-y divide-line">
                        <div
                            v-for="transaction in transactions.slice(0, 6)"
                            :key="transaction.id"
                            class="flex items-center justify-between gap-4 px-5 py-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-black text-ink">
                                    #{{ transaction.id }} ·
                                    {{ statusLabel(transaction.type) }}
                                </p>
                                <p
                                    class="truncate text-xs font-semibold text-slate"
                                >
                                    {{ transaction.teller }} ·
                                    {{ formatDate(transaction.created_at) }}
                                </p>
                            </div>
                            <p
                                class="money shrink-0 text-sm font-black text-ink"
                            >
                                {{ formatMoney(transaction.amount) }} MMK
                            </p>
                        </div>
                        <p
                            v-if="!transactions.length"
                            class="px-5 py-8 text-center text-sm font-semibold text-slate"
                        >
                            No Teller transactions yet.
                        </p>
                    </div>
                </section>

                <section
                    class="rounded-2xl border border-line bg-card p-5 shadow-sm"
                >
                    <h2 class="font-black text-ink">Quick actions</h2>
                    <div class="mt-4 grid gap-2">
                        <Link
                            href="/cashier/teller-entry-notifications"
                            :headers="authHeaders()"
                            class="rounded-xl bg-brand px-4 py-3 text-sm font-black text-white"
                            >Review pending Cash In</Link
                        >
                        <Link
                            href="/cashier/morning-issue"
                            :headers="authHeaders()"
                            class="rounded-xl bg-mist px-4 py-3 text-sm font-black text-ink"
                            >Issue Teller float</Link
                        >
                        <Link
                            href="/cashier/end-of-day"
                            :headers="authHeaders()"
                            class="rounded-xl bg-mist px-4 py-3 text-sm font-black text-ink"
                            >Reconcile end-of-day return</Link
                        >
                    </div>
                </section>
            </div>
        </section>

        <section
            v-if="activeSection === 'teller-entry-notifications'"
            class="mb-6 overflow-hidden rounded-2xl border border-brand/25 bg-card shadow-sm"
        >
            <header
                class="grid gap-4 border-b border-brand/15 bg-brand-soft/45 px-4 py-4 sm:px-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center"
            >
                <div>
                    <div class="flex items-center gap-2">
                        <span
                            class="grid size-8 place-items-center rounded-full bg-brand text-xs font-black text-white"
                            >{{ livePendingCashIns.length }}</span
                        >
                        <h2 class="text-lg font-black">
                            Pending Cash In reviews
                        </h2>
                    </div>
                    <p class="mt-1 text-xs text-slate">
                        Verify the Teller handoff before posting cash to the
                        main vault.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div
                        class="rounded-xl border border-brand/15 bg-card px-4 py-2"
                    >
                        <p class="text-[10px] font-black text-slate uppercase">
                            Pending total
                        </p>
                        <p class="money text-sm font-black text-ink">
                            {{ formatMoney(pendingCashInTotal) }} MMK
                        </p>
                    </div>
                    <span
                        class="rounded-pill bg-brand px-3 py-2 text-xs font-black text-white"
                    >
                        {{
                            livePendingCashIns.length
                                ? 'Action required'
                                : 'All clear'
                        }}
                    </span>
                </div>
            </header>
            <div
                v-if="livePendingCashIns.length"
                class="border-b border-line px-4 py-3 sm:px-6"
            >
                <input
                    v-model="pendingSearch"
                    type="search"
                    class="bank-input max-w-lg"
                    placeholder="Search reference, customer or teller"
                />
            </div>
            <div v-if="livePendingCashIns.length" class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead
                        class="border-b border-line bg-mist/45 text-[11px] tracking-wide text-slate uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 sm:px-6">Reference</th>
                            <th class="px-4 py-3">Teller</th>
                            <th class="px-4 py-3 text-right">Cash In</th>
                            <th class="px-4 py-3">Cashier handoff</th>
                            <th class="px-4 py-3">Time</th>
                            <th class="px-4 py-3 sm:px-6">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr
                            v-for="entry in paginatedPendingCashIns"
                            :key="entry.id"
                            class="hover:bg-mist/35"
                        >
                            <td class="px-4 py-3 font-black sm:px-6">
                                #{{ entry.id }}
                                <p class="text-xs font-normal text-slate">
                                    {{ entry.customer_name || 'Customer' }}
                                </p>
                            </td>
                            <td class="px-4 py-3 font-semibold">
                                {{ entry.teller }}
                            </td>
                            <td class="money px-4 py-3 text-right font-black">
                                {{ formatMoney(entry.amount) }} MMK
                            </td>
                            <td class="px-4 py-3 text-xs font-bold">
                                {{
                                    denominationSummary(
                                        entry.handoff_denominations,
                                    )
                                }}
                            </td>
                            <td class="px-4 py-3 text-xs text-slate">
                                {{ formatDate(entry.created_at) }}
                            </td>
                            <td class="px-4 py-3 sm:px-6">
                                <button
                                    type="button"
                                    :disabled="pendingBusy === entry.id"
                                    class="rounded-pill bg-ink px-3 py-2 text-xs font-bold whitespace-nowrap text-white hover:bg-brand disabled:opacity-40"
                                    @click="openCashInReview(entry)"
                                >
                                    {{
                                        pendingBusy === entry.id
                                            ? 'Reviewing...'
                                            : 'Open review'
                                    }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!paginatedPendingCashIns.length">
                            <td
                                colspan="6"
                                class="px-6 py-10 text-center text-slate"
                            >
                                No matching Teller entry.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer
                v-if="livePendingCashIns.length"
                class="grid items-center gap-3 border-t border-line px-4 py-4 text-sm font-semibold text-slate sm:px-6 md:grid-cols-3"
            >
                <span
                    >Showing
                    {{
                        filteredPendingCashIns.length
                            ? (pendingPage - 1) * pendingPageSize + 1
                            : 0
                    }}
                    to
                    {{
                        Math.min(
                            pendingPage * pendingPageSize,
                            filteredPendingCashIns.length,
                        )
                    }}
                    of {{ filteredPendingCashIns.length }} entries</span
                >
                <label class="bank-page-size justify-self-center"
                    >Show
                    <select
                        v-model.number="pendingPageSize"
                        class="bank-page-size-select"
                    >
                        <option :value="10">10</option>
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>
                    entries</label
                >
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="bank-button bank-button-secondary px-3 py-2"
                        :disabled="pendingPage <= 1"
                        @click="pendingPage--"
                    >
                        Previous</button
                    ><span class="self-center"
                        >{{ pendingPage }} / {{ pendingPageCount }}</span
                    ><button
                        type="button"
                        class="bank-button bank-button-secondary px-3 py-2"
                        :disabled="pendingPage >= pendingPageCount"
                        @click="pendingPage++"
                    >
                        Next
                    </button>
                </div>
            </footer>
            <p
                v-else
                class="px-6 py-8 text-center text-sm font-semibold text-balance"
            >
                No Teller entry is waiting for Cashier action.
            </p>
        </section>

        <section
            v-if="activeSection === 'main-vault-denomination-stock'"
            class="rounded-2xl border border-line bg-card shadow-sm"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-4 sm:px-6"
            >
                <div>
                    <h2 class="text-lg font-black">
                        Main vault denomination stock
                    </h2>
                    <p class="mt-1 text-xs text-slate">
                        Available stock is the live main vault balance after
                        issued Teller floats are removed.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-pill bg-ink px-4 py-2 text-xs font-bold text-white transition hover:bg-brand"
                        @click="openVaultEntry('vault_in')"
                    >
                        Record cash received
                    </button>
                    <button
                        type="button"
                        class="rounded-pill border border-line px-4 py-2 text-xs font-bold text-slate transition hover:border-brand hover:text-brand"
                        @click="openVaultEntry('adjustment')"
                    >
                        Record adjustment
                    </button>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-4 sm:p-6">
                <div
                    v-for="note in notes"
                    :key="note"
                    class="rounded-xl border border-line bg-mist/45 px-3 py-3"
                >
                    <p class="money text-lg font-black">
                        {{ formatMoney(note) }}
                    </p>
                    <p class="mt-1 text-xs text-slate">
                        {{ availableByNumber[note] ?? 0 }} available ·
                        {{ formatMoney((availableByNumber[note] ?? 0) * note) }}
                        MMK
                    </p>
                </div>
            </div>
        </section>

        <section
            v-if="
                activeSection === 'morning-issue' ||
                activeSection === 'end-of-day'
            "
            class="grid gap-6"
        >
            <section
                v-if="activeSection === 'morning-issue'"
                class="rounded-2xl border border-line bg-card shadow-sm"
            >
                <header class="border-b border-line px-4 py-4 sm:px-6">
                    <p
                        class="text-xs font-black tracking-wide text-brand uppercase"
                    >
                        Teller float issue
                    </p>
                    <h2 class="mt-1 text-lg font-black">
                        Issue / add Teller float
                    </h2>
                    <p class="mt-1 text-xs text-slate">
                        Issue the opening float, or issue more cash to the same
                        ACTIVE float during the day.
                    </p>
                </header>
                <div class="space-y-4 p-4 sm:p-6">
                    <label class="block text-sm font-bold" for="cashier-teller"
                        >Teller</label
                    >
                    <select
                        id="cashier-teller"
                        v-model="issueEmployeeId"
                        class="h-11 w-full rounded-xl border border-line bg-mist px-3 text-sm font-semibold outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                    >
                        <option :value="null">Choose a Teller</option>
                        <option
                            v-for="teller in tellers"
                            :key="teller.id"
                            :value="teller.id"
                        >
                            {{ teller.name }}
                        </option>
                    </select>
                    <div
                        v-if="selectedIssueTeller?.open_float_id"
                        class="rounded-xl border border-line bg-mist/45 px-3 py-2 text-xs font-semibold"
                    >
                        <template v-if="issuingAdditionalFloat">
                            Active Float #{{
                                selectedIssueTeller.open_float_id
                            }}
                            · this creates another pending issue.
                            <span
                                v-if="
                                    selectedIssueTeller.pending_additional_issues
                                "
                            >
                                {{
                                    selectedIssueTeller.pending_additional_issues
                                }}
                                issue(s) already waiting for Teller receipt.
                            </span>
                        </template>
                        <template v-else>
                            Float #{{ selectedIssueTeller.open_float_id }} is
                            {{ selectedIssueTeller.open_float_status }}. It must
                            be received/rejected or reconciled before another
                            issue.
                        </template>
                    </div>
                    <DenomDrawer
                        v-model="issueDenoms"
                        :notes="notes"
                        :stock="availableByNumber"
                        :enforce-stock="false"
                        compact
                        label="Cash float notes"
                    />
                    <input
                        v-model="issueNote"
                        type="text"
                        maxlength="2000"
                        placeholder="Optional note"
                        class="h-11 w-full rounded-xl border border-line bg-mist px-3 text-sm outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                    />
                    <p
                        v-if="issueShortages.length"
                        class="rounded-xl bg-brand-soft px-3 py-2 text-xs font-semibold text-brand"
                    >
                        Not enough stock for:
                        {{ issueShortages.map(formatMoney).join(', ') }} MMK
                    </p>
                    <button
                        type="button"
                        :disabled="!canIssue || busy"
                        class="w-full rounded-xl bg-brand px-4 py-3 text-sm font-black text-white transition hover:bg-ink disabled:cursor-not-allowed disabled:opacity-40"
                        @click="issueFloat"
                    >
                        {{
                            busy
                                ? 'Issuing…'
                                : (issuingAdditionalFloat
                                      ? 'Issue more '
                                      : 'Issue ') +
                                  formatMoney(issueTotal) +
                                  ' MMK to Teller'
                        }}
                    </button>
                </div>
            </section>

            <section
                v-if="activeSection === 'end-of-day'"
                class="rounded-2xl border border-line bg-card shadow-sm"
            >
                <header
                    class="grid gap-4 border-b border-line px-4 py-4 sm:px-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end"
                >
                    <div>
                        <p
                            class="text-xs font-black tracking-wide text-balance uppercase"
                        >
                            End-of-day
                        </p>
                        <h2 class="mt-1 text-lg font-black">
                            Teller reconciliation
                        </h2>
                        <p class="mt-1 text-xs text-slate">
                            Verify the physical notes returned by each Teller
                            before closing the float.
                        </p>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-[16rem_auto]">
                        <input
                            v-model="floatSearch"
                            type="search"
                            class="bank-input"
                            placeholder="Search Teller or float"
                        />
                        <select v-model="floatStatus" class="bank-input">
                            <option value="pending">Pending returns</option>
                            <option value="completed">Completed / other</option>
                            <option value="all">All floats</option>
                        </select>
                    </div>
                </header>
                <div
                    class="grid gap-3 border-b border-line bg-mist/25 p-4 sm:grid-cols-3 sm:p-6"
                >
                    <div class="rounded-xl border border-line bg-card p-4">
                        <p class="text-xs font-bold text-slate">
                            Pending returns
                        </p>
                        <p class="money mt-1 text-xl font-black text-ink">
                            {{ pendingReconciliationFloats.length }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-line bg-card p-4">
                        <p class="text-xs font-bold text-slate">
                            Expected return
                        </p>
                        <p class="money mt-1 text-xl font-black text-balance">
                            {{ formatMoney(pendingReturnTotal) }} MMK
                        </p>
                    </div>
                    <div class="rounded-xl border border-line bg-card p-4">
                        <p class="text-xs font-bold text-slate">All floats</p>
                        <p class="money mt-1 text-xl font-black text-ink">
                            {{ floats.length }}
                        </p>
                    </div>
                </div>
                <div class="divide-y divide-line">
                    <div
                        v-for="float in filteredEndDayFloats"
                        :key="float.id"
                        class="grid gap-4 px-4 py-4 transition hover:bg-mist/30 sm:px-6 md:grid-cols-[minmax(0,1fr)_auto_auto] md:items-center"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-bold">
                                {{ float.employee_name }}
                                <span class="text-xs font-normal text-slate"
                                    >· Float #{{ float.id }}</span
                                >
                            </p>
                            <p
                                class="mt-1 text-xs tracking-wide text-slate uppercase"
                            >
                                {{ statusLabel(float.status) }}
                            </p>
                            <details class="mt-2 text-xs text-slate">
                                <summary
                                    class="cursor-pointer font-bold text-ink"
                                >
                                    View issued notes
                                </summary>
                                <p class="mt-1">
                                    {{
                                        denominationSummary(
                                            Object.fromEntries(
                                                float.denominations.map(
                                                    (line) => [
                                                        line.denomination,
                                                        line.quantity,
                                                    ],
                                                ),
                                            ),
                                        )
                                    }}
                                </p>
                            </details>
                        </div>
                        <div class="grid grid-cols-2 gap-3 text-sm md:min-w-64">
                            <div class="rounded-lg bg-mist p-3">
                                <p class="text-xs font-bold text-slate">
                                    Issued
                                </p>
                                <p class="money mt-1 font-black text-ink">
                                    {{ formatMoney(float.total_amount) }} MMK
                                </p>
                            </div>
                            <div class="rounded-lg bg-mist p-3">
                                <p class="text-xs font-bold text-slate">
                                    Returned
                                </p>
                                <p class="money mt-1 font-black text-balance">
                                    {{ formatMoney(floatReturnTotal(float)) }}
                                    MMK
                                </p>
                            </div>
                        </div>
                        <button
                            v-if="float.status === 'PENDING_RECONCILIATION'"
                            type="button"
                            class="bank-button bank-button-primary whitespace-nowrap"
                            @click="openReturn(float)"
                        >
                            Verify return
                        </button>
                    </div>
                    <p
                        v-if="!filteredEndDayFloats.length"
                        class="px-6 py-10 text-center text-sm text-slate"
                    >
                        No matching Teller floats.
                    </p>
                </div>
            </section>
        </section>

        <section
            v-if="isTransactionHistorySection"
            class="rounded-2xl border border-line bg-card shadow-sm"
        >
            <header
                class="flex flex-wrap items-end justify-end gap-3 border-b border-line px-4 py-4 sm:px-6"
            >
                <div
                    class="grid w-full gap-2 sm:w-auto sm:grid-cols-[16rem_auto_auto_auto]"
                >
                    <input
                        v-model="transactionSearch"
                        type="search"
                        placeholder="Search reference, customer, status"
                        class="h-10 min-w-0 flex-1 rounded-xl border border-line bg-mist px-3 text-xs outline-none focus:border-brand focus:ring-2 focus:ring-brand/20 sm:w-64"
                    />
                    <select
                        v-if="activeSection === 'teller-entry-history'"
                        v-model="transactionType"
                        class="h-10 rounded-xl border border-line bg-mist px-3 text-xs font-bold outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                    >
                        <option value="all">All types</option>
                        <option value="cash_in">Cash In</option>
                        <option value="cash_out">Cash Out</option>
                        <option value="transfer">Transfer</option>
                        <option value="exchange">Exchange</option>
                    </select>
                    <input
                        v-model="transactionDateFrom"
                        type="date"
                        aria-label="From date"
                        class="h-10 rounded-xl border border-line bg-mist px-3 text-xs outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                    />
                    <input
                        v-model="transactionDateTo"
                        type="date"
                        aria-label="To date"
                        class="h-10 rounded-xl border border-line bg-mist px-3 text-xs outline-none focus:border-brand focus:ring-2 focus:ring-brand/20"
                    />
                </div>
            </header>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead
                        class="border-b border-line bg-mist/45 text-[11px] tracking-wide text-slate uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 sm:px-6">Reference</th>
                            <th class="px-4 py-3">Teller</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Amount</th>
                            <th class="px-4 py-3">Fee</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 sm:px-6">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr
                            v-for="transaction in paginatedTransactions"
                            :key="transaction.id"
                        >
                            <td class="px-4 py-3 font-bold sm:px-6">
                                #{{ transaction.id }}
                                <p class="text-xs font-normal text-slate">
                                    {{ transaction.customer || 'Customer' }}
                                </p>
                            </td>
                            <td class="px-4 py-3 font-semibold">
                                {{ transaction.teller }}
                            </td>
                            <td class="px-4 py-3">
                                {{ transaction.type.replaceAll('_', ' ') }}
                            </td>
                            <td class="money px-4 py-3 font-bold">
                                {{ formatMoney(transaction.amount) }} MMK
                            </td>
                            <td class="money px-4 py-3 text-xs text-slate">
                                {{ formatMoney(transaction.fee) }} MMK
                            </td>
                            <td
                                class="px-4 py-3 text-xs font-bold text-slate uppercase"
                            >
                                {{ statusLabel(transaction.status) }}
                            </td>
                            <td class="px-4 py-3 text-xs text-slate sm:px-6">
                                {{ formatDate(transaction.created_at) }}
                            </td>
                        </tr>
                        <tr v-if="!paginatedTransactions.length">
                            <td
                                colspan="7"
                                class="px-6 py-10 text-center text-sm text-slate"
                            >
                                No matching Teller entry.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer
                class="grid items-center gap-3 border-t border-line px-4 py-4 text-sm font-semibold text-slate sm:px-6 md:grid-cols-3"
            >
                <span
                    >Showing
                    {{
                        filteredTransactions.length
                            ? (transactionPage - 1) * transactionPageSize + 1
                            : 0
                    }}
                    to
                    {{
                        Math.min(
                            transactionPage * transactionPageSize,
                            filteredTransactions.length,
                        )
                    }}
                    of {{ filteredTransactions.length }} entries</span
                >
                <label class="bank-page-size justify-self-center"
                    >Show
                    <select
                        v-model.number="transactionPageSize"
                        class="bank-page-size-select"
                    >
                        <option :value="10">10</option>
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>
                    entries</label
                >
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="bank-button bank-button-secondary px-3 py-2"
                        :disabled="transactionPage <= 1"
                        @click="transactionPage--"
                    >
                        Previous</button
                    ><span class="self-center"
                        >{{ transactionPage }} /
                        {{ transactionPageCount }}</span
                    ><button
                        type="button"
                        class="bank-button bank-button-secondary px-3 py-2"
                        :disabled="transactionPage >= transactionPageCount"
                        @click="transactionPage++"
                    >
                        Next
                    </button>
                </div>
            </footer>
        </section>

        <section
            v-if="activeSection === 'main-vault-audit-log'"
            class="rounded-2xl border border-line bg-card shadow-sm"
        >
            <header class="border-b border-line px-4 py-4 sm:px-6">
                <h2 class="text-lg font-black">Main vault audit log</h2>
                <p class="mt-1 text-xs text-slate">
                    Every note movement is recorded with its operator and
                    reason.
                </p>
                <div class="mt-3 grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                    <input
                        v-model="vaultLogSearch"
                        type="search"
                        class="bank-input"
                        placeholder="Search movement, note, operator or denomination"
                    />
                    <select v-model="vaultLogType" class="bank-input">
                        <option value="all">All movements</option>
                        <option
                            v-for="type in vaultLogTypes"
                            :key="type"
                            :value="type"
                        >
                            {{ statusLabel(type) }}
                        </option>
                    </select>
                </div>
            </header>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead
                        class="border-b border-line bg-mist/45 text-[11px] tracking-wide text-slate uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 sm:px-6">Time</th>
                            <th class="px-4 py-3">Movement</th>
                            <th class="px-4 py-3">Note</th>
                            <th class="px-4 py-3">Quantity</th>
                            <th class="px-4 py-3 sm:px-6">Operator</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="log in paginatedVaultLogs" :key="log.id">
                            <td class="px-4 py-3 text-xs text-slate sm:px-6">
                                {{ formatDate(log.created_at) }}
                            </td>
                            <td class="px-4 py-3 font-bold">
                                {{ statusLabel(log.type) }}
                            </td>
                            <td class="money px-4 py-3">
                                {{ formatMoney(log.denomination) }}
                            </td>
                            <td class="px-4 py-3">{{ log.quantity }}</td>
                            <td class="px-4 py-3 text-xs text-slate sm:px-6">
                                {{ log.performed_by || 'Cashier' }}
                            </td>
                        </tr>
                        <tr v-if="!paginatedVaultLogs.length">
                            <td
                                colspan="5"
                                class="px-6 py-10 text-center text-sm text-slate"
                            >
                                No vault movements yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer
                class="grid items-center gap-3 border-t border-line px-4 py-4 text-sm font-semibold text-slate sm:px-6 md:grid-cols-3"
            >
                <span
                    >Showing
                    {{
                        filteredVaultLogs.length
                            ? (vaultLogPage - 1) * vaultLogPageSize + 1
                            : 0
                    }}
                    to
                    {{
                        Math.min(
                            vaultLogPage * vaultLogPageSize,
                            filteredVaultLogs.length,
                        )
                    }}
                    of {{ filteredVaultLogs.length }} entries</span
                >
                <label class="bank-page-size justify-self-center"
                    >Show
                    <select
                        v-model.number="vaultLogPageSize"
                        class="bank-page-size-select"
                    >
                        <option :value="10">10</option>
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>
                    entries</label
                >
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="bank-button bank-button-secondary px-3 py-2"
                        :disabled="vaultLogPage <= 1"
                        @click="vaultLogPage--"
                    >
                        Previous</button
                    ><span class="self-center"
                        >{{ vaultLogPage }} / {{ vaultLogPageCount }}</span
                    ><button
                        type="button"
                        class="bank-button bank-button-secondary px-3 py-2"
                        :disabled="vaultLogPage >= vaultLogPageCount"
                        @click="vaultLogPage++"
                    >
                        Next
                    </button>
                </div>
            </footer>
        </section>

        <div
            v-if="returnFloat"
            class="fixed inset-0 z-50 grid place-items-center bg-ink/55 p-3 sm:p-6"
            @click.self="closeReturnReview"
        >
            <section
                class="max-h-[calc(100vh-1.5rem)] w-full max-w-3xl overflow-y-auto rounded-2xl border border-line bg-card shadow-2xl sm:max-h-[calc(100vh-3rem)]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="return-review-title"
            >
                <header
                    class="flex items-start justify-between gap-4 border-b border-line px-5 py-4 sm:px-6"
                >
                    <div>
                        <p
                            class="text-xs font-black tracking-wide text-balance uppercase"
                        >
                            Teller return
                        </p>
                        <h2
                            id="return-review-title"
                            class="mt-1 text-lg font-black"
                        >
                            Verify Float #{{ returnFloat.id }}
                        </h2>
                        <p class="mt-1 text-xs text-slate">
                            Count the cash handed back by the Teller, then
                            confirm with your Cashier PIN.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-pill border border-line px-3 py-2 text-xs font-bold text-slate hover:bg-mist disabled:opacity-40"
                        :disabled="returnPinBusy"
                        @click="closeReturnReview"
                    >
                        Close
                    </button>
                </header>

                <div
                    class="grid gap-2 border-b border-line bg-mist/45 p-4 sm:grid-cols-3 sm:px-6"
                >
                    <div
                        class="rounded-xl border border-line bg-card px-3 py-2.5"
                    >
                        <p
                            class="text-[10px] font-bold tracking-wide text-slate uppercase"
                        >
                            Teller
                        </p>
                        <p class="mt-1 font-bold">
                            {{ returnFloat.employee_name }}
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-line bg-card px-3 py-2.5"
                    >
                        <p
                            class="text-[10px] font-bold tracking-wide text-slate uppercase"
                        >
                            Teller handback
                        </p>
                        <p class="money mt-1 font-black">
                            {{ formatMoney(returnTotal) }} MMK
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-line bg-card px-3 py-2.5"
                    >
                        <p
                            class="text-[10px] font-bold tracking-wide text-slate uppercase"
                        >
                            Cashier counted
                        </p>
                        <p class="money mt-1 font-black">
                            {{ formatMoney(returnCountedTotal) }} MMK
                        </p>
                    </div>
                </div>

                <div class="grid gap-4 p-4 sm:p-6">
                    <DenomDrawer
                        v-model="returnCountedDenoms"
                        :notes="notes"
                        :target="returnTotal"
                        :expected="returnDenoms"
                        label="Cashier counted return"
                        id-prefix="return-confirm-denomination"
                    />

                    <div
                        class="flex flex-wrap items-center justify-between gap-2 rounded-xl border px-4 py-3 text-xs"
                        :class="
                            returnCountedMatches
                                ? 'border-balance/25 bg-balance/5 text-balance'
                                : 'border-brand/25 bg-brand-soft text-brand'
                        "
                    >
                        <strong>
                            {{
                                returnCountedMatches
                                    ? 'Return count matched'
                                    : 'Return count mismatch'
                            }}
                        </strong>
                        <span class="money">
                            {{ formatMoney(returnCountedTotal) }} /
                            {{ formatMoney(returnTotal) }} MMK
                        </span>
                    </div>
                </div>

                <footer
                    class="flex justify-end gap-2 border-t border-line px-5 py-4 sm:px-6"
                >
                    <button
                        type="button"
                        class="rounded-pill border border-line px-4 py-2 text-xs font-bold text-slate transition hover:bg-mist disabled:opacity-40"
                        :disabled="returnPinBusy"
                        @click="closeReturnReview"
                    >
                        Back
                    </button>
                    <button
                        type="button"
                        class="rounded-pill bg-ink px-4 py-2 text-xs font-bold text-white transition hover:bg-brand disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="returnPinBusy || !returnCountedMatches"
                        @click="requestReturnConfirmation"
                    >
                        Confirm with PIN
                    </button>
                </footer>
            </section>
        </div>

        <div
            v-if="pendingReview"
            class="fixed inset-0 z-50 grid place-items-center bg-ink/55 p-3 sm:p-6"
            @click.self="closeCashInReview"
        >
            <section
                class="max-h-[calc(100vh-1.5rem)] w-full max-w-4xl overflow-y-auto rounded-2xl border border-line bg-card shadow-2xl sm:max-h-[calc(100vh-3rem)]"
                role="dialog"
                aria-modal="true"
                aria-labelledby="cash-in-review-title"
            >
                <header
                    class="flex items-start justify-between gap-4 border-b border-line px-5 py-4 sm:px-6"
                >
                    <div>
                        <p
                            class="text-xs font-black tracking-wide text-brand uppercase"
                        >
                            Teller Cash In
                        </p>
                        <h2
                            id="cash-in-review-title"
                            class="mt-1 text-lg font-black"
                        >
                            Review handoff #{{ pendingReview.id }}
                        </h2>
                        <p class="mt-1 text-xs text-slate">
                            Count the physical handoff before confirming into
                            the main vault.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-pill border border-line px-3 py-2 text-xs font-bold text-slate hover:bg-mist disabled:opacity-40"
                        :disabled="pendingBusy !== null"
                        @click="closeCashInReview"
                    >
                        Close
                    </button>
                </header>

                <div
                    class="grid gap-2 border-b border-line bg-mist/45 p-4 sm:grid-cols-3 sm:px-6"
                >
                    <div
                        class="rounded-xl border border-line bg-card px-3 py-2.5"
                    >
                        <p
                            class="text-[10px] font-bold tracking-wide text-slate uppercase"
                        >
                            Reference
                        </p>
                        <p class="mt-1 font-bold">#{{ pendingReview.id }}</p>
                    </div>
                    <div
                        class="rounded-xl border border-line bg-card px-3 py-2.5"
                    >
                        <p
                            class="text-[10px] font-bold tracking-wide text-slate uppercase"
                        >
                            Cash In
                        </p>
                        <p class="money mt-1 font-black">
                            {{ formatMoney(pendingReview.amount) }} MMK
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-line bg-card px-3 py-2.5"
                    >
                        <p
                            class="text-[10px] font-bold tracking-wide text-slate uppercase"
                        >
                            Expected settlement
                        </p>
                        <p class="money mt-1 font-black">
                            {{ formatMoney(pendingReviewExpectedSettlement) }}
                            MMK
                        </p>
                    </div>
                </div>

                <div class="grid gap-3 p-4 sm:grid-cols-2 sm:p-6">
                    <section
                        class="overflow-hidden rounded-xl border border-line bg-card"
                    >
                        <header
                            class="flex items-center justify-between gap-3 border-b border-line bg-mist/40 px-4 py-3"
                        >
                            <h3 class="text-sm font-bold">Customer received</h3>
                            <strong class="money text-sm">
                                {{
                                    formatMoney(
                                        denominationRowsTotal(
                                            pendingReviewRows.received,
                                        ),
                                    )
                                }}
                                MMK
                            </strong>
                        </header>
                        <div class="grid gap-1.5 p-3">
                            <div
                                v-for="row in pendingReviewRows.received"
                                :key="`cash-in-review-received-${row.denomination}`"
                                class="flex items-center justify-between rounded-lg bg-mist/60 px-3 py-2 text-xs"
                            >
                                <span class="money font-semibold">
                                    {{ formatMoney(row.denomination) }} x
                                    {{ row.quantity }}
                                </span>
                                <strong class="money">{{
                                    formatMoney(row.total)
                                }}</strong>
                            </div>
                            <p
                                v-if="!pendingReviewRows.received.length"
                                class="px-2 py-3 text-xs text-slate"
                            >
                                No denomination breakdown.
                            </p>
                        </div>
                    </section>

                    <section
                        class="overflow-hidden rounded-xl border border-balance/30 bg-card ring-2 ring-balance/5"
                    >
                        <header
                            class="flex items-center justify-between gap-3 border-b border-balance/15 bg-balance/5 px-4 py-3"
                        >
                            <h3 class="text-sm font-bold">
                                {{ pendingReviewSettlementLabel }}
                            </h3>
                            <strong class="money text-sm">
                                {{ formatMoney(pendingReviewSettlementTotal) }}
                                MMK
                            </strong>
                        </header>
                        <div class="grid gap-1.5 p-3">
                            <div
                                v-for="row in pendingReviewSettlementRows"
                                :key="`cash-in-review-settlement-${row.denomination}`"
                                class="flex items-center justify-between rounded-lg bg-mist/60 px-3 py-2 text-xs"
                            >
                                <span class="money font-semibold">
                                    {{ formatMoney(row.denomination) }} x
                                    {{ row.quantity }}
                                </span>
                                <strong class="money">{{
                                    formatMoney(row.total)
                                }}</strong>
                            </div>
                            <p
                                v-if="!pendingReviewSettlementRows.length"
                                class="px-2 py-3 text-xs text-slate"
                            >
                                No denomination breakdown.
                            </p>
                        </div>
                    </section>

                    <section
                        v-if="pendingReviewRows.change.length"
                        class="overflow-hidden rounded-xl border border-held/30 bg-card sm:col-span-2"
                    >
                        <header
                            class="flex items-center justify-between gap-3 border-b border-held/15 bg-held/5 px-4 py-3"
                        >
                            <h3 class="text-sm font-bold">Change given</h3>
                            <strong class="money text-sm text-held">
                                {{
                                    formatMoney(
                                        denominationRowsTotal(
                                            pendingReviewRows.change,
                                        ),
                                    )
                                }}
                                MMK
                            </strong>
                        </header>
                        <div class="grid gap-1.5 p-3 sm:grid-cols-3">
                            <div
                                v-for="row in pendingReviewRows.change"
                                :key="`cash-in-review-change-${row.denomination}`"
                                class="flex items-center justify-between rounded-lg bg-held/5 px-3 py-2 text-xs"
                            >
                                <span class="money font-semibold">
                                    {{ formatMoney(row.denomination) }} x
                                    {{ row.quantity }}
                                </span>
                                <strong class="money">{{
                                    formatMoney(row.total)
                                }}</strong>
                            </div>
                        </div>
                    </section>
                </div>

                <div
                    class="mx-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border px-4 py-3 text-xs sm:mx-6"
                    :class="
                        pendingReviewBalanced
                            ? 'border-balance/25 bg-balance/5 text-balance'
                            : 'border-brand/25 bg-brand-soft text-brand'
                    "
                >
                    <strong>
                        {{
                            pendingReviewBalanced
                                ? 'Denomination balanced'
                                : 'Denomination mismatch'
                        }}
                    </strong>
                    <span class="money">
                        {{ pendingReviewSettlementLabel }}
                        {{ formatMoney(pendingReviewSettlementTotal) }} /
                        {{ formatMoney(pendingReviewExpectedSettlement) }} MMK
                    </span>
                </div>

                <footer class="flex justify-end gap-2 px-4 py-4 sm:px-6">
                    <button
                        type="button"
                        class="rounded-pill border border-line px-4 py-2 text-xs font-bold text-slate transition hover:bg-mist disabled:opacity-40"
                        :disabled="pendingBusy !== null"
                        @click="closeCashInReview"
                    >
                        Back
                    </button>
                    <button
                        type="button"
                        class="rounded-pill border border-brand px-4 py-2 text-xs font-bold text-brand transition hover:bg-brand-soft disabled:opacity-40"
                        :disabled="pendingBusy !== null"
                        @click="requestCashInReview('cancel')"
                    >
                        Reject Cash In
                    </button>
                    <button
                        type="button"
                        class="rounded-pill bg-ink px-4 py-2 text-xs font-bold text-white transition hover:bg-brand disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="
                            pendingBusy !== null || !pendingReviewBalanced
                        "
                        @click="requestCashInReview('confirm')"
                    >
                        Confirm Cash In
                    </button>
                </footer>
            </section>
        </div>

        <div
            v-if="vaultEntryOpen"
            class="fixed inset-0 z-40 grid place-items-center bg-ink/55 p-4"
            @click.self="vaultEntryOpen = false"
        >
            <section
                class="max-h-[calc(100vh-2rem)] w-full max-w-lg overflow-y-auto rounded-2xl border border-line bg-card shadow-2xl"
                role="dialog"
                aria-modal="true"
                aria-labelledby="vault-entry-title"
            >
                <header
                    class="flex items-start justify-between border-b border-line px-5 py-4"
                >
                    <div>
                        <h2 id="vault-entry-title" class="text-lg font-black">
                            {{
                                vaultEntryType === 'vault_in'
                                    ? 'Record cash received'
                                    : 'Record vault adjustment'
                            }}
                        </h2>
                        <p class="mt-1 text-xs text-slate">
                            {{
                                vaultEntryType === 'vault_in'
                                    ? 'Add physical cash to the shared Cashier main vault.'
                                    : 'Use only for an approved physical cash correction.'
                            }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="text-xl text-slate"
                        @click="vaultEntryOpen = false"
                    >
                        ×
                    </button>
                </header>
                <div class="p-4 sm:p-5">
                    <DenomDrawer
                        v-model="vaultEntryDenoms"
                        :notes="notes"
                        label="Cash received"
                    />
                </div>
                <footer
                    class="flex justify-end gap-2 border-t border-line px-5 py-4"
                >
                    <button
                        type="button"
                        class="rounded-pill border border-line px-4 py-2 text-xs font-bold text-slate"
                        @click="vaultEntryOpen = false"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="vaultEntryTotal <= 0 || busy"
                        class="rounded-pill bg-ink px-4 py-2 text-xs font-bold text-white disabled:opacity-40"
                        @click="recordVaultEntry"
                    >
                        {{
                            busy
                                ? 'Saving…'
                                : 'Save ' +
                                  formatMoney(vaultEntryTotal) +
                                  ' MMK'
                        }}
                    </button>
                </footer>
            </section>
        </div>

        <PinSeal
            :open="pendingPinOpen"
            :title="
                pendingAction === 'confirm'
                    ? 'Confirm Cash In'
                    : 'Reject Cash In'
            "
            :detail="
                pendingAction === 'confirm'
                    ? 'Enter your Cashier PIN to post the handoff into the main vault.'
                    : 'Enter your Cashier PIN to reverse this pending Cash In.'
            "
            :busy="pendingPinBusy"
            :error="pendingPinError"
            @close="closePendingPin"
            @confirm="confirmPendingCashIn"
        />

        <PinSeal
            :open="returnPinOpen"
            title="Confirm Teller return"
            detail="Enter your Cashier PIN after the returned denominations match the Teller handback."
            :busy="returnPinBusy"
            :error="returnPinError"
            @close="closeReturnPin"
            @confirm="confirmReturn"
        />
    </BankLayout>
</template>
