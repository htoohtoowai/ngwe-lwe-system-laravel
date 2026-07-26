<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import { ApiRequestError, apiRequest } from '../lib/api';
import { readStoredToken, removeStoredToken } from '../lib/auth-token';
import {
    createNgweLweEcho,
    disconnectNgweLweEcho,
    subscribeToRoleChannel,
    subscribeToUserChannel,
} from '../lib/echo';
import type { RealtimeEventName, RealtimePayload } from '../lib/echo';
import type {
    Account,
    ActivityLog,
    ApiCollection,
    ApiItem,
    CashFloat,
    Company,
    CommissionTier,
    DailyReconciliation,
    DailySummaryReport,
    DenominationMap,
    DeviceContext,
    ExchangeRate,
    ManagedUser,
    Role,
    ServiceType,
    SessionUser,
    Transaction,
    VaultInventory,
    VaultTransaction,
} from '../types';

type NoticeTone = 'ok' | 'warn' | 'error';
type TransactionMode = 'cash-in' | 'cash-out' | 'transfer' | 'exchange';
type ConsoleIcon =
    | 'layout'
    | 'receipt'
    | 'users'
    | 'chart'
    | 'settings'
    | 'wallet'
    | 'vault'
    | 'coins'
    | 'cashier'
    | 'edit'
    | 'activity';
type ConsolePage =
    | 'dashboard'
    | 'transactions'
    | 'activity-logs'
    | 'users'
    | 'reports'
    | 'setup'
    | 'commission-tiers'
    | 'server-connection'
    | 'accounts'
    | 'vault'
    | 'cash-floats'
    | 'cashier'
    | 'teller'
    | 'cash-in'
    | 'cash-out'
    | 'transfer'
    | 'exchange'
    | 'float-receipt'
    | 'realtime';

type Notice = {
    tone: NoticeTone;
    message: string;
};

type RealtimeEntry = {
    id: number;
    event: RealtimeEventName;
    at: string;
    payload: RealtimePayload;
};

type ConsoleMenuChild = {
    id: ConsolePage;
    label: string;
};

type ConsoleMenuItem = {
    id: ConsolePage;
    label: string;
    icon: ConsoleIcon;
    badge?: string | number;
    children?: ConsoleMenuChild[];
};

type ConsoleMenuSection = {
    label: string;
    items: ConsoleMenuItem[];
};

const transactionModes: Array<{ id: TransactionMode; label: string }> = [
    { id: 'cash-in', label: 'Cash In' },
    { id: 'cash-out', label: 'Cash Out' },
    { id: 'transfer', label: 'Transfer' },
    { id: 'exchange', label: 'Exchange' },
];
const transactionPageIds: TransactionMode[] = transactionModes.map(
    (mode) => mode.id,
);
const tellerTransactionChildren: ConsoleMenuChild[] = transactionModes;
const tellerFloatChildren: ConsoleMenuChild[] = [
    { id: 'float-receipt', label: 'Float Receipt' },
];
const menuIconPaths: Record<ConsoleIcon, string[]> = {
    layout: [
        'M4 4h7v7H4V4Z',
        'M13 4h7v5h-7V4Z',
        'M13 11h7v9h-7v-9Z',
        'M4 13h7v7H4v-7Z',
    ],
    receipt: [
        'M6 3h12v18l-2-1.2-2 1.2-2-1.2-2 1.2-2-1.2L6 21V3Z',
        'M9 8h6',
        'M9 12h6',
        'M9 16h4',
    ],
    users: [
        'M16 19c0-2.2-1.8-4-4-4H8c-2.2 0-4 1.8-4 4',
        'M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
        'M20 19c0-1.8-1.2-3.2-2.8-3.8',
        'M16 4.4a3 3 0 0 1 0 5.2',
    ],
    chart: ['M4 19V5', 'M4 19h16', 'M8 16v-5', 'M12 16V8', 'M16 16v-9'],
    settings: [
        'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z',
        'M19.4 15a1.8 1.8 0 0 0 .36 1.98l.04.05-1.8 3.12-.06-.02a1.8 1.8 0 0 0-1.98.2 1.8 1.8 0 0 0-.76 1.67H8.8a1.8 1.8 0 0 0-.76-1.67 1.8 1.8 0 0 0-1.98-.2L6 20.15 4.2 17.03l.04-.05A1.8 1.8 0 0 0 4.6 15a1.8 1.8 0 0 0-1.4-1.27v-3.46A1.8 1.8 0 0 0 4.6 9a1.8 1.8 0 0 0-.36-1.98l-.04-.05L6 3.85l.06.02a1.8 1.8 0 0 0 1.98-.2A1.8 1.8 0 0 0 8.8 2h6.4a1.8 1.8 0 0 0 .76 1.67 1.8 1.8 0 0 0 1.98.2l.06-.02 1.8 3.12-.04.05A1.8 1.8 0 0 0 19.4 9a1.8 1.8 0 0 0 1.4 1.27v3.46A1.8 1.8 0 0 0 19.4 15Z',
    ],
    wallet: [
        'M4 7.5A2.5 2.5 0 0 1 6.5 5H18a2 2 0 0 1 2 2v12H6.5A2.5 2.5 0 0 1 4 16.5v-9Z',
        'M4 8h14a2 2 0 0 1 2 2',
        'M16 14h4',
    ],
    vault: [
        'M5 7h14v12H5V7Z',
        'M8 7V5h8v2',
        'M12 11a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z',
        'M12 9v2',
        'M12 15v2',
    ],
    coins: [
        'M8 7c0-2 2.2-3 5-3s5 1 5 3-2.2 3-5 3-5-1-5-3Z',
        'M8 7v5c0 2 2.2 3 5 3s5-1 5-3V7',
        'M5 11c-1.8.5-3 1.5-3 2.8 0 2 2.2 3 5 3 .7 0 1.4-.06 2-.2',
        'M2 14.3v3c0 2 2.2 3 5 3 1.2 0 2.3-.18 3.1-.54',
    ],
    cashier: ['M4 7h16v10H4V7Z', 'M7 17v3', 'M17 17v3', 'M8 11h8', 'M9 14h2'],
    edit: ['M5 19h4l10-10-4-4L5 15v4Z', 'M13.5 6.5l4 4'],
    activity: ['M4 12h4l2-6 4 12 2-6h4'],
};

const token = ref(readStoredToken());
const session = ref<SessionUser | null>(null);
const page = usePage<{ device?: DeviceContext }>();
const previewRole = ref<Role>('admin');
const notice = ref<Notice | null>(null);
const loading = ref(false);
const workspaceLoading = ref(false);
const realtimeStatus = ref('offline');
const realtimeEvents = ref<RealtimeEntry[]>([]);
const eventCounter = ref(0);
const activePage = ref<ConsolePage>('dashboard');
const userMenuOpen = ref(false);
const menuGroupsOpen = ref<Record<string, boolean>>({});

const companies = ref<Company[]>([]);
const users = ref<ManagedUser[]>([]);
const serviceTypes = ref<ServiceType[]>([]);
const accounts = ref<Account[]>([]);
const transactions = ref<Transaction[]>([]);
const cashFloats = ref<CashFloat[]>([]);
const inventory = ref<VaultInventory | null>(null);
const vaultLog = ref<VaultTransaction[]>([]);
const activityLogs = ref<ActivityLog[]>([]);
const commissionTiers = ref<CommissionTier[]>([]);
const commissionTierServiceTypeId = ref('');
const activityLogDate = ref(todayDate());
const activityLogEntity = ref('');
const activityLogAction = ref('');
const systemStatus = ref<{ name: string; domain: string; status: string } | null>(null);
const webOrigin = ref('');
const dailySummary = ref<DailySummaryReport | null>(null);
const reconciliationLogs = ref<DailyReconciliation[]>([]);
const latestRate = ref<ExchangeRate | null>(null);
const cashInReview = ref<Transaction | null>(null);

const pinForm = ref({
    pin: '',
});
const passwordForm = ref({
    current_password: '',
    password: '',
    password_confirmation: '',
});
const userForm = ref({
    id: '',
    username: '',
    email: '',
    full_name: '',
    role: 'teller' as Role,
    password: '',
    pin: '',
    is_active: true,
});
const reportForm = ref({
    date: todayDate(),
    notes: '',
});
const companyForm = ref({
    name: '',
    category: 'Pay',
});
const serviceForm = ref({
    company_id: '',
    name: '',
    operation: 'CashIn',
});
const accountForm = ref({
    service_type_id: '',
    account_name: '',
    phone_number: '',
    balance: '0',
    commission_rate: '0',
    is_fee_account: false,
});
const rateForm = ref({
    base_currency: 'THB',
    quote_currency: 'MMK',
    base_amount: '1',
    buy_rate: '',
    sell_rate: '',
});
const tierForm = ref({
    id: '',
    service_type_id: '',
    amount_from: '',
    amount_to: '',
    fee_amount_type: 'FIXED',
    fee_amount_deposit: '0',
    fee_amount_withdraw: '0',
    comm_type: 'FIXED',
    comm_deposit: '0',
    comm_withdraw: '0',
    additional_fee_type: 'FIXED',
    additional_fee_deposit_amount: '0',
    additional_fee_withdraw_amount: '0',
});
const adjustForm = ref({
    account_id: '',
    amount: '',
    remark: '',
});
const floatIssueForm = ref({
    employee_id: '',
    denominations: '{"10000": 3, "5000": 2}',
    note: '',
});
const floatReturnForm = ref({
    float_id: '',
    closing_total: '',
    pin: '',
});
const floatActivateForm = ref({
    float_id: '',
    pin: '',
    verified_denominations: '{"10000": 1}',
});
const floatInitiateReturnForm = ref({
    float_id: '',
    return_denominations: '{"10000": 1}',
});
const transactionMode = ref<TransactionMode>('cash-in');
const transactionForm = ref({
    account_id: '',
    from_account_id: '',
    to_account_id: '',
    amount: '',
    customer_name: '',
    customer_phone: '',
    amount_received: '',
    currency: 'MMK',
    denominations: '{"10000": 1}',
    change_denominations: '{}',
    note: '',
});

let unsubscribeRole: (() => void) | null = null;
let unsubscribeUser: (() => void) | null = null;

