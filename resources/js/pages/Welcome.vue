<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import { ApiRequestError, apiRequest } from '../lib/api';
import {
    createNgweLweEcho,
    disconnectNgweLweEcho,
    subscribeToRoleChannel,
    subscribeToUserChannel,
} from '../lib/echo';
import type { RealtimeEventName, RealtimePayload } from '../lib/echo';
import type {
    Account,
    ApiCollection,
    ApiItem,
    CashFloat,
    Company,
    DenominationMap,
    ExchangeRate,
    LoginResponse,
    Role,
    ServiceType,
    SessionUser,
    Transaction,
    VaultInventory,
    VaultTransaction,
} from '../types';

type NoticeTone = 'ok' | 'warn' | 'error';
type TransactionMode = 'cash-in' | 'cash-out' | 'transfer' | 'exchange';

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

const tokenKey = 'ngwe_lwe_api_token';
const roles: Array<{ id: Role; label: string }> = [
    { id: 'owner', label: 'Owner' },
    { id: 'cashier', label: 'Cashier' },
    { id: 'employee', label: 'Employee' },
];
const transactionModes: Array<{ id: TransactionMode; label: string }> = [
    { id: 'cash-in', label: 'Cash In' },
    { id: 'cash-out', label: 'Cash Out' },
    { id: 'transfer', label: 'Transfer' },
    { id: 'exchange', label: 'Exchange' },
];

const token = ref(readStoredToken());
const session = ref<SessionUser | null>(null);
const previewRole = ref<Role>('owner');
const notice = ref<Notice | null>(null);
const loading = ref(false);
const workspaceLoading = ref(false);
const realtimeStatus = ref('offline');
const realtimeEvents = ref<RealtimeEntry[]>([]);
const eventCounter = ref(0);

const companies = ref<Company[]>([]);
const serviceTypes = ref<ServiceType[]>([]);
const accounts = ref<Account[]>([]);
const transactions = ref<Transaction[]>([]);
const cashFloats = ref<CashFloat[]>([]);
const inventory = ref<VaultInventory | null>(null);
const vaultLog = ref<VaultTransaction[]>([]);
const latestRate = ref<ExchangeRate | null>(null);