const activeRole = computed<Role>(
    () => session.value?.role ?? previewRole.value,
);
const device = computed<DeviceContext>(
    () =>
        page.props.device ?? {
            type: 'desktop',
            view: 'web',
            is_mobile: false,
            is_tablet: false,
            is_desktop: true,
        },
);
const consoleShellClass = computed(() => [
    'console-shell min-h-screen',
    `device-${device.value.type}`,
    `role-${activeRole.value}`,
    {
        'is-mobile': device.value.is_mobile,
        'is-tablet': device.value.is_tablet,
        'is-desktop': device.value.is_desktop,
    },
]);
const activeUserName = computed(
    () => session.value?.full_name || session.value?.username || 'Checking',
);
const userInitials = computed(() => {
    const parts = activeUserName.value.trim().split(/\s+/).slice(0, 2);
    const initials = parts
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');

    return initials || 'NL';
});
const pendingCashIns = computed(() =>
    transactions.value.filter(
        (transaction) => transaction.status === 'PENDING_CASHIER_CONFIRM',
    ),
);
const cashInReviewRows = computed(() => {
    const transaction = cashInReview.value;

    return {
        received: denominationRows(transaction?.received_denominations),
        change: denominationRows(transaction?.change_denominations),
        handoff: denominationRows(transaction?.handoff_denominations),
    };
});
const cashInReviewHandoffTotal = computed(() =>
    denominationTotal(cashInReviewRows.value.handoff),
);
const cashInReviewExpectedHandoff = computed(() => {
    const transaction = cashInReview.value;

    if (!transaction) {
        return 0;
    }

    const fee = transaction.fee_payment_method === 'cash'
        ? Number(transaction.customer_fee ?? 0)
        : 0;

    return Number(transaction.amount ?? 0) + fee;
});
const cashInReviewIsBalanced = computed(() =>
    cashInReviewHandoffTotal.value === cashInReviewExpectedHandoff.value,
);
const activeFloatCount = computed(
    () => cashFloats.value.filter((float) => float.status === 'ACTIVE').length,
);
const tellerFloatBalance = computed(() =>
    cashFloats.value
        .filter((float) => float.status === 'ACTIVE')
        .reduce((sum, float) => sum + Number(float.current_balance ?? 0), 0),
);
const pendingReceiptFloats = computed(() =>
    cashFloats.value.filter((float) => float.status === 'PENDING_RECEIPT'),
);
const openReturnFloats = computed(() =>
    cashFloats.value.filter(
        (float) => float.status === 'PENDING_RECONCILIATION',
    ),
);
const accountOptions = computed(() =>
    accounts.value.map((account) => ({
        id: account.id,
        label: `#${account.id} ${account.account_name}`,
    })),
);
const companyOptions = computed(() =>
    companies.value.map((company) => ({
        id: company.id,
        label: `#${company.id} ${company.name}`,
    })),
);
const userOptions = computed(() =>
    users.value.map((user) => ({
        id: user.id,
        label: `#${user.id} ${user.full_name || user.username}`,
    })),
);
const selectedUserIsCurrentSession = computed(
    () =>
        session.value !== null &&
        userForm.value.id === String(session.value.id),
);
const serviceTypeOptions = computed(() =>
    serviceTypes.value.map((serviceType) => ({
        id: serviceType.id,
        label: `#${serviceType.id} ${serviceType.name}`,
    })),
);
const currentRoleLabel = computed(() => roleLabel(activeRole.value));
const workspaceState = computed(() =>
    session.value ? 'Live API session' : 'Secure console',
);
const vaultTotal = computed(() => inventory.value?.main_vault_total ?? 0);
const tellerCashTotal = computed(
    () => inventory.value?.total_employee_cash ?? 0,
);
const grandPhysicalTotal = computed(
    () => inventory.value?.grand_physical_total ?? 0,
);
const menuSections = computed<ConsoleMenuSection[]>(() => {
    const mainItems: ConsoleMenuItem[] = [
        {
            id: 'dashboard',
            label: 'Dashboard',
            icon: 'layout',
        },
        {
            id: 'transactions',
            label: 'Transactions',
            icon: 'receipt',
            badge: transactions.value.length,
        },
        {
            id: 'activity-logs',
            label: 'Activity Logs',
            icon: 'activity',
            badge: activityLogs.value.length,
        },
        {
            id: 'cash-floats',
            label: 'Cash Floats',
            icon: 'coins',
            badge: cashFloats.value.length,
        },
    ];
    const roleItems: ConsoleMenuItem[] = [];
    const systemItems: ConsoleMenuItem[] = [
        {
            id: 'realtime',
            label: 'Realtime Events',
            icon: 'activity',
            badge: realtimeEvents.value.length,
        },
    ];

    if (activeRole.value === 'admin') {
        roleItems.push(
            {
                id: 'users',
                label: 'Staff Users',
                icon: 'users',
                badge: users.value.length,
            },
            {
                id: 'reports',
                label: 'Daily Reports',
                icon: 'chart',
                badge: reconciliationLogs.value.length,
            },
            {
                id: 'setup',
                label: 'Setup',
                icon: 'settings',
                badge: companies.value.length,
            },
            {
                id: 'commission-tiers',
                label: 'Commission Tiers',
                icon: 'settings',
                badge: commissionTiers.value.length,
            },
            {
                id: 'accounts',
                label: 'Accounts',
                icon: 'wallet',
                badge: accounts.value.length,
            },
            {
                id: 'vault',
                label: 'Vault Ops',
                icon: 'vault',
                badge: vaultLog.value.length,
            },
            {
                id: 'server-connection',
                label: 'Server Connection',
                icon: 'activity',
            },
        );
    }

    if (activeRole.value === 'cashier') {
        roleItems.push({
            id: 'cashier',
            label: 'Cashier Queue',
            icon: 'cashier',
            badge: pendingCashIns.value.length,
        });
    }

    if (activeRole.value === 'teller') {
        roleItems.push(
            {
                id: 'teller',
                label: 'Transaction Entry',
                icon: 'edit',
                children: tellerTransactionChildren,
            },
            {
                id: 'float-receipt',
                label: 'Float',
                icon: 'coins',
                badge: pendingReceiptFloats.value.length,
                children: tellerFloatChildren,
            },
        );
    }

    return [
        {
            label: 'Main',
            items: mainItems,
        },
        {
            label: 'Role',
            items: roleItems,
        },
        {
            label: 'System',
            items: systemItems,
        },
    ].filter((section) => section.items.length > 0);
});
function findPageLabel(page: ConsolePage): string {
    for (const section of menuSections.value) {
        for (const item of section.items) {
            if (item.id === page) {
                return item.label;
            }

            if (item.children) {
                const child = item.children.find((child) => child.id === page);

                if (child) {
                    return child.label;
                }
            }
        }
    }

    return 'Dashboard';
}

const transactionModeLabel = computed(
    () =>
        transactionModes.find((mode) => mode.id === transactionMode.value)
            ?.label ?? '',
);
const transactionScreenDescription = computed(() => {
    if (transactionMode.value === 'cash-in') {
        return 'Receive customer cash and submit a pending cash-in request.';
    }

    if (transactionMode.value === 'cash-out') {
        return 'Record a customer cash-out transaction from the selected account.';
    }

    if (transactionMode.value === 'transfer') {
        return 'Move funds between two accounts in a dedicated transfer screen.';
    }

    return 'Create a currency exchange transaction with the active rate.';
});
const currentPageLabel = computed(() => {
    const pageLabel = findPageLabel(activePage.value);

    return transactionPageIds.includes(activePage.value as TransactionMode)
        ? `Transaction Entry / ${pageLabel}`
        : pageLabel;
});
const ownerPageActive = computed(
    () =>
        activeRole.value === 'admin' &&
        ['users', 'reports', 'setup', 'commission-tiers', 'activity-logs', 'accounts', 'vault', 'server-connection'].includes(
            activePage.value,
        ),
);
const cashierPageActive = computed(
    () => activeRole.value === 'cashier' && activePage.value === 'cashier',
);
const tellerPageActive = computed(
    () =>
        activeRole.value === 'teller' &&
        transactionPageIds.includes(activePage.value as TransactionMode),
);
const floatReceiptPageActive = computed(
    () => activeRole.value === 'teller' && activePage.value === 'float-receipt',
);

watch(activePage, (page) => {
    if (transactionPageIds.includes(page as TransactionMode)) {
        transactionMode.value = page as TransactionMode;
    }
});

watch(activeRole, () => {
    if (activeRole.value === 'teller' && activePage.value === 'dashboard') {
        activePage.value = 'cash-in';
        menuGroupsOpen.value.teller = true;

        return;
    }

    if (!canOpenPage(activePage.value)) {
        activePage.value = 'dashboard';
    }
});

watch(cashInReview, (review) => {
    document.body.style.overflow = review ? 'hidden' : '';
});

onMounted(() => {
    webOrigin.value = window.location.origin;

    if (token.value) {
        void restoreSession();

        return;
    }

    redirectToLogin();
});

onBeforeUnmount(() => {
    disconnectRealtime();
    document.body.style.overflow = '';
});

async function restoreSession(): Promise<void> {
    loading.value = true;

    try {
        const response = await apiRequest<{ user: SessionUser }>(
            '/api/auth/me',
            {
                token: token.value,
            },
        );
        session.value = response.user;
        previewRole.value = response.user.role;
        connectRealtime();
        await refreshWorkspace();
        setNotice('ok', 'Session restored.');
    } catch {
        clearSession();
        redirectToLogin();
    } finally {
        loading.value = false;
    }
}

async function logout(): Promise<void> {
    if (token.value) {
        await apiRequest('/api/auth/logout', {
            method: 'POST',
            token: token.value,
        }).catch(() => undefined);
    }

    clearSession();
    clearWorkspace();
    redirectToLogin();
}

async function setPin(): Promise<void> {
    await runAction(async () => {
        await apiRequest('/api/auth/pin', {
            method: 'POST',
            token: token.value,
            body: {
                pin: pinForm.value.pin,
            },
        });
        pinForm.value.pin = '';
        userMenuOpen.value = false;
        setNotice('ok', 'PIN updated.');
    });
}

async function changePassword(): Promise<void> {
    await runAction(async () => {
        await apiRequest('/api/auth/password', {
            method: 'POST',
            token: token.value,
            body: { ...passwordForm.value },
        });
        passwordForm.value = {
            current_password: '',
            password: '',
            password_confirmation: '',
        };
        userMenuOpen.value = false;
        clearSession();
        setNotice('ok', 'Password updated. Please sign in again.');
        redirectToLogin();
    });
}

function resetTransactionForm(): void {
    transactionForm.value = {
        account_id: '',
        from_account_id: '',
        to_account_id: '',
        amount: '',
        customer_name: '',
        customer_phone: '',
        amount_received: '',
        currency: 'MMK',
        denominations: '{"10000": 1}',
        change_denominations: '{}',
        note: '',
    };
}

async function refreshWorkspace(): Promise<void> {
    if (!token.value) {
        clearWorkspace();

        return;
    }

    workspaceLoading.value = true;

    try {
        const [
            companyResponse,
            serviceResponse,
            accountResponse,
            transactionResponse,
            floatResponse,
            inventoryResponse,
            rateResponse,
        ] = await Promise.all([
            apiRequest<ApiCollection<Company>>('/api/companies', {
                token: token.value,
            }),
            apiRequest<ApiCollection<ServiceType>>('/api/service-types', {
                token: token.value,
            }),
            apiRequest<ApiCollection<Account>>('/api/accounts', {
                token: token.value,
            }),
            apiRequest<ApiCollection<Transaction>>('/api/transactions/recent', {
                token: token.value,
                query: { limit: 25 },
            }),
            apiRequest<ApiCollection<CashFloat>>('/api/cash-floats', {
                token: token.value,
            }),
            apiRequest<ApiItem<VaultInventory>>('/api/vault/inventory', {
                token: token.value,
            }),
            apiRequest<ApiItem<ExchangeRate>>('/api/exchange-rates/latest', {
                token: token.value,
                query: { base: 'THB', quote: 'MMK' },
            }),
        ]);
        companies.value = companyResponse.data;
        serviceTypes.value = serviceResponse.data;
        accounts.value = accountResponse.data;
        transactions.value = transactionResponse.data;
        cashFloats.value = floatResponse.data;
        inventory.value = inventoryResponse.data;
        latestRate.value = rateResponse.data;

        if (activeRole.value === 'admin') {
            const [
                userResponse,
                vaultLogResponse,
                activityLogResponse,
                systemStatusResponse,
                summaryResponse,
                reconciliationResponse,
            ] = await Promise.all([
                apiRequest<ApiCollection<ManagedUser>>('/api/users', {
                    token: token.value,
                    query: { include_inactive: true },
                }),
                apiRequest<ApiCollection<VaultTransaction>>('/api/vault/log', {
                    token: token.value,
                    query: { per_page: 12 },
                }),
                apiRequest<ApiCollection<ActivityLog>>('/api/activity-logs', {
                    token: token.value,
                    query: {
                        date: activityLogDate.value,
                        entity_type: activityLogEntity.value || undefined,
                        action: activityLogAction.value || undefined,
                        per_page: 200,
                    },
                }),
                apiRequest<{ name: string; domain: string; status: string }>('/api/system/status', {
                    token: token.value,
                }),
                apiRequest<ApiItem<DailySummaryReport>>(
                    '/api/reports/daily-summary',
                    {
                        token: token.value,
                        query: { date: reportForm.value.date },
                    },
                ),
                apiRequest<ApiCollection<DailyReconciliation>>(
                    '/api/reports/daily-reconciliations',
                    {
                        token: token.value,
                        query: { per_page: 5 },
                    },
                ),
            ]);
            users.value = userResponse.data;
            vaultLog.value = vaultLogResponse.data;
            activityLogs.value = activityLogResponse.data;
            systemStatus.value = systemStatusResponse;

            if (serviceTypes.value.length > 0) {
                commissionTierServiceTypeId.value = commissionTierServiceTypeId.value || String(serviceTypes.value[0].id);
                const tierResponse = await apiRequest<ApiCollection<CommissionTier>>('/api/commission-tiers', {
                    token: token.value,
                    query: { service_type_id: commissionTierServiceTypeId.value },
                });
                commissionTiers.value = tierResponse.data;
            }

            dailySummary.value = summaryResponse.data;
            reconciliationLogs.value = reconciliationResponse.data;
        } else {
            users.value = [];
            vaultLog.value = [];
            activityLogs.value = [];
            commissionTiers.value = [];
            systemStatus.value = null;
            dailySummary.value = null;
            reconciliationLogs.value = [];
        }
    } catch (error) {
        if (error instanceof ApiRequestError && error.status === 401) {
            clearSession();
        }

        setNotice('error', messageFromError(error));
    } finally {
        workspaceLoading.value = false;
    }
}

async function createUser(): Promise<void> {
    await runAction(async () => {
        await apiRequest('/api/users', {
            method: 'POST',
            token: token.value,
            body: userPayload(true),
        });
        resetUserForm();
        await refreshWorkspace();
        setNotice('ok', 'User created.');
    });
}

async function updateUser(): Promise<void> {
    await runAction(async () => {
        const userId = requiredNumber(userForm.value.id, 'User');
        await apiRequest(`/api/users/${userId}`, {
            method: 'PATCH',
            token: token.value,
            body: userPayload(false),
        });
        resetUserForm();
        await refreshWorkspace();
        setNotice('ok', 'User updated.');
    });
}

async function deactivateUser(): Promise<void> {
    await runAction(async () => {
        const userId = requiredNumber(userForm.value.id, 'User');
        await apiRequest(`/api/users/${userId}`, {
            method: 'DELETE',
            token: token.value,
        });
        resetUserForm();
        await refreshWorkspace();
        setNotice('ok', 'User deactivated.');
    });
}

async function refreshDailyReport(): Promise<void> {
    await runAction(async () => {
        const [summaryResponse, reconciliationResponse] = await Promise.all([
            apiRequest<ApiItem<DailySummaryReport>>(
                '/api/reports/daily-summary',
                {
                    token: token.value,
                    query: { date: reportForm.value.date },
                },
            ),
            apiRequest<ApiCollection<DailyReconciliation>>(
                '/api/reports/daily-reconciliations',
                {
                    token: token.value,
                    query: { per_page: 5 },
                },
            ),
        ]);
        dailySummary.value = summaryResponse.data;
        reconciliationLogs.value = reconciliationResponse.data;
        setNotice('ok', 'Daily report refreshed.');
    });
}

async function refreshActivityLogs(): Promise<void> {
    await runAction(async () => {
        const response = await apiRequest<ApiCollection<ActivityLog>>('/api/activity-logs', {
            token: token.value,
            query: {
                date: activityLogDate.value,
                entity_type: activityLogEntity.value || undefined,
                action: activityLogAction.value || undefined,
                per_page: 200,
            },
        });
        activityLogs.value = response.data;
        setNotice('ok', 'Activity logs refreshed.');
    });
}

async function loadCommissionTiers(): Promise<void> {
    if (!commissionTierServiceTypeId.value) {
        commissionTiers.value = [];
        resetTierForm();

        return;
    }

    const serviceTypeId = requiredNumber(commissionTierServiceTypeId.value, 'Service type');
    tierForm.value.service_type_id = commissionTierServiceTypeId.value;
    tierForm.value.id = '';
    const response = await apiRequest<ApiCollection<CommissionTier>>('/api/commission-tiers', {
        token: token.value,
        query: { service_type_id: serviceTypeId },
    });
    commissionTiers.value = response.data;
}

function selectTierForEdit(tier: CommissionTier): void {
    tierForm.value = {
        id: String(tier.id),
        service_type_id: String(tier.service_type_id),
        amount_from: String(tier.amount_from),
        amount_to: String(tier.amount_to),
        fee_amount_type: tier.fee_amount_type,
        fee_amount_deposit: String(tier.fee_amount_deposit),
        fee_amount_withdraw: String(tier.fee_amount_withdraw),
        comm_type: tier.comm_type,
        comm_deposit: String(tier.comm_deposit),
        comm_withdraw: String(tier.comm_withdraw),
        additional_fee_type: tier.additional_fee_type,
        additional_fee_deposit_amount: String(tier.additional_fee_deposit_amount),
        additional_fee_withdraw_amount: String(tier.additional_fee_withdraw_amount),
    };
    commissionTierServiceTypeId.value = String(tier.service_type_id);
}

function resetTierForm(): void {
    tierForm.value = {
        id: '',
        service_type_id: commissionTierServiceTypeId.value,
        amount_from: '',
        amount_to: '',
        fee_amount_type: 'FIXED',
        fee_amount_deposit: '0',
        fee_amount_withdraw: '0',
        comm_type: 'FIXED',
        comm_deposit: '0',
        comm_withdraw: '0',
        additional_fee_type: 'FIXED',
        additional_fee_deposit_amount: '0',
        additional_fee_withdraw_amount: '0',
    };
}

function tierPayload(): Record<string, unknown> {
    return {
        service_type_id: requiredNumber(tierForm.value.service_type_id || commissionTierServiceTypeId.value, 'Service type'),
        amount_from: requiredNumber(tierForm.value.amount_from, 'Amount from'),
        amount_to: requiredNumber(tierForm.value.amount_to, 'Amount to'),
        fee_amount_type: tierForm.value.fee_amount_type,
        fee_amount_deposit: optionalNumber(tierForm.value.fee_amount_deposit) ?? 0,
        fee_amount_withdraw: optionalNumber(tierForm.value.fee_amount_withdraw) ?? 0,
        comm_type: tierForm.value.comm_type,
        comm_deposit: optionalNumber(tierForm.value.comm_deposit) ?? 0,
        comm_withdraw: optionalNumber(tierForm.value.comm_withdraw) ?? 0,
        additional_fee_type: tierForm.value.additional_fee_type,
        additional_fee_deposit_amount: optionalNumber(tierForm.value.additional_fee_deposit_amount) ?? 0,
        additional_fee_withdraw_amount: optionalNumber(tierForm.value.additional_fee_withdraw_amount) ?? 0,
    };
}

async function saveCommissionTier(): Promise<void> {
    await runAction(async () => {
        const id = tierForm.value.id;
        await apiRequest(id ? `/api/commission-tiers/${id}` : '/api/commission-tiers', {
            method: id ? 'PATCH' : 'POST',
            token: token.value,
            body: tierPayload(),
        });
        resetTierForm();
        await loadCommissionTiers();
        setNotice('ok', id ? 'Commission tier updated.' : 'Commission tier created.');
    });
}

async function deleteCommissionTier(tierId: number): Promise<void> {
    await runAction(async () => {
        await apiRequest(`/api/commission-tiers/${tierId}`, {
            method: 'DELETE',
            token: token.value,
        });
        await loadCommissionTiers();
        setNotice('ok', 'Commission tier deleted.');
    });
}

async function closeDailyReconciliation(): Promise<void> {
    await runAction(async () => {
        await apiRequest('/api/reports/daily-reconciliation', {
            method: 'POST',
            token: token.value,
            body: {
                date: reportForm.value.date,
                notes: reportForm.value.notes || null,
            },
        });
        reportForm.value.notes = '';
        await refreshWorkspace();
        setNotice('ok', 'Daily reconciliation closed.');
    });
}

async function createCompany(): Promise<void> {
    await runAction(async () => {
        await apiRequest('/api/companies', {
            method: 'POST',
            token: token.value,
            body: {
                name: companyForm.value.name,
                category: companyForm.value.category,
            },
        });
        companyForm.value.name = '';
        await refreshWorkspace();
        setNotice('ok', 'Company created.');
    });
}

async function createServiceType(): Promise<void> {
    await runAction(async () => {
        await apiRequest('/api/service-types', {
            method: 'POST',
            token: token.value,
            body: {
                company_id: requiredNumber(
                    serviceForm.value.company_id,
                    'Company',
                ),
                name: serviceForm.value.name,
                operation: serviceForm.value.operation,
            },
        });
        serviceForm.value.name = '';
        await refreshWorkspace();
        setNotice('ok', 'Service type created.');
    });
}

async function createAccount(): Promise<void> {
    await runAction(async () => {
        await apiRequest('/api/accounts', {
            method: 'POST',
            token: token.value,
            body: {
                service_type_id: requiredNumber(
                    accountForm.value.service_type_id,
                    'Service type',
                ),
                account_name: accountForm.value.account_name,
                phone_number: accountForm.value.phone_number,
                balance: optionalNumber(accountForm.value.balance) ?? 0,
                commission_rate:
                    optionalNumber(accountForm.value.commission_rate) ?? 0,
                is_fee_account: accountForm.value.is_fee_account,
            },
        });
        accountForm.value.account_name = '';
        accountForm.value.phone_number = '';
        await refreshWorkspace();
        setNotice('ok', 'Account created.');
    });
}

async function createExchangeRate(): Promise<void> {
    await runAction(async () => {
        await apiRequest('/api/exchange-rates', {
            method: 'POST',
            token: token.value,
            body: {
                base_currency: rateForm.value.base_currency,
                quote_currency: rateForm.value.quote_currency,
                base_amount: requiredNumber(
                    rateForm.value.base_amount,
                    'Base amount',
                ),
                buy_rate: requiredNumber(rateForm.value.buy_rate, 'Buy rate'),
                sell_rate: requiredNumber(
                    rateForm.value.sell_rate,
                    'Sell rate',
                ),
            },
        });
        await refreshWorkspace();
        setNotice('ok', 'Exchange rate saved.');
    });
}

async function adjustBalance(): Promise<void> {
    await runAction(async () => {
        const accountId = requiredNumber(
            adjustForm.value.account_id,
            'Account',
        );
        await apiRequest(`/api/accounts/${accountId}/balance-adjust`, {
            method: 'POST',
            token: token.value,
            body: {
                amount: requiredNumber(adjustForm.value.amount, 'Amount'),
                remark: adjustForm.value.remark,
            },
        });
        adjustForm.value.amount = '';
        adjustForm.value.remark = '';
        await refreshWorkspace();
        setNotice('ok', 'Balance adjusted.');
    });
}