const loginForm = ref({
    username: '',
    password: '',
});
const pinForm = ref({
    pin: '',
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
const activeUserName = computed(() => {
    if (!session.value) {
        return 'No session';
    }

    return session.value.full_name || session.value.username;
});
const pendingCashIns = computed(() =>
    transactions.value.filter(
        (transaction) => transaction.status === 'PENDING_CASHIER_CONFIRM',
    ),
);
const activeFloatCount = computed(
    () => cashFloats.value.filter((float) => float.status === 'ACTIVE').length,
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
const serviceTypeOptions = computed(() =>
    serviceTypes.value.map((serviceType) => ({
        id: serviceType.id,
        label: `#${serviceType.id} ${serviceType.name}`,
    })),
);
const currentRoleLabel = computed(() => roleLabel(activeRole.value));
const workspaceState = computed(() =>
    session.value ? 'Live API session' : 'Preview mode',
);
const vaultTotal = computed(() => inventory.value?.main_vault_total ?? 0);
const employeeCashTotal = computed(
    () => inventory.value?.total_employee_cash ?? 0,
);
const grandPhysicalTotal = computed(
    () => inventory.value?.grand_physical_total ?? 0,
);

onMounted(() => {
    if (token.value) {
        void restoreSession();
    }
});

onBeforeUnmount(() => {
    disconnectRealtime();
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
    } catch (error) {
        clearSession();
        setNotice('warn', messageFromError(error));
    } finally {
        loading.value = false;
    }
}

async function login(): Promise<void> {
    loading.value = true;

    try {
        const response = await apiRequest<LoginResponse>('/api/auth/login', {
            method: 'POST',
            body: {
                username: loginForm.value.username,
                password: loginForm.value.password,
            },
        });
        token.value = response.token;
        session.value = response.user;
        previewRole.value = response.user.role;
        storeToken(response.token);
        loginForm.value.password = '';
        connectRealtime();
        await refreshWorkspace();
        setNotice('ok', `Signed in as ${roleLabel(response.user.role)}.`);
    } catch (error) {
        setNotice('error', messageFromError(error));
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
    setNotice('ok', 'Signed out.');
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
        setNotice('ok', 'PIN updated.');
    });
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

        if (activeRole.value === 'owner') {
            const logResponse = await apiRequest<
                ApiCollection<VaultTransaction>
            >('/api/vault/log', {
                token: token.value,
                query: { per_page: 12 },
            });
            vaultLog.value = logResponse.data;
        } else {
            vaultLog.value = [];
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
                    'Employee',
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

async function confirmCashIn(transactionId: number): Promise<void> {
    await runAction(async () => {
        await apiRequest(`/api/transactions/${transactionId}/confirm-cash-in`, {
            method: 'POST',
            token: token.value,
        });
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

    if (session.value.role === 'employee') {
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
    return role.charAt(0).toUpperCase() + role.slice(1);
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
    serviceTypes.value = [];
    accounts.value = [];
    transactions.value = [];
    cashFloats.value = [];
    inventory.value = null;
    vaultLog.value = [];
    latestRate.value = null;
}

function readStoredToken(): string {
    if (typeof window === 'undefined') {
        return '';
    }

    return localStorage.getItem(tokenKey) ?? '';
}

function storeToken(value: string): void {
    if (typeof window !== 'undefined') {
        localStorage.setItem(tokenKey, value);
    }
}

function removeStoredToken(): void {
    if (typeof window !== 'undefined') {
        localStorage.removeItem(tokenKey);
    }
}
</script>

<template>
    <Head title="Operations Console" />

    <div class="console-shell">
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
                <button
                    v-if="session"
                    type="button"
                    class="ghost-button danger"
                    @click="logout"
                >
                    Logout
                </button>
            </div>
        </header>

        <main class="workspace">
            <aside class="side-rail">
                <section class="panel session-panel">
                    <div class="panel-heading">
                        <span>Session</span>
                        <strong>{{ activeUserName }}</strong>
                    </div>

                    <form
                        v-if="!session"
                        class="stack-form"
                        @submit.prevent="login"
                    >
                        <label>
                            Username
                            <input
                                v-model="loginForm.username"
                                autocomplete="username"
                            />
                        </label>
                        <label>
                            Password
                            <input
                                v-model="loginForm.password"
                                autocomplete="current-password"
                                type="password"
                            />
                        </label>
                        <button
                            type="submit"
                            class="primary-button"
                            :disabled="loading"
                        >
                            Login
                        </button>
                    </form>

                    <div v-else class="session-facts">
                        <dl>
                            <div>
                                <dt>User ID</dt>
                                <dd>#{{ session.id }}</dd>
                            </div>
                            <div>
                                <dt>Role</dt>
                                <dd>{{ currentRoleLabel }}</dd>
                            </div>
                        </dl>

                        <form class="inline-form" @submit.prevent="setPin">
                            <label>
                                PIN
                                <input
                                    v-model="pinForm.pin"
                                    inputmode="numeric"
                                    type="password"
                                />
                            </label>
                            <button
                                type="submit"
                                class="compact-button"
                                :disabled="loading"
                            >
                                Save
                            </button>
                        </form>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-heading">
                        <span>Role View</span>
                        <strong>{{ currentRoleLabel }}</strong>
                    </div>
                    <div
                        class="segmented-control"
                        role="tablist"
                        aria-label="Role view"
                    >
                        <button
                            v-for="role in roles"
                            :key="role.id"
                            type="button"
                            :class="{ active: activeRole === role.id }"
                            :disabled="Boolean(session)"
                            @click="previewRole = role.id"
                        >
                            {{ role.label }}
                        </button>
                    </div>
                </section>

                <section class="panel event-feed">
                    <div class="panel-heading">
                        <span>Realtime</span>
                        <strong>{{ realtimeEvents.length }}</strong>
                    </div>
                    <button
                        v-if="activeRole === 'owner'"
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
            </aside>

            <section class="content-column" aria-live="polite">
                <div v-if="notice" class="notice" :data-tone="notice.tone">
                    {{ notice.message }}
                </div>

                <section class="metric-grid" aria-label="Operations summary">
                    <article class="metric">
                        <span>Main Vault</span>
                        <strong>{{ formatMoney(vaultTotal) }}</strong>
                        <small>MMK</small>
                    </article>
                    <article class="metric">
                        <span>Employee Cash</span>
                        <strong>{{ formatMoney(employeeCashTotal) }}</strong>
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

                <section class="panel data-panel">
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

                <section v-if="activeRole === 'owner'" class="role-grid">
                    <section class="panel">
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

                    <section class="panel">
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

                    <section class="panel">
                        <div class="panel-heading">
                            <span>Owner Operations</span>
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

                    <section class="panel data-panel">
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

                <section v-if="activeRole === 'cashier'" class="role-grid">
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
                                                @click="
                                                    confirmCashIn(
                                                        transaction.id,
                                                    )
                                                "
                                            >
                                                Confirm
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
                                Employee ID
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

                <section v-if="activeRole === 'employee'" class="role-grid">
                    <section class="panel">
                        <div class="panel-heading">
                            <span>Transaction Entry</span>
                            <strong>{{ transactionMode }}</strong>
                        </div>
                        <div class="segmented-control compact" role="tablist">
                            <button
                                v-for="mode in transactionModes"
                                :key="mode.id"
                                type="button"
                                :class="{ active: transactionMode === mode.id }"
                                @click="transactionMode = mode.id"
                            >
                                {{ mode.label }}
                            </button>
                        </div>

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
                            <button
                                type="submit"
                                class="primary-button"
                                :disabled="loading"
                            >
                                Create Transaction
                            </button>
                        </form>
                    </section>

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
                                class="secondary-button"
                                :disabled="loading"
                            >
                                Activate Float
                            </button>
                        </form>
                    </section>

                    <section class="panel">
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
                </section>

                <section class="panel data-panel">
                    <div class="panel-heading">
                        <span>Cash Floats</span>
                        <strong>{{ cashFloats.length }}</strong>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Employee</th>
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

                <section class="panel inventory-panel">
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

        <div v-if="workspaceLoading" class="loading-bar" aria-hidden="true" />
    </div>
</template>