async function issueFloat(): Promise<void> {
    await runAction(async () => {
        await apiRequest('/api/cash-floats', {
            method: 'POST',
            token: token.value,
            body: {
                employee_id: requiredNumber(
                    floatIssueForm.value.employee_id,
                    'Teller',
                ),
                denominations: parseDenominations(
                    floatIssueForm.value.denominations,
                    true,
                ),
                note: floatIssueForm.value.note,
            },
        });
        await refreshWorkspace();
        setNotice('ok', 'Float issued.');
    });
}

async function confirmFloatReturn(): Promise<void> {
    await runAction(async () => {
        const floatId = requiredNumber(floatReturnForm.value.float_id, 'Float');
        await apiRequest(`/api/cash-floats/${floatId}/confirm-return`, {
            method: 'POST',
            token: token.value,
            body: {
                closing_total: requiredNumber(
                    floatReturnForm.value.closing_total,
                    'Closing total',
                ),
                pin: floatReturnForm.value.pin,
            },
        });
        floatReturnForm.value.pin = '';
        await refreshWorkspace();
        setNotice('ok', 'Float return confirmed.');
    });
}

async function activateFloat(): Promise<void> {
    await runAction(async () => {
        const floatId = requiredNumber(
            floatActivateForm.value.float_id,
            'Float',
        );
        await apiRequest(`/api/cash-floats/${floatId}/activate`, {
            method: 'POST',
            token: token.value,
            body: {
                pin: floatActivateForm.value.pin,
                verified_denominations: parseDenominations(
                    floatActivateForm.value.verified_denominations,
                    true,
                ),
            },
        });
        floatActivateForm.value.pin = '';
        await refreshWorkspace();
        setNotice('ok', 'Float activated.');
    });
}

async function initiateFloatReturn(): Promise<void> {
    await runAction(async () => {
        const floatId = requiredNumber(
            floatInitiateReturnForm.value.float_id,
            'Float',
        );
        await apiRequest(`/api/cash-floats/${floatId}/initiate-return`, {
            method: 'POST',
            token: token.value,
            body: {
                return_denominations: parseDenominations(
                    floatInitiateReturnForm.value.return_denominations,
                    true,
                ),
            },
        });
        await refreshWorkspace();
        setNotice('ok', 'Return initiated.');
    });
}

async function submitTransaction(): Promise<void> {
    await runAction(async () => {
        const body = transactionPayload();
        await apiRequest(`/api/transactions/${transactionMode.value}`, {
            method: 'POST',
            token: token.value,
            body,
        });
        await refreshWorkspace();
        setNotice('ok', 'Transaction created.');
    });
}

function denominationRows(map: DenominationMap | null | undefined): Array<{ denomination: number; quantity: number; total: number }> {
    return Object.entries(map ?? {})
        .map(([denomination, quantity]) => ({
            denomination: Number(denomination),
            quantity: Number(quantity),
            total: Number(denomination) * Number(quantity),
        }))
        .filter((row) => row.denomination > 0 && row.quantity > 0)
        .sort((a, b) => b.denomination - a.denomination);
}

function denominationTotal(rows: Array<{ total: number }>): number {
    return rows.reduce((sum, row) => sum + row.total, 0);
}

function openCashInReview(transaction: Transaction): void {
    cashInReview.value = transaction;
}

function closeCashInReview(): void {
    if (!loading.value) {
        cashInReview.value = null;
    }
}

async function confirmCashIn(transactionId: number): Promise<void> {
    await runAction(async () => {
        await apiRequest(`/api/transactions/${transactionId}/confirm-cash-in`, {
            method: 'POST',
            token: token.value,
        });
        cashInReview.value = null;
        await refreshWorkspace();
        setNotice('ok', 'Cash-in confirmed.');
    });
}

async function cancelCashIn(transactionId: number): Promise<void> {
    await runAction(async () => {
        await apiRequest(`/api/transactions/${transactionId}/cancel-cash-in`, {
            method: 'POST',
            token: token.value,
            body: { note: 'Cancelled from console' },
        });
        await refreshWorkspace();
        setNotice('ok', 'Cash-in cancelled.');
    });
}

async function sendBroadcastPing(): Promise<void> {
    await runAction(async () => {
        await apiRequest('/api/broadcast/test', {
            method: 'POST',
            token: token.value,
        });
        setNotice('ok', 'Ping dispatched.');
    });
}

function transactionPayload(): Record<string, unknown> {
    const amount = requiredNumber(transactionForm.value.amount, 'Amount');
    const denominationMap = parseDenominations(
        transactionForm.value.denominations,
        false,
    );

    if (transactionMode.value === 'cash-in') {
        const body: Record<string, unknown> = {
            account_id: requiredNumber(
                transactionForm.value.account_id,
                'Account',
            ),
            amount,
            customer_name: transactionForm.value.customer_name,
            customer_phone: transactionForm.value.customer_phone,
            note: transactionForm.value.note,
        };
        const amountReceived = optionalNumber(
            transactionForm.value.amount_received,
        );
        const changeMap = parseDenominations(
            transactionForm.value.change_denominations,
            false,
        );

        if (amountReceived !== undefined) {
            body.amount_received = amountReceived;
        }

        if (changeMap !== undefined) {
            body.change_denominations = changeMap;
        }

        return body;
    }

    if (transactionMode.value === 'transfer') {
        const body: Record<string, unknown> = {
            from_account_id: requiredNumber(
                transactionForm.value.from_account_id,
                'From account',
            ),
            to_account_id: requiredNumber(
                transactionForm.value.to_account_id,
                'To account',
            ),
            amount,
            note: transactionForm.value.note,
        };

        if (denominationMap !== undefined) {
            body.denominations = denominationMap;
        }

        return body;
    }

    const body: Record<string, unknown> = {
        account_id: requiredNumber(transactionForm.value.account_id, 'Account'),
        amount,
        note: transactionForm.value.note,
    };

    if (transactionMode.value === 'cash-out') {
        body.customer_name = transactionForm.value.customer_name;
        body.customer_phone = transactionForm.value.customer_phone;
    }

    if (transactionMode.value === 'exchange') {
        body.currency = transactionForm.value.currency;
    }

    if (denominationMap !== undefined) {
        body.denominations = denominationMap;
    }

    return body;
}

async function runAction(action: () => Promise<void>): Promise<void> {
    if (!token.value) {
        setNotice('warn', 'No API session.');

        return;
    }

    loading.value = true;

    try {
        await action();
    } catch (error) {
        setNotice('error', messageFromError(error));
    } finally {
        loading.value = false;
    }
}

function connectRealtime(): void {
    disconnectRealtime();

    if (!token.value || !session.value) {
        realtimeStatus.value = 'offline';

        return;
    }

    const echo = createNgweLweEcho(token.value);

    if (!echo) {
        realtimeStatus.value = 'not configured';

        return;
    }

    const handlers = {
        balance_update: (payload: RealtimePayload) =>
            pushRealtimeEvent('balance_update', payload),
        new_transaction: (payload: RealtimePayload) =>
            pushRealtimeEvent('new_transaction', payload),
        cash_in_pending: (payload: RealtimePayload) =>
            pushRealtimeEvent('cash_in_pending', payload),
        float_status_changed: (payload: RealtimePayload) =>
            pushRealtimeEvent('float_status_changed', payload),
        ping: (payload: RealtimePayload) => pushRealtimeEvent('ping', payload),
    };

    unsubscribeRole = subscribeToRoleChannel(
        echo,
        session.value.role,
        handlers,
    );

    if (session.value.role === 'teller') {
        unsubscribeUser = subscribeToUserChannel(
            echo,
            session.value.id,
            handlers,
        );
    }

    realtimeStatus.value = 'listening';
}

function disconnectRealtime(): void {
    unsubscribeRole?.();
    unsubscribeUser?.();
    unsubscribeRole = null;
    unsubscribeUser = null;
    disconnectNgweLweEcho();
}

function pushRealtimeEvent(
    event: RealtimeEventName,
    payload: RealtimePayload,
): void {
    eventCounter.value += 1;
    realtimeEvents.value = [
        {
            id: eventCounter.value,
            event,
            at: new Date().toLocaleTimeString(),
            payload,
        },
        ...realtimeEvents.value,
    ].slice(0, 8);

    void refreshWorkspace();
}

function parseDenominations(value: string, required: true): DenominationMap;
function parseDenominations(
    value: string,
    required: false,
): DenominationMap | undefined;
function parseDenominations(
    value: string,
    required: boolean,
): DenominationMap | undefined {
    const trimmed = value.trim();

    if (!trimmed) {
        if (required) {
            throw new Error('Denominations are required.');
        }

        return undefined;
    }

    const parsed = JSON.parse(trimmed) as unknown;

    if (
        parsed === null ||
        Array.isArray(parsed) ||
        typeof parsed !== 'object'
    ) {
        throw new Error('Denominations must be a JSON object.');
    }

    const map: DenominationMap = {};

    Object.entries(parsed as Record<string, unknown>).forEach(
        ([denomination, quantity]) => {
            const parsedQuantity = Number(quantity);

            if (!Number.isInteger(parsedQuantity) || parsedQuantity < 0) {
                throw new Error(`Invalid quantity for ${denomination} MMK.`);
            }

            map[denomination] = parsedQuantity;
        },
    );

    if (required && Object.keys(map).length === 0) {
        throw new Error('Denominations are required.');
    }

    return map;
}

function editUser(user: ManagedUser): void {
    userForm.value = {
        id: String(user.id),
        username: user.username,
        email: user.email ?? '',
        full_name: user.full_name ?? '',
        role: user.role,
        password: '',
        pin: '',
        is_active: user.is_active,
    };
}

function selectUserForEdit(): void {
    const selected = users.value.find(
        (user) => String(user.id) === userForm.value.id,
    );

    if (selected) {
        editUser(selected);
    }
}

function resetUserForm(): void {
    userForm.value = {
        id: '',
        username: '',
        email: '',
        full_name: '',
        role: 'teller',
        password: '',
        pin: '',
        is_active: true,
    };
}

function userPayload(requirePassword: boolean): Record<string, unknown> {
    const body: Record<string, unknown> = {
        username: userForm.value.username,
        email: userForm.value.email || null,
        full_name: userForm.value.full_name,
        role: userForm.value.role,
        is_active: userForm.value.is_active,
    };

    if (requirePassword || userForm.value.password !== '') {
        body.password = userForm.value.password;
    }

    if (userForm.value.pin !== '') {
        body.pin = userForm.value.pin;
    }

    return body;
}

function requiredNumber(value: string, label: string): number {
    const parsed = Number(value);

    if (!Number.isFinite(parsed) || value === '') {
        throw new Error(`${label} is required.`);
    }

    return parsed;
}

function optionalNumber(value: string): number | undefined {
    if (value === '') {
        return undefined;
    }

    const parsed = Number(value);

    if (!Number.isFinite(parsed)) {
        throw new Error('Invalid number.');
    }

    return parsed;
}

function formatMoney(value: string | number | null | undefined): string {
    const parsed = Number(value ?? 0);

    if (!Number.isFinite(parsed)) {
        return String(value ?? '0');
    }

    return new Intl.NumberFormat('en-US', {
        maximumFractionDigits: 2,
        minimumFractionDigits: 0,
    }).format(parsed);
}

function formatDate(value: string | null): string {
    if (!value) {
        return '-';
    }

    return new Date(value).toLocaleString();
}

function roleLabel(role: Role): string {
    return { admin: 'Admin', cashier: 'Cashier', teller: 'Teller' }[role];
}

function messageFromError(error: unknown): string {
    if (error instanceof ApiRequestError || error instanceof Error) {
        return error.message;
    }

    return 'Request failed.';
}

function setNotice(tone: NoticeTone, message: string): void {
    notice.value = { tone, message };
}

function clearSession(): void {
    disconnectRealtime();
    token.value = '';
    session.value = null;
    removeStoredToken();
    realtimeStatus.value = 'offline';
}

function clearWorkspace(): void {
    companies.value = [];
    users.value = [];
    serviceTypes.value = [];
    accounts.value = [];
    transactions.value = [];
    cashFloats.value = [];
    inventory.value = null;
    vaultLog.value = [];
    activityLogs.value = [];
    commissionTiers.value = [];
    systemStatus.value = null;
    dailySummary.value = null;
    reconciliationLogs.value = [];
    latestRate.value = null;
}

function todayDate(): string {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}

function canOpenPage(page: ConsolePage): boolean {
    return menuSections.value.some((section) =>
        section.items.some((item) =>
            item.id === page ||
            item.children?.some((child) => child.id === page),
        ),
    );
}

function selectPage(page: ConsolePage): void {
    if (page === 'teller') {
        menuGroupsOpen.value.teller = true;
        activePage.value = 'cash-in';

        return;
    }

    if (page === 'float-receipt') {
        menuGroupsOpen.value['float-receipt'] = true;
        activePage.value = 'float-receipt';

        return;
    }

    if (canOpenPage(page)) {
        activePage.value = page;
    }
}

function isMenuGroupOpen(id: ConsolePage): boolean {
    if (menuGroupsOpen.value[id] !== undefined) {
        return menuGroupsOpen.value[id];
    }

    const hasActiveChild =
        menuSections.value
            .find((section) => section.items.some((item) => item.id === id))
            ?.items.find((item) => item.id === id)
            ?.children?.some((child) => child.id === activePage.value) ?? false;

    return activePage.value === id || hasActiveChild;
}

function toggleMenuGroup(id: ConsolePage): void {
    menuGroupsOpen.value[id] = !isMenuGroupOpen(id);
}

function openSubMenuPage(_: ConsolePage, page: ConsolePage): void {
    if (transactionPageIds.includes(page as TransactionMode)) {
        transactionMode.value = page as TransactionMode;
    }

    selectPage(page);
}

function shouldShowMenuBadge(badge: ConsoleMenuItem['badge']): boolean {
    if (typeof badge === 'number') {
        return badge > 0;
    }

    return Boolean(badge);
}

function redirectToLogin(): void {
    if (typeof window !== 'undefined') {
        window.location.assign('/login');
    }
}
</script>

<template>
    <Head title="Operations Console" />

    <main v-if="!session" class="auth-redirect-shell" aria-live="polite">
        <section class="auth-redirect-card">
            <div class="brand-mark" aria-hidden="true">NL</div>
            <h1>Ngwe Lwe System</h1>
            <p>Opening secure console</p>
        </section>
    </main>

    <div
        v-else
        :class="consoleShellClass"
        :data-device="device.type"
        :data-device-view="device.view"
        :data-role="activeRole"
    >
        <header class="topbar">
            <div class="brand-lockup">
                <div class="brand-mark" aria-hidden="true">NL</div>
                <div>
                    <h1>Ngwe Lwe System</h1>
                    <p>{{ workspaceState }} / {{ currentRoleLabel }}</p>
                </div>
            </div>

            <div class="topbar-actions">
                <span class="status-pill" :data-status="realtimeStatus">
                    {{ realtimeStatus }}
                </span>
                <button
                    type="button"
                    class="ghost-button"
                    @click="refreshWorkspace"
                >
                    Refresh
                </button>
                <div class="user-menu">
                    <button
                        type="button"
                        class="user-menu-trigger"
                        aria-label="Account menu"
                        aria-haspopup="true"
                        :aria-expanded="userMenuOpen"
                        @click="userMenuOpen = !userMenuOpen"
                    >
                        <span class="avatar" aria-hidden="true">
                            {{ userInitials }}
                        </span>
                    </button>
                    <template v-if="userMenuOpen">
                        <div
                            class="user-menu-backdrop"
                            aria-hidden="true"
                            @click="userMenuOpen = false"
                        ></div>
                        <div class="user-menu-pop">
                            <div class="user-menu-head">
                                <span class="avatar" aria-hidden="true">
                                    {{ userInitials }}
                                </span>
                                <div>
                                    <strong>{{ activeUserName }}</strong>
                                    <span>
                                        #{{ session?.id }} /
                                        {{ currentRoleLabel }}
                                    </span>
                                </div>
                            </div>
                            <form
                                class="user-menu-pin"
                                @submit.prevent="setPin"
                            >
                                <label>
                                    Security PIN
                                    <input
                                        v-model="pinForm.pin"
                                        inputmode="numeric"
                                        type="password"
                                        autocomplete="new-password"
                                    />
                                </label>
                                <button
                                    type="submit"
                                    class="compact-button"
                                    :disabled="loading"
                                >
                                    Save PIN
                                </button>
                            </form>
                            <form class="user-menu-pin" @submit.prevent="changePassword">
                                <label>
                                    Current password
                                    <input v-model="passwordForm.current_password" type="password" autocomplete="current-password" required />
                                </label>
                                <label>
                                    New password
                                    <input v-model="passwordForm.password" type="password" autocomplete="new-password" minlength="8" required />
                                </label>
                                <label>
                                    Confirm password
                                    <input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" minlength="8" required />
                                </label>
                                <button type="submit" class="compact-button" :disabled="loading">Update password</button>
                            </form>
                            <button
                                type="button"
                                class="user-menu-logout"
                                @click="logout"
                            >
                                Logout
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </header>

        <main class="workspace">
            <aside class="side-rail">
                <div class="side-brand" aria-hidden="true">
                    <div class="brand-mark">NL</div>
                    <div>
                        <strong>Ngwe Lwe</strong>
                        <span>Operations</span>
                    </div>
                </div>

                <nav class="drawer-menu" aria-label="Console pages">
                    <section
                        v-for="section in menuSections"
                        :key="section.label"
                        class="drawer-menu-section"
                    >
                        <p>{{ section.label }}</p>
                        <template
                            v-for="item in section.items"
                            :key="item.id"
                        >
                            <button
                                v-if="!item.children"
                                type="button"
                                :class="{ active: activePage === item.id }"
                                @click="selectPage(item.id)"
                            >
                                <span class="drawer-menu-icon">
                                    <svg
                                        aria-hidden="true"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            v-for="path in menuIconPaths[
                                                item.icon
                                            ]"
                                            :key="path"
                                            :d="path"
                                        />
                                    </svg>
                                </span>
                                <span class="drawer-menu-label">
                                    {{ item.label }}
                                </span>
                                <strong v-if="shouldShowMenuBadge(item.badge)">
                                    {{ item.badge }}
                                </strong>
                            </button>
                            <div
                                v-else
                                class="drawer-menu-group"
                                :class="{
                                    open: isMenuGroupOpen(item.id),
                                    'group-active':
                                        activePage === item.id ||
                                        item.children?.some(
                                            (child) => child.id === activePage,
                                        ),
                                }"
                            >
                                <button
                                    type="button"
                                    class="drawer-menu-group-toggle"
                                    :aria-expanded="isMenuGroupOpen(item.id)"
                                    @click="toggleMenuGroup(item.id)"
                                >
                                    <span class="drawer-menu-icon">
                                        <svg
                                            aria-hidden="true"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                v-for="path in menuIconPaths[
                                                    item.icon
                                                ]"
                                                :key="path"
                                                :d="path"
                                            />
                                        </svg>
                                    </span>
                                    <span class="drawer-menu-label">
                                        {{ item.label }}
                                    </span>
                                    <strong
                                        v-if="shouldShowMenuBadge(item.badge)"
                                    >
                                        {{ item.badge }}
                                    </strong>
                                    <span
                                        class="drawer-menu-arrow"
                                        aria-hidden="true"
                                    >
                                        <svg fill="none" viewBox="0 0 24 24">
                                            <path d="m9 6 6 6-6 6" />
                                        </svg>
                                    </span>
                                </button>
                                <div
                                    v-if="isMenuGroupOpen(item.id)"
                                    class="drawer-menu-children"
                                >
                                    <button
                                        v-for="child in item.children"
                                        :key="child.id"
                                        type="button"
                                        :class="{ active: activePage === child.id }"
                                        @click="openSubMenuPage(item.id, child.id)"
                                    >
                                        <span
                                            class="drawer-menu-dot"
                                            aria-hidden="true"
                                        ></span>
                                        <span class="drawer-menu-label">
                                            {{ child.label }}
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </section>
                </nav>
            </aside>

            <section class="content-column" aria-live="polite">
                <section class="content-toolbar">
                    <div class="toolbar-title">
                        <span>Operations</span>
                        <strong>{{ currentPageLabel }}</strong>
                    </div>
                    <div class="toolbar-summary">
                        <span>{{ transactions.length }} transactions</span>
                        <span>{{ cashFloats.length }} floats</span>
                        <span>{{ accounts.length }} accounts</span>
                    </div>
                </section>

                <div v-if="notice" class="notice" :data-tone="notice.tone">
                    {{ notice.message }}
                </div>

                <section
                    v-if="activePage === 'realtime'"
                    class="panel event-feed page-event-feed"
                >
                    <div class="panel-heading">
                        <span>Realtime Events</span>
                        <strong>{{ realtimeStatus }}</strong>
                    </div>
                    <button
                        v-if="activeRole === 'admin'"
                        type="button"
                        class="secondary-button"
                        :disabled="loading || !session"
                        @click="sendBroadcastPing"
                    >
                        Dispatch Ping
                    </button>
                    <ol>
                        <li v-for="event in realtimeEvents" :key="event.id">
                            <span>{{ event.at }}</span>
                            <strong>{{ event.event }}</strong>
                        </li>
                    </ol>
                    <p v-if="realtimeEvents.length === 0" class="empty-line">
                        No realtime events.
                    </p>
                </section>

                <section
                    v-if="activePage === 'dashboard'"
                    class="metric-grid"
                    aria-label="Operations summary"
                >
                    <article class="metric">
                        <span>Main Vault</span>
                        <strong>{{ formatMoney(vaultTotal) }}</strong>
                        <small>MMK</small>
                    </article>
                    <article class="metric">
                        <span>Teller Cash</span>
                        <strong>{{ formatMoney(tellerCashTotal) }}</strong>
                        <small>MMK</small>
                    </article>
                    <article class="metric">
                        <span>Physical Total</span>
                        <strong>{{ formatMoney(grandPhysicalTotal) }}</strong>
                        <small>MMK</small>
                    </article>
                    <article class="metric">
                        <span>Pending Cash In</span>
                        <strong>{{ pendingCashIns.length }}</strong>
                        <small>{{ activeFloatCount }} active floats</small>
                    </article>
                </section>

                <section
                    v-if="
                        activePage === 'dashboard' ||
                        activePage === 'transactions'
                    "
                    class="panel data-panel"
                >
                    <div class="panel-heading">
                        <span>Recent Transactions</span>
                        <strong>{{ transactions.length }}</strong>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Amount</th>
                                    <th>Customer</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="transaction in transactions"
                                    :key="transaction.id"
                                >
                                    <td>#{{ transaction.id }}</td>
                                    <td>{{ transaction.transaction_type }}</td>
                                    <td>
                                        <span class="row-status">
                                            {{ transaction.status }}
                                        </span>
                                    </td>
                                    <td>
                                        {{ formatMoney(transaction.amount) }}
                                    </td>
                                    <td>
                                        {{ transaction.customer_name || '-' }}
                                    </td>
                                    <td>
                                        {{ formatDate(transaction.created_at) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="transactions.length === 0" class="empty-line">
                        No transactions.
                    </p>
                </section>

                <section
                    v-if="activePage === 'activity-logs' && activeRole === 'admin'"
                    class="panel data-panel"
                >
                    <div class="panel-heading">
                        <span>Activity Logs</span>
                        <strong>{{ activityLogs.length }}</strong>
                    </div>
                    <form class="form-grid three" @submit.prevent="refreshActivityLogs">
                        <label>
                            Date
                            <input v-model="activityLogDate" type="date" />
                        </label>
                        <label>
                            Entity
                            <select v-model="activityLogEntity">
                                <option value="">All entities</option>
                                <option value="accounts">Accounts</option>
                                <option value="transactions">Transactions</option>
                                <option value="users">Users</option>
                                <option value="companies">Companies</option>
                                <option value="service_types">Service types</option>
                                <option value="commission_tiers">Commission tiers</option>
                            </select>
                        </label>
                        <label>
                            Action contains
                            <input v-model="activityLogAction" placeholder="transaction_created" />
                        </label>
                        <button type="submit" class="primary-button" :disabled="loading">Load logs</button>
                    </form>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr><th>ID</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>Created</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="log in activityLogs" :key="log.id">
                                    <td>#{{ log.id }}</td>
                                    <td>{{ log.user?.full_name || log.user?.username || log.user_id }}</td>
                                    <td>{{ log.action }}</td>
                                    <td>{{ log.entity_type }} #{{ log.entity_id ?? '-' }}</td>
                                    <td>{{ typeof log.details === 'string' ? log.details : JSON.stringify(log.details || {}) }}</td>
                                    <td>{{ formatDate(log.created_at) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="activityLogs.length === 0" class="empty-line">No activity logs for this filter.</p>
                </section>

                <section v-if="activePage === 'server-connection' && activeRole === 'admin'" class="panel data-panel">
                    <div class="panel-heading">
                        <span>Server Connection</span>
                        <strong>{{ systemStatus?.status || 'unknown' }}</strong>
                    </div>
                    <dl class="summary-list">
                        <div><dt>System</dt><dd>{{ systemStatus?.name || 'Ngwe Lwe System' }}</dd></div>
                        <div><dt>Domain</dt><dd>{{ systemStatus?.domain || 'money-transfer' }}</dd></div>
                        <div><dt>Web endpoint</dt><dd>{{ webOrigin || '-' }}</dd></div>
                        <div><dt>Realtime</dt><dd>{{ realtimeStatus }}</dd></div>
                    </dl>
                    <button type="button" class="secondary-button" :disabled="workspaceLoading" @click="refreshWorkspace">Check connection</button>
                </section>

                <section v-if="ownerPageActive" class="role-grid">
                    <section
                        v-if="activePage === 'users'"
                        class="panel data-panel"
                    >
                        <div class="panel-heading">
                            <span>Staff Users</span>
                            <strong>{{ users.length }}</strong>
                        </div>
                        <form
                            class="form-grid three"
                            @submit.prevent="
                                userForm.id ? updateUser() : createUser()
                            "
                        >
                            <label>
                                User
                                <select
                                    v-model="userForm.id"
                                    @change="selectUserForEdit"
                                >
                                    <option value=""></option>
                                    <option
                                        v-for="user in userOptions"
                                        :key="user.id"
                                        :value="user.id"
                                    >
                                        {{ user.label }}
                                    </option>
                                </select>
                            </label>
                            <label>
                                Username
                                <input
                                    v-model="userForm.username"
                                    autocomplete="off"
                                />
                            </label>
                            <label>
                                Full name
                                <input v-model="userForm.full_name" />
                            </label>
                            <label>
                                Email
                                <input
                                    v-model="userForm.email"
                                    autocomplete="off"
                                />
                            </label>
                            <label>
                                Role
                                <select v-model="userForm.role">
                                    <option value="admin">Admin</option>
                                    <option value="cashier">Cashier</option>
                                    <option value="teller">Teller</option>
                                </select>
                            </label>
                            <label>
                                Password
                                <input
                                    v-model="userForm.password"
                                    autocomplete="new-password"
                                    type="password"
                                />
                            </label>
                            <label>
                                PIN
                                <input
                                    v-model="userForm.pin"
                                    autocomplete="off"
                                    inputmode="numeric"
                                    type="password"
                                />
                            </label>
                            <label class="check-row">
                                <input
                                    v-model="userForm.is_active"
                                    :disabled="selectedUserIsCurrentSession"
                                    type="checkbox"
                                />
                                Active
                            </label>
                            <div class="button-cell">
                                <button
                                    type="submit"
                                    class="primary-button"
                                    :disabled="loading"
                                >
                                    {{
                                        userForm.id
                                            ? 'Update User'
                                            : 'Create User'
                                    }}
                                </button>
                                <button
                                    type="button"
                                    class="secondary-button"
                                    :disabled="
                                        loading ||
                                        !userForm.id ||
                                        selectedUserIsCurrentSession
                                    "
                                    @click="deactivateUser"
                                >
                                    Deactivate
                                </button>
                                <button
                                    type="button"
                                    class="ghost-button"
                                    :disabled="loading"
                                    @click="resetUserForm"
                                >
                                    Clear
                                </button>
                            </div>
                        </form>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>PIN</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="user in users" :key="user.id">
                                        <td>
                                            <strong>
                                                {{
                                                    user.full_name ||
                                                    user.username
                                                }}
                                            </strong>
                                            <small>{{ user.username }}</small>
                                        </td>
                                        <td>{{ roleLabel(user.role) }}</td>
                                        <td>
                                            <span class="row-status">
                                                {{
                                                    user.is_active
                                                        ? 'ACTIVE'
                                                        : 'INACTIVE'
                                                }}
                                            </span>
                                        </td>
                                        <td>
                                            {{ user.has_pin ? 'Set' : '-' }}
                                        </td>
                                        <td class="button-cell">
                                            <button
                                                type="button"
                                                class="ghost-button"
                                                :disabled="loading"
                                                @click="editUser(user)"
                                            >
                                                Edit
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section
                        v-if="activePage === 'reports'"
                        class="panel data-panel"
                    >
                        <div class="panel-heading">
                            <span>Daily Report</span>
                            <strong>
                                {{
                                    dailySummary?.summary_date ||
                                    reportForm.date
                                }}
                            </strong>
                        </div>
                        <form
                            class="form-grid three"
                            @submit.prevent="refreshDailyReport"
                        >
                            <label>
                                Date
                                <input v-model="reportForm.date" type="date" />
                            </label>
                            <label>
                                Close note
                                <textarea v-model="reportForm.notes"></textarea>
                            </label>
                            <div class="button-cell">
                                <button
                                    type="submit"
                                    class="secondary-button"
                                    :disabled="loading"
                                >
                                    Refresh
                                </button>
                                <button
                                    type="button"
                                    class="primary-button"
                                    :disabled="loading || !dailySummary"
                                    @click="closeDailyReconciliation"
                                >
                                    Close Day
                                </button>
                            </div>
                        </form>
                        <div v-if="dailySummary" class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Cash In</th>
                                        <th>Cash Out</th>
                                        <th>Transfer</th>
                                        <th>Exchange</th>
                                        <th>Fees</th>
                                        <th>Profit</th>
                                        <th>Cash</th>
                                        <th>Digital</th>
                                        <th>Grand</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            {{
                                                formatMoney(
                                                    dailySummary.total_cash_in,
                                                )
                                            }}
                                        </td>
                                        <td>
                                            {{
                                                formatMoney(
                                                    dailySummary.total_cash_out,
                                                )
                                            }}
                                        </td>
                                        <td>
                                            {{
                                                formatMoney(
                                                    dailySummary.total_transfer,
                                                )
                                            }}
                                        </td>
                                        <td>
                                            {{
                                                formatMoney(
                                                    dailySummary.total_exchange,
                                                )
                                            }}
                                        </td>
                                        <td>
                                            {{
                                                formatMoney(
                                                    dailySummary.total_customer_fees,
                                                )
                                            }}
                                        </td>
                                        <td>
                                            {{
                                                formatMoney(
                                                    dailySummary.total_profit,
                                                )
                                            }}
                                        </td>
                                        <td>
                                            {{
                                                formatMoney(
                                                    dailySummary.total_cash,
                                                )
                                            }}
                                        </td>
                                        <td>
                                            {{
                                                formatMoney(
                                                    dailySummary.total_digital,
                                                )
                                            }}
                                        </td>
                                        <td>
                                            {{
                                                formatMoney(
                                                    dailySummary.grand_total,
                                                )
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="dailySummary" class="empty-line">
                            {{ dailySummary.transaction_count }} completed,
                            {{ dailySummary.pending_cash_in_count }} pending
                            cash in.
                        </p>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Closed By</th>
                                        <th>Closed At</th>
                                        <th>Cash</th>
                                        <th>Digital</th>
                                        <th>Grand</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="log in reconciliationLogs"
                                        :key="log.id"
                                    >
                                        <td>{{ log.recon_date }}</td>
                                        <td>
                                            {{
                                                log.closed_by_name ||
                                                log.closed_by
                                            }}
                                        </td>
                                        <td>{{ formatDate(log.closed_at) }}</td>
                                        <td>
                                            {{ formatMoney(log.total_cash) }}
                                        </td>
                                        <td>
                                            {{ formatMoney(log.total_digital) }}
                                        </td>
                                        <td>
                                            {{ formatMoney(log.grand_total) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p
                            v-if="reconciliationLogs.length === 0"
                            class="empty-line"
                        >
                            No reconciliation logs.
                        </p>
                    </section>

                    <section v-if="activePage === 'setup'" class="panel">
                        <div class="panel-heading">
                            <span>Setup</span>
                            <strong>{{ companies.length }} companies</strong>
                        </div>
                        <form
                            class="form-grid two"
                            @submit.prevent="createCompany"
                        >
                            <label>
                                Company
                                <input v-model="companyForm.name" />
                            </label>
                            <label>
                                Category
                                <select v-model="companyForm.category">
                                    <option>Pay</option>
                                    <option>Bank</option>
                                    <option>Both</option>
                                </select>
                            </label>
                            <button
                                type="submit"
                                class="primary-button"
                                :disabled="loading"
                            >
                                Create Company
                            </button>
                        </form>

                        <form
                            class="form-grid three"
                            @submit.prevent="createServiceType"
                        >
                            <label>
                                Company
                                <select v-model="serviceForm.company_id">
                                    <option value=""></option>
                                    <option
                                        v-for="company in companyOptions"
                                        :key="company.id"
                                        :value="company.id"
                                    >
                                        {{ company.label }}
                                    </option>
                                </select>
                            </label>
                            <label>
                                Service
                                <input v-model="serviceForm.name" />
                            </label>
                            <label>
                                Operation
                                <select v-model="serviceForm.operation">
                                    <option>CashIn</option>
                                    <option>CashOut</option>
                                    <option>Transfer</option>
                                    <option>Exchange</option>
                                    <option>All</option>
                                </select>
                            </label>
                            <button
                                type="submit"
                                class="secondary-button"
                                :disabled="loading"
                            >
                                Create Service
                            </button>
                        </form>
                    </section>

                    <section v-if="activePage === 'commission-tiers'" class="panel">
                        <div class="panel-heading">
                            <span>Commission Tiers</span>
                            <strong>{{ commissionTiers.length }}</strong>
                        </div>
                        <form class="form-grid three" @submit.prevent="saveCommissionTier">
                            <label>
                                Service type
                                <select v-model="commissionTierServiceTypeId" @change="loadCommissionTiers">
                                    <option value=""></option>
                                    <option v-for="serviceType in serviceTypes" :key="serviceType.id" :value="serviceType.id">
                                        {{ serviceType.company?.name || 'Company' }} / {{ serviceType.name }}
                                    </option>
                                </select>
                            </label>
                            <label>From <input v-model="tierForm.amount_from" inputmode="decimal" /></label>
                            <label>To <input v-model="tierForm.amount_to" inputmode="decimal" /></label>
                            <label>Fee type
                                <select v-model="tierForm.fee_amount_type"><option>FIXED</option><option>PERCENTAGE</option></select>
                            </label>
                            <label>Cash In fee <input v-model="tierForm.fee_amount_deposit" inputmode="decimal" /></label>
                            <label>Cash Out fee <input v-model="tierForm.fee_amount_withdraw" inputmode="decimal" /></label>
                            <label>Commission type
                                <select v-model="tierForm.comm_type"><option>FIXED</option><option>PERCENTAGE</option></select>
                            </label>
                            <label>Cash In commission <input v-model="tierForm.comm_deposit" inputmode="decimal" /></label>
                            <label>Cash Out commission <input v-model="tierForm.comm_withdraw" inputmode="decimal" /></label>
                            <label>Additional fee type
                                <select v-model="tierForm.additional_fee_type"><option>FIXED</option><option>PERCENTAGE</option></select>
                            </label>
                            <label>Cash In additional <input v-model="tierForm.additional_fee_deposit_amount" inputmode="decimal" /></label>
                            <label>Cash Out additional <input v-model="tierForm.additional_fee_withdraw_amount" inputmode="decimal" /></label>
                            <div class="button-cell">
                                <button type="submit" class="primary-button" :disabled="loading || !commissionTierServiceTypeId">{{ tierForm.id ? 'Update tier' : 'Create tier' }}</button>
                                <button type="button" class="ghost-button" :disabled="loading" @click="resetTierForm">Clear</button>
                            </div>
                        </form>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Range</th><th>Cash In fee</th><th>Cash Out fee</th><th>Cash In comm.</th><th>Cash Out comm.</th><th>Action</th></tr></thead>
                                <tbody>
                                    <tr v-for="tier in commissionTiers" :key="tier.id">
                                        <td>{{ formatMoney(tier.amount_from) }} – {{ formatMoney(tier.amount_to) }}</td>
                                        <td>{{ formatMoney(tier.fee_amount_deposit) }} {{ tier.fee_amount_type === 'PERCENTAGE' ? '%' : 'MMK' }}</td>
                                        <td>{{ formatMoney(tier.fee_amount_withdraw) }} {{ tier.fee_amount_type === 'PERCENTAGE' ? '%' : 'MMK' }}</td>
                                        <td>{{ formatMoney(tier.comm_deposit) }} {{ tier.comm_type === 'PERCENTAGE' ? '%' : 'MMK' }}</td>
                                        <td>{{ formatMoney(tier.comm_withdraw) }} {{ tier.comm_type === 'PERCENTAGE' ? '%' : 'MMK' }}</td>
                                        <td class="button-cell"><button type="button" class="ghost-button" @click="selectTierForEdit(tier)">Edit</button><button type="button" class="ghost-button danger" @click="deleteCommissionTier(tier.id)">Delete</button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="commissionTiers.length === 0" class="empty-line">No commission tiers for this service type.</p>
                    </section>

                    <section v-if="activePage === 'accounts'" class="panel">
                        <div class="panel-heading">
                            <span>Accounts</span>
                            <strong>{{ accounts.length }}</strong>
                        </div>
                        <form
                            class="form-grid three"
                            @submit.prevent="createAccount"
                        >
                            <label>
                                Service
                                <select v-model="accountForm.service_type_id">
                                    <option value=""></option>
                                    <option
                                        v-for="serviceType in serviceTypeOptions"
                                        :key="serviceType.id"
                                        :value="serviceType.id"
                                    >
                                        {{ serviceType.label }}
                                    </option>
                                </select>
                            </label>
                            <label>
                                Account
                                <input v-model="accountForm.account_name" />
                            </label>
                            <label>
                                Phone
                                <input v-model="accountForm.phone_number" />
                            </label>
                            <label>
                                Balance
                                <input
                                    v-model="accountForm.balance"
                                    inputmode="decimal"
                                />
                            </label>
                            <label>
                                Commission
                                <input
                                    v-model="accountForm.commission_rate"
                                    inputmode="decimal"
                                />
                            </label>
                            <label class="check-row">
                                <input
                                    v-model="accountForm.is_fee_account"
                                    type="checkbox"
                                />
                                Fee account
                            </label>
                            <button
                                type="submit"
                                class="primary-button"
                                :disabled="loading"
                            >
                                Create Account
                            </button>
                        </form>
                    </section>

                    <section v-if="activePage === 'vault'" class="panel">
                        <div class="panel-heading">
                            <span>Admin Operations</span>
                            <strong>Vault</strong>
                        </div>
                        <form
                            class="form-grid three"
                            @submit.prevent="adjustBalance"
                        >
                            <label>
                                Account
                                <select v-model="adjustForm.account_id">
                                    <option value=""></option>
                                    <option
                                        v-for="account in accountOptions"
                                        :key="account.id"
                                        :value="account.id"
                                    >
                                        {{ account.label }}
                                    </option>
                                </select>
                            </label>
                            <label>
                                Amount
                                <input
                                    v-model="adjustForm.amount"
                                    inputmode="decimal"
                                />
                            </label>
                            <label>
                                Remark
                                <input v-model="adjustForm.remark" />
                            </label>
                            <button
                                type="submit"
                                class="secondary-button"
                                :disabled="loading"
                            >
                                Adjust Balance
                            </button>
                        </form>

                        <form
                            class="form-grid three"
                            @submit.prevent="createExchangeRate"
                        >
                            <label>
                                Base
                                <input v-model="rateForm.base_currency" />
                            </label>
                            <label>
                                Buy
                                <input
                                    v-model="rateForm.buy_rate"
                                    inputmode="decimal"
                                />
                            </label>
                            <label>
                                Sell
                                <input
                                    v-model="rateForm.sell_rate"
                                    inputmode="decimal"
                                />
                            </label>
                            <button
                                type="submit"
                                class="secondary-button"
                                :disabled="loading"
                            >
                                Save Rate
                            </button>
                        </form>
                    </section>

                    <section
                        v-if="activePage === 'vault'"
                        class="panel data-panel"
                    >
                        <div class="panel-heading">
                            <span>Vault Log</span>
                            <strong>{{ vaultLog.length }}</strong>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Float</th>
                                        <th>Note</th>
                                        <th>Qty</th>
                                        <th>By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in vaultLog" :key="row.id">
                                        <td>{{ row.txn_type }}</td>
                                        <td>
                                            {{
                                                row.float_id
                                                    ? `#${row.float_id}`
                                                    : '-'
                                            }}
                                        </td>
                                        <td>{{ row.denomination }}</td>
                                        <td>{{ row.quantity }}</td>
                                        <td>
                                            {{ row.performed_by_name || '-' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </section>

                <section v-if="cashierPageActive" class="role-grid">
                    <section class="panel data-panel">
                        <div class="panel-heading">
                            <span>Pending Cash In</span>
                            <strong>{{ pendingCashIns.length }}</strong>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Amount</th>
                                        <th>Customer</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="transaction in pendingCashIns"
                                        :key="transaction.id"
                                    >
                                        <td>#{{ transaction.id }}</td>
                                        <td>
                                            {{
                                                formatMoney(transaction.amount)
                                            }}
                                        </td>
                                        <td>
                                            {{
                                                transaction.customer_name || '-'
                                            }}
                                        </td>
                                        <td class="button-cell">
                                            <button
                                                type="button"
                                                class="compact-button"
                                                @click="openCashInReview(transaction)"
                                            >
                                                Review & confirm
                                            </button>
                                            <button
                                                type="button"
                                                class="compact-button danger"
                                                @click="
                                                    cancelCashIn(transaction.id)
                                                "
                                            >
                                                Cancel
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="panel">
                        <div class="panel-heading">
                            <span>Issue Float</span>
                            <strong>{{ cashFloats.length }} floats</strong>
                        </div>
                        <form
                            class="form-grid two"
                            @submit.prevent="issueFloat"
                        >
                            <label>
                                Teller ID
                                <input
                                    v-model="floatIssueForm.employee_id"
                                    inputmode="numeric"
                                />
                            </label>
                            <label>
                                Note
                                <input v-model="floatIssueForm.note" />
                            </label>
                            <label class="wide-field">
                                Denominations
                                <textarea
                                    v-model="floatIssueForm.denominations"
                                    rows="4"
                                />
                            </label>
                            <button
                                type="submit"
                                class="primary-button"
                                :disabled="loading"
                            >
                                Issue Float
                            </button>
                        </form>
                    </section>

                    <section class="panel">
                        <div class="panel-heading">
                            <span>Return Confirmation</span>
                            <strong
                                >{{ openReturnFloats.length }} pending</strong
                            >
                        </div>
                        <form
                            class="form-grid three"
                            @submit.prevent="confirmFloatReturn"
                        >
                            <label>
                                Float ID
                                <input
                                    v-model="floatReturnForm.float_id"
                                    inputmode="numeric"
                                />
                            </label>
                            <label>
                                Closing Total
                                <input
                                    v-model="floatReturnForm.closing_total"
                                    inputmode="decimal"
                                />
                            </label>
                            <label>
                                PIN
                                <input
                                    v-model="floatReturnForm.pin"
                                    inputmode="numeric"
                                    type="password"
                                />
                            </label>
                            <button
                                type="submit"
                                class="secondary-button"
                                :disabled="loading"
                            >
                                Confirm Return
                            </button>
                        </form>
                    </section>
                </section>

                <section
                    v-if="tellerPageActive"
                    class="role-grid teller-grid"
                >
                    <section class="panel entry-title-panel bank-hero">
                        <div class="bank-hero-balance">
                            <span>Available Float Balance</span>
                            <strong>
                                {{ formatMoney(tellerFloatBalance) }}
                                <small>MMK</small>
                            </strong>
                            <p>{{ activeFloatCount }} active floats</p>
                        </div>
                        <div class="bank-hero-panel">
                            <div class="bank-hero-heading">
                                <span>
                                    {{ transactionModeLabel }} Application
                                </span>
                                <p>{{ transactionScreenDescription }}</p>
                            </div>
                            <div
                                class="bank-quick-actions"
                                role="tablist"
                                aria-label="Transaction mode"
                            >
                                <button
                                    v-for="mode in transactionModes"
                                    :key="mode.id"
                                    type="button"
                                    :class="{
                                        active: transactionMode === mode.id,
                                    }"
                                    @click="transactionMode = mode.id"
                                >
                                    {{ mode.label }}
                                </button>
                            </div>
                        </div>
                    </section>

                    <section class="panel entry-form-panel">
                        <form
                            class="form-grid three"
                            @submit.prevent="submitTransaction"
                        >
                            <label v-if="transactionMode !== 'transfer'">
                                Account
                                <select v-model="transactionForm.account_id">
                                    <option value=""></option>
                                    <option
                                        v-for="account in accountOptions"
                                        :key="account.id"
                                        :value="account.id"
                                    >
                                        {{ account.label }}
                                    </option>
                                </select>
                            </label>
                            <label v-if="transactionMode === 'transfer'">
                                From
                                <select
                                    v-model="transactionForm.from_account_id"
                                >
                                    <option value=""></option>
                                    <option
                                        v-for="account in accountOptions"
                                        :key="account.id"
                                        :value="account.id"
                                    >
                                        {{ account.label }}
                                    </option>
                                </select>
                            </label>
                            <label v-if="transactionMode === 'transfer'">
                                To
                                <select v-model="transactionForm.to_account_id">
                                    <option value=""></option>
                                    <option
                                        v-for="account in accountOptions"
                                        :key="account.id"
                                        :value="account.id"
                                    >
                                        {{ account.label }}
                                    </option>
                                </select>
                            </label>
                            <label>
                                Amount
                                <input
                                    v-model="transactionForm.amount"
                                    inputmode="decimal"
                                />
                            </label>
                            <label v-if="transactionMode === 'exchange'">
                                Currency
                                <select v-model="transactionForm.currency">
                                    <option>MMK</option>
                                    <option>THB</option>
                                </select>
                            </label>
                            <label
                                v-if="
                                    transactionMode === 'cash-in' ||
                                    transactionMode === 'cash-out'
                                "
                            >
                                Customer
                                <input
                                    v-model="transactionForm.customer_name"
                                />
                            </label>
                            <label
                                v-if="
                                    transactionMode === 'cash-in' ||
                                    transactionMode === 'cash-out'
                                "
                            >
                                Phone
                                <input
                                    v-model="transactionForm.customer_phone"
                                />
                            </label>
                            <label v-if="transactionMode === 'cash-in'">
                                Received
                                <input
                                    v-model="transactionForm.amount_received"
                                    inputmode="decimal"
                                />
                            </label>
                            <hr class="form-divider" aria-hidden="true" />
                            <label class="wide-field">
                                Denominations
                                <textarea
                                    v-model="transactionForm.denominations"
                                    rows="3"
                                />
                            </label>
                            <label
                                v-if="transactionMode === 'cash-in'"
                                class="wide-field"
                            >
                                Change Denominations
                                <textarea
                                    v-model="
                                        transactionForm.change_denominations
                                    "
                                    rows="3"
                                />
                            </label>
                            <div class="form-actions">
                                <button
                                    type="submit"
                                    class="primary-button"
                                    :disabled="loading"
                                >
                                    Submit
                                </button>
                                <button
                                    type="button"
                                    class="ghost-button"
                                    :disabled="loading"
                                    @click="resetTransactionForm"
                                >
                                    Reset
                                </button>
                            </div>
                        </form>
                    </section>

                    <section class="panel entry-return-panel">
                        <div class="panel-heading">
                            <span>Float Return</span>
                            <strong>{{ activeFloatCount }} active</strong>
                        </div>
                        <form
                            class="form-grid two"
                            @submit.prevent="initiateFloatReturn"
                        >
                            <label>
                                Float ID
                                <input
                                    v-model="floatInitiateReturnForm.float_id"
                                    inputmode="numeric"
                                />
                            </label>
                            <label class="wide-field">
                                Return Denominations
                                <textarea
                                    v-model="
                                        floatInitiateReturnForm.return_denominations
                                    "
                                    rows="4"
                                />
                            </label>
                            <button
                                type="submit"
                                class="secondary-button"
                                :disabled="loading"
                            >
                                Initiate Return
                            </button>
                        </form>
                    </section>

                    <section class="panel entry-floats-panel">
                        <div class="panel-heading">
                            <span>Float Status</span>
                            <strong>{{ cashFloats.length }} total</strong>
                        </div>
                        <div class="denom-grid">
                            <div>
                                <span>Active</span>
                                <strong>{{ activeFloatCount }}</strong>
                            </div>
                            <div>
                                <span>Pending Receipt</span>
                                <strong>
                                    {{ pendingReceiptFloats.length }}
                                </strong>
                            </div>
                            <div>
                                <span>Pending Reconciliation</span>
                                <strong>{{ openReturnFloats.length }}</strong>
                            </div>
                        </div>
                    </section>
                </section>

                <section v-if="floatReceiptPageActive" class="role-grid">
                    <section class="panel">
                        <div class="panel-heading">
                            <span>Float Receipt</span>
                            <strong
                                >{{
                                    pendingReceiptFloats.length
                                }}
                                pending</strong
                            >
                        </div>
                        <p class="panel-note">
                            Activate a received teller float after checking
                            denominations and PIN.
                        </p>
                        <form
                            class="form-grid two"
                            @submit.prevent="activateFloat"
                        >
                            <label>
                                Float ID
                                <input
                                    v-model="floatActivateForm.float_id"
                                    inputmode="numeric"
                                />
                            </label>
                            <label>
                                PIN
                                <input
                                    v-model="floatActivateForm.pin"
                                    inputmode="numeric"
                                    type="password"
                                />
                            </label>
                            <label class="wide-field">
                                Verified Denominations
                                <textarea
                                    v-model="
                                        floatActivateForm.verified_denominations
                                    "
                                    rows="4"
                                />
                            </label>
                            <button
                                type="submit"
                                class="primary-button"
                                :disabled="loading"
                            >
                                Activate Float
                            </button>
                        </form>
                    </section>
                </section>

                <section
                    v-if="activePage === 'cash-floats'"
                    class="panel data-panel"
                >
                    <div class="panel-heading">
                        <span>Cash Floats</span>
                        <strong>{{ cashFloats.length }}</strong>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                        <th>Teller</th>
                                    <th>Status</th>
                                    <th>Total</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="float in cashFloats" :key="float.id">
                                    <td>#{{ float.id }}</td>
                                    <td>
                                        {{
                                            float.employee_name ||
                                            `#${float.employee_id}`
                                        }}
                                    </td>
                                    <td>
                                        <span class="row-status">{{
                                            float.status
                                        }}</span>
                                    </td>
                                    <td>
                                        {{ formatMoney(float.total_amount) }}
                                    </td>
                                    <td>
                                        {{ formatMoney(float.current_balance) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="cashFloats.length === 0" class="empty-line">
                        No cash floats.
                    </p>
                </section>

                <section
                    v-if="activePage === 'dashboard' || activePage === 'vault'"
                    class="panel inventory-panel"
                >
                    <div class="panel-heading">
                        <span>Vault Inventory</span>
                        <strong
                            >{{
                                latestRate?.sell_rate ?? '0.0000'
                            }}
                            sell</strong
                        >
                    </div>
                    <div class="denom-grid">
                        <div
                            v-for="(
                                quantity, denomination
                            ) in inventory?.main_vault ?? {}"
                            :key="denomination"
                        >
                            <span>{{ denomination }} MMK</span>
                            <strong>{{ quantity }}</strong>
                        </div>
                    </div>
                    <p v-if="!inventory" class="empty-line">
                        No vault inventory.
                    </p>
                </section>
            </section>
        </main>

        <div
            v-if="cashInReview"
            class="cash-in-review-backdrop"
            role="presentation"
            @click.self="closeCashInReview"
        >
            <section
                class="cash-in-review-modal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="cash-in-review-title"
            >
                <header class="cash-in-review-header">
                    <div>
                        <span class="cash-in-review-eyebrow">Cash In review</span>
                        <h2 id="cash-in-review-title">
                            Verify denomination before confirmation
                        </h2>
                        <p>
                            Check the physical notes handed over by the Teller, then confirm the Cash In.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="cash-in-review-close"
                        aria-label="Close denomination review"
                        :disabled="loading"
                        @click="closeCashInReview"
                    >
                        ×
                    </button>
                </header>

                <div class="cash-in-review-summary">
                    <div>
                        <span>Reference</span>
                        <strong>#{{ cashInReview.id }}</strong>
                    </div>
                    <div>
                        <span>Cash In amount</span>
                        <strong>{{ formatMoney(cashInReview.amount) }} MMK</strong>
                    </div>
                    <div>
                        <span>Expected handoff</span>
                        <strong>{{ formatMoney(cashInReviewExpectedHandoff) }} MMK</strong>
                    </div>
                </div>

                <div class="cash-in-review-columns">
                    <section class="cash-in-review-card">
                        <div class="cash-in-review-card-heading">
                            <div>
                                <span class="cash-in-review-step">01</span>
                                <h3>Customer cash received</h3>
                            </div>
                            <strong>{{ formatMoney(denominationTotal(cashInReviewRows.received)) }} MMK</strong>
                        </div>
                        <div class="cash-in-review-denoms">
                            <div v-for="row in cashInReviewRows.received" :key="`received-${row.denomination}`">
                                <span>{{ formatMoney(row.denomination) }} × {{ row.quantity }}</span>
                                <strong>{{ formatMoney(row.total) }}</strong>
                            </div>
                            <p v-if="cashInReviewRows.received.length === 0">No denomination recorded.</p>
                        </div>
                    </section>

                    <section class="cash-in-review-card cash-in-review-card-primary">
                        <div class="cash-in-review-card-heading">
                            <div>
                                <span class="cash-in-review-step">02</span>
                                <h3>Cashier handoff</h3>
                            </div>
                            <strong>{{ formatMoney(cashInReviewHandoffTotal) }} MMK</strong>
                        </div>
                        <div class="cash-in-review-denoms">
                            <div v-for="row in cashInReviewRows.handoff" :key="`handoff-${row.denomination}`">
                                <span>{{ formatMoney(row.denomination) }} × {{ row.quantity }}</span>
                                <strong>{{ formatMoney(row.total) }}</strong>
                            </div>
                            <p v-if="cashInReviewRows.handoff.length === 0">No denomination recorded.</p>
                        </div>
                    </section>
                </div>

                <section v-if="cashInReviewRows.change.length" class="cash-in-review-card cash-in-review-change">
                    <div class="cash-in-review-card-heading">
                        <div>
                            <span class="cash-in-review-step">03</span>
                            <h3>Change from Teller vault</h3>
                        </div>
                        <strong>{{ formatMoney(denominationTotal(cashInReviewRows.change)) }} MMK</strong>
                    </div>
                    <div class="cash-in-review-denoms cash-in-review-denoms-inline">
                        <div v-for="row in cashInReviewRows.change" :key="`change-${row.denomination}`">
                            <span>{{ formatMoney(row.denomination) }} × {{ row.quantity }}</span>
                            <strong>{{ formatMoney(row.total) }}</strong>
                        </div>
                    </div>
                </section>

                <div
                    class="cash-in-review-status"
                    :class="cashInReviewIsBalanced ? 'is-balanced' : 'is-warning'"
                >
                    <strong>{{ cashInReviewIsBalanced ? 'Denomination balanced' : 'Review required' }}</strong>
                    <span>
                        Handoff {{ formatMoney(cashInReviewHandoffTotal) }} / {{ formatMoney(cashInReviewExpectedHandoff) }} MMK
                    </span>
                </div>

                <footer class="cash-in-review-actions">
                    <button
                        type="button"
                        class="ghost-button"
                        :disabled="loading"
                        @click="closeCashInReview"
                    >
                        Back
                    </button>
                    <button
                        type="button"
                        class="primary-button"
                        :disabled="loading || !cashInReviewIsBalanced"
                        @click="confirmCashIn(cashInReview.id)"
                    >
                        {{ loading ? 'Confirming…' : 'Confirm Cash In' }}
                    </button>
                </footer>
            </section>
        </div>

        <div v-if="workspaceLoading" class="loading-bar" aria-hidden="true" />
    </div>
</template>
