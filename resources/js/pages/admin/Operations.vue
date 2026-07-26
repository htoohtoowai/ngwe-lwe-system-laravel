<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import BankLayout from '@/layouts/BankLayout.vue';
import { apiRequest } from '@/lib/api';
import type { ApiRequestOptions } from '@/lib/api';
import { readStoredToken } from '@/lib/auth-token';

type Role = 'admin' | 'cashier' | 'teller';
type MoneyValue = string | number | null;
type DenominationMap = Record<string, number>;
type ApiList<T> = { data?: T[] };
type ApiObject<T> = { data?: T };
type RequestOptions = Pick<ApiRequestOptions, 'method' | 'body' | 'query'>;
type StatusTone = 'ok' | 'warn' | 'muted';

type Company = {
    id: number;
    name: string;
    category: 'Pay' | 'Bank' | 'Both' | string;
    is_active: boolean;
    created_at?: string | null;
};

type ServiceType = {
    id: number;
    company_id: number;
    name: string;
    operation: 'CashIn' | 'CashOut' | 'Transfer' | 'Exchange' | 'All' | string;
    is_active: boolean;
    company?: Company | null;
};

type Account = {
    id: number;
    service_type_id: number;
    account_name: string;
    phone_number: string;
    balance: MoneyValue;
    commission_rate: MoneyValue;
    is_active: boolean;
    is_fee_account: boolean;
    service_type?: ServiceType | null;
};

type User = {
    id: number;
    username: string;
    email: string | null;
    full_name: string;
    role: Role | string;
    is_active: boolean;
    has_pin: boolean;
    created_at?: string | null;
};

type CommissionTier = {
    id: number;
    service_type_id: number;
    amount_from: MoneyValue;
    amount_to: MoneyValue;
    fee_amount_type: 'FIXED' | 'PERCENTAGE' | string;
    fee_amount_deposit: MoneyValue;
    fee_amount_withdraw: MoneyValue;
    comm_type: 'FIXED' | 'PERCENTAGE' | string;
    comm_deposit: MoneyValue;
    comm_withdraw: MoneyValue;
    additional_fee_type: 'FIXED' | 'PERCENTAGE' | string;
    additional_fee_deposit_amount: MoneyValue;
    additional_fee_withdraw_amount: MoneyValue;
    is_active: boolean;
    service_type?: ServiceType | null;
};

type ExchangeRate = {
    id: number;
    base_currency: string;
    quote_currency: string;
    base_amount: MoneyValue;
    buy_rate: MoneyValue;
    sell_rate: MoneyValue;
    created_at?: string | null;
};

type Transaction = {
    id: number;
    transaction_type: string;
    customer_name: string | null;
    amount: MoneyValue;
    customer_fee: MoneyValue;
    commission_amount: MoneyValue;
    fee_payment_method: string | null;
    status: string;
    created_by: number | null;
    created_at?: string | null;
};

type ActivityLog = {
    id: number;
    user_id: number | null;
    user?: { username?: string | null; full_name?: string | null } | null;
    action: string;
    entity_type: string | null;
    entity_id: number | null;
    details: unknown;
    created_at?: string | null;
};

type CashFloat = {
    id: number;
    employee_id: number;
    employee_name: string | null;
    issued_by_name: string | null;
    status: string;
    total_amount: MoneyValue;
    current_balance: MoneyValue;
    created_at?: string | null;
};

type VaultInventory = {
    main_vault: DenominationMap;
    main_vault_total: MoneyValue;
    employee_floats: FloatSnapshot[];
    total_employee_cash: MoneyValue;
    grand_physical_total: MoneyValue;
};

type VaultLog = {
    id: number;
    txn_type: string;
    float_id: number | null;
    denomination: number;
    quantity: number;
    transaction_id: number | null;
    performed_by_name: string | null;
    verified_by_name: string | null;
    note: string | null;
    created_at?: string | null;
};

type FloatSnapshot = {
    float_id: number;
    employee_id: number;
    employee_name: string | null;
    status: string;
    current_balance: MoneyValue;
    total_amount: MoneyValue;
    denomination_balance?: DenominationMap;
    denom_total?: MoneyValue;
};

type AccountSnapshot = {
    id: number;
    account_name: string;
    service_type?: string | null;
    company?: string | null;
    balance: MoneyValue;
    is_fee_account?: boolean;
};

type Summary = {
    summary_date?: string;
    total_cash_in?: MoneyValue;
    total_cash_out?: MoneyValue;
    total_transfer?: MoneyValue;
    total_exchange?: MoneyValue;
    total_commission?: MoneyValue;
    total_customer_fees?: MoneyValue;
    total_profit?: MoneyValue;
    transaction_count?: number;
    pending_cash_in_count?: number;
    main_vault_total?: MoneyValue;
    employee_floats_total?: MoneyValue;
    total_cash?: MoneyValue;
    total_digital?: MoneyValue;
    grand_total?: MoneyValue;
    vault_snapshot?: { denominations?: DenominationMap; total?: MoneyValue };
    employee_snapshots?: FloatSnapshot[];
    account_snapshots?: AccountSnapshot[];
};

type Reconciliation = {
    id: number;
    recon_date: string | null;
    closed_by_name: string | null;
    closed_at: string | null;
    grand_total: MoneyValue;
    total_cash: MoneyValue;
    total_digital: MoneyValue;
    notes: string | null;
};

type AdminTab =
    | 'overview'
    | 'companies'
    | 'service-types'
    | 'exchange-rates'
    | 'accounts'
    | 'fees'
    | 'users'
    | 'transactions'
    | 'vault'
    | 'reports';
type AdminMode = 'list' | 'detail' | 'create' | 'edit';

const props = defineProps<{
    role: 'admin';
    section?: AdminTab;
    mode?: AdminMode;
    resourceId?: number | null;
    announcement?: string | null;
    notificationCount?: number;
}>();

const setupSections: AdminTab[] = [
    'companies',
    'service-types',
    'exchange-rates',
];

const activeTab = computed<AdminTab>(() => props.section ?? 'overview');
const activeMode = computed<AdminMode>(() => props.mode ?? 'list');
const resourceId = computed(() => props.resourceId ?? null);
const loading = ref(false);
const busy = ref('');
const error = ref('');
const notice = ref('');
const reportDate = ref(today());
const closeNotes = ref('');

const dashboardSummary = ref<Summary | null>(null);
const dailySummary = ref<Summary | null>(null);
const companies = ref<Company[]>([]);
const serviceTypes = ref<ServiceType[]>([]);
const accounts = ref<Account[]>([]);
const users = ref<User[]>([]);
const transactions = ref<Transaction[]>([]);
const activityLogs = ref<ActivityLog[]>([]);
const cashFloats = ref<CashFloat[]>([]);
const vaultInventory = ref<VaultInventory | null>(null);
const vaultLogs = ref<VaultLog[]>([]);
const exchangeRates = ref<ExchangeRate[]>([]);
const reconciliations = ref<Reconciliation[]>([]);
const commissionTiers = ref<CommissionTier[]>([]);
const systemStatus = ref<Record<string, unknown> | null>(null);

const companyForm = ref({
    name: '',
    category: 'Pay',
    is_active: true,
});
const serviceForm = ref({
    company_id: null as number | null,
    name: '',
    operation: 'CashIn',
    is_active: true,
});
const accountForm = ref({
    service_type_id: null as number | null,
    account_name: '',
    phone_number: '',
    balance: 0,
    commission_rate: 0,
    is_fee_account: false,
    is_active: true,
});
const adjustForm = ref({
    account_id: null as number | null,
    amount: 0,
    remark: '',
});
const tierServiceTypeId = ref<number | null>(null);
const tierForm = ref({
    service_type_id: null as number | null,
    amount_from: 1,
    amount_to: 999999999,
    fee_amount_type: 'FIXED',
    fee_amount_deposit: 0,
    fee_amount_withdraw: 0,
    comm_type: 'FIXED',
    comm_deposit: 0,
    comm_withdraw: 0,
    additional_fee_type: 'FIXED',
    additional_fee_deposit_amount: 0,
    additional_fee_withdraw_amount: 0,
    is_active: true,
});
const userForm = ref({
    username: '',
    email: '',
    full_name: '',
    role: 'teller',
    password: 'password123',
    pin: '',
    is_active: true,
});
const credentialForm = ref({
    user_id: null as number | null,
    new_password: '',
    pin: '',
});
const rateForm = ref({
    base_currency: 'THB',
    quote_currency: 'MMK',
    base_amount: 1,
    buy_rate: 0,
    sell_rate: 0,
});
const passwordForm = ref({
    old_password: '',
    new_password: '',
});
const transactionFilters = ref({
    type: '',
    date_from: '',
    date_to: '',
});
const logFilters = ref({
    user_id: null as number | null,
    action: '',
    entity_type: '',
    date: '',
});

const activeCompanyCount = computed(
    () => companies.value.filter((company) => company.is_active).length,
);
const activeServiceCount = computed(
    () =>
        serviceTypes.value.filter((serviceType) => serviceType.is_active)
            .length,
);
const activeAccountCount = computed(
    () => accounts.value.filter((account) => account.is_active).length,
);
const activeUserCount = computed(
    () => users.value.filter((user) => user.is_active).length,
);
const digitalTotal = computed(() =>
    accounts.value.reduce((sum, account) => sum + numeric(account.balance), 0),
);
const feeAccounts = computed(() =>
    accounts.value.filter((account) => account.is_fee_account),
);
const selectedTierService = computed(
    () =>
        serviceTypes.value.find(
            (serviceType) => serviceType.id === tierServiceTypeId.value,
        ) ?? null,
);
const latestRates = computed(() => exchangeRates.value.slice(0, 8));
const pendingCashInCount = computed(
    () =>
        dashboardSummary.value?.pending_cash_in_count ??
        props.notificationCount ??
        0,
);
const vaultDenominations = computed(() =>
    denominationRows(vaultInventory.value?.main_vault ?? {}),
);
const topAccountSnapshots = computed(() =>
    (dailySummary.value?.account_snapshots ?? []).slice(0, 8),
);
const topFloatSnapshots = computed(() =>
    (
        vaultInventory.value?.employee_floats ??
        dailySummary.value?.employee_snapshots ??
        []
    ).slice(0, 8),
);
const currentCompany = computed(
    () =>
        companies.value.find((company) => company.id === resourceId.value) ??
        null,
);
const currentServiceType = computed(
    () =>
        serviceTypes.value.find(
            (serviceType) => serviceType.id === resourceId.value,
        ) ?? null,
);
const currentAccount = computed(
    () =>
        accounts.value.find((account) => account.id === resourceId.value) ??
        null,
);
const currentTier = computed(
    () =>
        commissionTiers.value.find((tier) => tier.id === resourceId.value) ??
        null,
);
const currentUser = computed(
    () => users.value.find((user) => user.id === resourceId.value) ?? null,
);
const currentExchangeRate = computed(
    () =>
        exchangeRates.value.find((rate) => rate.id === resourceId.value) ??
        null,
);
const currentTransaction = computed(
    () =>
        transactions.value.find(
            (transaction) => transaction.id === resourceId.value,
        ) ?? null,
);
const visibleCommissionTiers = computed(() => {
    if (tierServiceTypeId.value === null) {
        return commissionTiers.value;
    }

    return commissionTiers.value.filter(
        (tier) => tier.service_type_id === tierServiceTypeId.value,
    );
});
const sectionSlugs: Record<AdminTab, string> = {
    overview: '',
    companies: 'companies',
    'service-types': 'service-types',
    'exchange-rates': 'exchange-rates',
    accounts: 'accounts',
    fees: 'fees',
    users: 'users',
    transactions: 'transactions',
    vault: 'vault',
    reports: 'reports',
};
const pageHeading = computed(() => {
    const labels: Record<AdminTab, string> = {
        overview: 'Admin Operations',
        companies: 'Companies',
        'service-types': 'Service Types',
        'exchange-rates': 'Exchange Rates',
        accounts: 'Accounts',
        fees: 'Fees',
        users: 'Users',
        transactions: 'Transactions',
        vault: 'Vault',
        reports: 'Reports',
    };
    const modeLabel =
        activeMode.value === 'list'
            ? ''
            : activeMode.value === 'detail'
              ? 'Detail'
              : activeMode.value === 'edit'
                ? 'Update'
                : 'Create';

    return modeLabel
        ? `${labels[activeTab.value]} ${modeLabel}`
        : labels[activeTab.value];
});

watch(
    companies,
    (values) => {
        if (serviceForm.value.company_id === null && values.length > 0) {
            serviceForm.value.company_id = values[0].id;
        }
    },
    { immediate: true },
);

watch(
    serviceTypes,
    (values) => {
        const firstId = values[0]?.id ?? null;

        if (accountForm.value.service_type_id === null) {
            accountForm.value.service_type_id = firstId;
        }

        if (tierServiceTypeId.value === null) {
            tierServiceTypeId.value = firstId;
        }
    },
    { immediate: true },
);

watch(tierServiceTypeId, (value) => {
    tierForm.value.service_type_id = value;
});

watch(
    [
        activeTab,
        activeMode,
        resourceId,
        currentCompany,
        currentServiceType,
        currentAccount,
        currentTier,
        currentUser,
        currentExchangeRate,
    ],
    () => {
        syncFormsFromRoute();
    },
    { immediate: true },
);

onMounted(() => {
    void refreshAll();
});

function today(): string {
    const current = new Date();
    current.setMinutes(current.getMinutes() - current.getTimezoneOffset());

    return current.toISOString().slice(0, 10);
}

function authHeaders(): Record<string, string> {
    const token = readStoredToken();

    return token ? { Authorization: `Bearer ${token}` } : {};
}

function isSetupSection(tab: AdminTab): boolean {
    return setupSections.includes(tab);
}

function showSetupCard(
    tab: 'companies' | 'service-types' | 'exchange-rates',
): boolean {
    return activeTab.value === tab;
}

function adminPath(
    section: AdminTab,
    mode: AdminMode = 'list',
    id?: number | null,
): string {
    const slug = sectionSlugs[section];
    const base = slug === '' ? '/admin' : `/admin/${slug}`;

    if (mode === 'create') {
        return `${base}/create`;
    }

    if ((mode === 'detail' || mode === 'edit') && id) {
        return mode === 'edit' ? `${base}/${id}/edit` : `${base}/${id}`;
    }

    return base;
}

function visitAdmin(
    section: AdminTab,
    mode: AdminMode = 'list',
    id?: number | null,
): void {
    router.visit(adminPath(section, mode, id), {
        headers: authHeaders(),
    });
}

function shouldShowCreateEdit(section: AdminTab): boolean {
    return (
        activeTab.value === section &&
        (activeMode.value === 'create' || activeMode.value === 'edit')
    );
}

function shouldShowDetail(section: AdminTab): boolean {
    return activeTab.value === section && activeMode.value === 'detail';
}

function resetCompanyForm(): void {
    companyForm.value = { name: '', category: 'Pay', is_active: true };
}

function resetServiceForm(): void {
    serviceForm.value = {
        company_id: companies.value[0]?.id ?? null,
        name: '',
        operation: 'CashIn',
        is_active: true,
    };
}

function resetAccountForm(): void {
    accountForm.value = {
        service_type_id: serviceTypes.value[0]?.id ?? null,
        account_name: '',
        phone_number: '',
        balance: 0,
        commission_rate: 0,
        is_fee_account: false,
        is_active: true,
    };
}

function resetTierForm(): void {
    tierForm.value = {
        service_type_id:
            tierServiceTypeId.value ?? serviceTypes.value[0]?.id ?? null,
        amount_from: 1,
        amount_to: 999999999,
        fee_amount_type: 'FIXED',
        fee_amount_deposit: 0,
        fee_amount_withdraw: 0,
        comm_type: 'FIXED',
        comm_deposit: 0,
        comm_withdraw: 0,
        additional_fee_type: 'FIXED',
        additional_fee_deposit_amount: 0,
        additional_fee_withdraw_amount: 0,
        is_active: true,
    };
}

function resetUserForm(): void {
    userForm.value = {
        username: '',
        email: '',
        full_name: '',
        role: 'teller',
        password: 'password123',
        pin: '',
        is_active: true,
    };
}

function resetRateForm(): void {
    rateForm.value = {
        base_currency: 'THB',
        quote_currency: 'MMK',
        base_amount: 1,
        buy_rate: 0,
        sell_rate: 0,
    };
}

function syncFormsFromRoute(): void {
    if (activeMode.value === 'create') {
        if (activeTab.value === 'companies') {
            resetCompanyForm();
        } else if (activeTab.value === 'service-types') {
            resetServiceForm();
        } else if (activeTab.value === 'accounts') {
            resetAccountForm();
        } else if (activeTab.value === 'fees') {
            resetTierForm();
        } else if (activeTab.value === 'users') {
            resetUserForm();
        } else if (activeTab.value === 'exchange-rates') {
            resetRateForm();
        }

        return;
    }

    if (activeMode.value === 'detail') {
        if (activeTab.value === 'accounts' && currentAccount.value) {
            adjustForm.value.account_id = currentAccount.value.id;
        } else if (activeTab.value === 'users' && currentUser.value) {
            credentialForm.value.user_id = currentUser.value.id;
        }

        return;
    }

    if (activeMode.value !== 'edit') {
        return;
    }

    if (activeTab.value === 'companies' && currentCompany.value) {
        companyForm.value = {
            name: currentCompany.value.name,
            category: currentCompany.value.category,
            is_active: currentCompany.value.is_active,
        };
    } else if (
        activeTab.value === 'service-types' &&
        currentServiceType.value
    ) {
        serviceForm.value = {
            company_id: currentServiceType.value.company_id,
            name: currentServiceType.value.name,
            operation: currentServiceType.value.operation,
            is_active: currentServiceType.value.is_active,
        };
    } else if (activeTab.value === 'accounts' && currentAccount.value) {
        accountForm.value = {
            service_type_id: currentAccount.value.service_type_id,
            account_name: currentAccount.value.account_name,
            phone_number: currentAccount.value.phone_number,
            balance: numeric(currentAccount.value.balance),
            commission_rate: numeric(currentAccount.value.commission_rate),
            is_fee_account: currentAccount.value.is_fee_account,
            is_active: currentAccount.value.is_active,
        };
    } else if (activeTab.value === 'fees' && currentTier.value) {
        tierServiceTypeId.value = currentTier.value.service_type_id;
        tierForm.value = {
            service_type_id: currentTier.value.service_type_id,
            amount_from: numeric(currentTier.value.amount_from),
            amount_to: numeric(currentTier.value.amount_to),
            fee_amount_type: currentTier.value.fee_amount_type,
            fee_amount_deposit: numeric(currentTier.value.fee_amount_deposit),
            fee_amount_withdraw: numeric(currentTier.value.fee_amount_withdraw),
            comm_type: currentTier.value.comm_type,
            comm_deposit: numeric(currentTier.value.comm_deposit),
            comm_withdraw: numeric(currentTier.value.comm_withdraw),
            additional_fee_type: currentTier.value.additional_fee_type,
            additional_fee_deposit_amount: numeric(
                currentTier.value.additional_fee_deposit_amount,
            ),
            additional_fee_withdraw_amount: numeric(
                currentTier.value.additional_fee_withdraw_amount,
            ),
            is_active: currentTier.value.is_active,
        };
    } else if (activeTab.value === 'users' && currentUser.value) {
        userForm.value = {
            username: currentUser.value.username,
            email: currentUser.value.email ?? '',
            full_name: currentUser.value.full_name,
            role: currentUser.value.role,
            password: '',
            pin: '',
            is_active: currentUser.value.is_active,
        };
    } else if (
        activeTab.value === 'exchange-rates' &&
        currentExchangeRate.value
    ) {
        rateForm.value = {
            base_currency: currentExchangeRate.value.base_currency,
            quote_currency: currentExchangeRate.value.quote_currency,
            base_amount: numeric(currentExchangeRate.value.base_amount),
            buy_rate: numeric(currentExchangeRate.value.buy_rate),
            sell_rate: numeric(currentExchangeRate.value.sell_rate),
        };
    }
}

function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
    return apiRequest<T>(path, {
        ...options,
        token: readStoredToken(),
    });
}

function listFrom<T>(payload: unknown): T[] {
    const data = (payload as ApiList<T>)?.data;

    if (Array.isArray(data)) {
        return data;
    }

    return Array.isArray(payload) ? (payload as T[]) : [];
}

function objectFrom<T>(payload: unknown): T {
    return ((payload as ApiObject<T>)?.data ?? payload) as T;
}

function firstError(value: unknown): string {
    const data = value as {
        message?: string;
        errors?: Record<string, string[]>;
    };
    const validation = data.errors ? Object.values(data.errors)[0]?.[0] : null;

    return validation ?? data.message ?? 'Request failed.';
}

function numeric(value: MoneyValue | undefined): number {
    const amount = Number(value ?? 0);

    return Number.isFinite(amount) ? amount : 0;
}

function money(value: MoneyValue | undefined): string {
    return numeric(value).toLocaleString(undefined, {
        maximumFractionDigits: 2,
    });
}

function percent(value: MoneyValue | undefined): string {
    return numeric(value).toLocaleString(undefined, {
        maximumFractionDigits: 4,
    });
}

function dateTime(value: string | null | undefined): string {
    return value ? new Date(value).toLocaleString() : '-';
}

function userName(userId: number | null | undefined): string {
    if (!userId) {
        return '-';
    }

    const user = users.value.find((row) => row.id === userId);

    return user?.full_name ?? user?.username ?? `#${userId}`;
}

function companyName(serviceType: ServiceType | null | undefined): string {
    if (serviceType?.company?.name) {
        return serviceType.company.name;
    }

    const company = companies.value.find(
        (row) => row.id === serviceType?.company_id,
    );

    return company?.name ?? '-';
}

function serviceLabel(serviceType: ServiceType | null | undefined): string {
    if (!serviceType) {
        return '-';
    }

    return `${companyName(serviceType)} / ${serviceType.name}`;
}

function accountLabel(account: Account | null | undefined): string {
    if (!account) {
        return '-';
    }

    return `${account.account_name} (${account.phone_number})`;
}

function transactionTypeLabel(value: string): string {
    return value
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (part) => part.toUpperCase());
}

function compactJson(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    const text = typeof value === 'string' ? value : JSON.stringify(value);

    return text.length > 140 ? `${text.slice(0, 140)}...` : text;
}

function statusTone(active: boolean): StatusTone {
    return active ? 'ok' : 'muted';
}

function statusClass(tone: StatusTone): string {
    return {
        ok: 'bg-balance/10 text-balance',
        warn: 'bg-brand-soft text-brand',
        muted: 'bg-mist text-slate',
    }[tone];
}

function denominationRows(
    map: DenominationMap,
): { denomination: number; quantity: number; total: number }[] {
    return Object.entries(map)
        .map(([denomination, quantity]) => ({
            denomination: Number(denomination),
            quantity: Number(quantity),
            total: Number(denomination) * Number(quantity),
        }))
        .filter((row) => row.quantity !== 0)
        .sort((left, right) => right.denomination - left.denomination);
}

function clearFeedback(): void {
    error.value = '';
    notice.value = '';
}

async function runAction(
    label: string,
    task: () => Promise<void>,
): Promise<void> {
    busy.value = label;
    clearFeedback();

    try {
        await task();
        notice.value = label;
    } catch (exception) {
        error.value = firstError(exception);
    } finally {
        busy.value = '';
    }
}

async function refreshAll(): Promise<void> {
    loading.value = true;
    clearFeedback();

    try {
        const [
            dashboardPayload,
            dailyPayload,
            companiesPayload,
            serviceTypesPayload,
            accountsPayload,
            usersPayload,
            transactionsPayload,
            logsPayload,
            floatsPayload,
            vaultPayload,
            vaultLogsPayload,
            ratesPayload,
            reconciliationsPayload,
            statusPayload,
        ] = await Promise.all([
            request<Summary>('/api/dashboard/summary'),
            request<ApiObject<Summary>>('/api/reports/daily-summary', {
                query: { date: reportDate.value },
            }),
            request<ApiList<Company>>('/api/companies', {
                query: { include_inactive: true },
            }),
            request<ApiList<ServiceType>>('/api/service-types', {
                query: { include_inactive: true },
            }),
            request<ApiList<Account>>('/api/accounts', {
                query: { include_inactive: true },
            }),
            request<ApiList<User>>('/api/users', {
                query: { include_inactive: true },
            }),
            request<ApiList<Transaction>>('/api/transactions', {
                query: transactionQuery(),
            }),
            request<ApiList<ActivityLog>>('/api/activity-logs', {
                query: logQuery(),
            }),
            request<ApiList<CashFloat>>('/api/cash-floats'),
            request<ApiObject<VaultInventory>>('/api/vault/inventory'),
            request<ApiList<VaultLog>>('/api/vault/log', {
                query: { per_page: 100 },
            }),
            request<ApiList<ExchangeRate>>('/api/exchange-rates', {
                query: { limit: 50 },
            }),
            request<ApiList<Reconciliation>>(
                '/api/reports/daily-reconciliations',
                { query: { per_page: 20 } },
            ),
            request<Record<string, unknown>>('/api/system/status'),
        ]);

        dashboardSummary.value = objectFrom<Summary>(dashboardPayload);
        dailySummary.value = objectFrom<Summary>(dailyPayload);
        companies.value = listFrom<Company>(companiesPayload);
        serviceTypes.value = listFrom<ServiceType>(serviceTypesPayload);
        accounts.value = listFrom<Account>(accountsPayload);
        users.value = listFrom<User>(usersPayload);
        transactions.value = listFrom<Transaction>(transactionsPayload);
        activityLogs.value = listFrom<ActivityLog>(logsPayload);
        cashFloats.value = listFrom<CashFloat>(floatsPayload);
        vaultInventory.value = objectFrom<VaultInventory>(vaultPayload);
        vaultLogs.value = listFrom<VaultLog>(vaultLogsPayload);
        exchangeRates.value = listFrom<ExchangeRate>(ratesPayload);
        reconciliations.value = listFrom<Reconciliation>(
            reconciliationsPayload,
        );
        systemStatus.value = statusPayload;
        await refreshTiers();
        syncFormsFromRoute();
    } catch (exception) {
        error.value = firstError(exception);
    } finally {
        loading.value = false;
    }
}

async function refreshDailySummary(): Promise<void> {
    await runAction('Report refreshed.', async () => {
        const payload = await request<ApiObject<Summary>>(
            '/api/reports/daily-summary',
            { query: { date: reportDate.value } },
        );
        dailySummary.value = objectFrom<Summary>(payload);
    });
}

function transactionQuery(): Record<
    string,
    string | number | boolean | null | undefined
> {
    return {
        limit: 200,
        type: transactionFilters.value.type || null,
        date_from: transactionFilters.value.date_from || null,
        date_to: transactionFilters.value.date_to || null,
    };
}

function logQuery(): Record<
    string,
    string | number | boolean | null | undefined
> {
    return {
        per_page: 200,
        user_id: logFilters.value.user_id,
        action: logFilters.value.action || null,
        entity_type: logFilters.value.entity_type || null,
        date: logFilters.value.date || null,
    };
}

async function refreshTransactionsAndLogs(): Promise<void> {
    await runAction('Transactions and logs refreshed.', async () => {
        const [transactionsPayload, logsPayload] = await Promise.all([
            request<ApiList<Transaction>>('/api/transactions', {
                query: transactionQuery(),
            }),
            request<ApiList<ActivityLog>>('/api/activity-logs', {
                query: logQuery(),
            }),
        ]);

        transactions.value = listFrom<Transaction>(transactionsPayload);
        activityLogs.value = listFrom<ActivityLog>(logsPayload);
    });
}

async function refreshTiers(): Promise<void> {
    if (serviceTypes.value.length === 0) {
        commissionTiers.value = [];

        return;
    }

    const payloads = await Promise.all(
        serviceTypes.value.map((serviceType) =>
            request<ApiList<CommissionTier>>('/api/commission-tiers', {
                query: {
                    service_type_id: serviceType.id,
                    include_inactive: true,
                },
            }).catch(() => ({ data: [] })),
        ),
    );
    commissionTiers.value = payloads.flatMap((payload) =>
        listFrom<CommissionTier>(payload),
    );
}

async function saveCompany(): Promise<void> {
    await runAction('Company saved.', async () => {
        const isEdit = activeMode.value === 'edit' && resourceId.value !== null;
        const payload = await request<ApiObject<Company>>(
            isEdit ? `/api/companies/${resourceId.value}` : '/api/companies',
            {
                method: isEdit ? 'PATCH' : 'POST',
                body: { ...companyForm.value },
            },
        );
        const company = objectFrom<Company>(payload);
        resetCompanyForm();
        await refreshAll();
        visitAdmin('companies', 'detail', company.id);
    });
}

async function toggleCompany(company: Company): Promise<void> {
    await runAction('Company status updated.', async () => {
        await request(`/api/companies/${company.id}`, {
            method: 'PATCH',
            body: { is_active: !company.is_active },
        });
        await refreshAll();
    });
}

async function saveServiceType(): Promise<void> {
    if (serviceForm.value.company_id === null) {
        error.value = 'Select a company first.';

        return;
    }

    await runAction('Service type saved.', async () => {
        const isEdit = activeMode.value === 'edit' && resourceId.value !== null;
        const payload = await request<ApiObject<ServiceType>>(
            isEdit
                ? `/api/service-types/${resourceId.value}`
                : '/api/service-types',
            {
                method: isEdit ? 'PATCH' : 'POST',
                body: {
                    ...serviceForm.value,
                    company_id: serviceForm.value.company_id,
                },
            },
        );
        const serviceType = objectFrom<ServiceType>(payload);
        resetServiceForm();
        await refreshAll();
        visitAdmin('service-types', 'detail', serviceType.id);
    });
}

async function toggleServiceType(serviceType: ServiceType): Promise<void> {
    await runAction('Service type status updated.', async () => {
        await request(`/api/service-types/${serviceType.id}`, {
            method: 'PATCH',
            body: { is_active: !serviceType.is_active },
        });
        await refreshAll();
    });
}

async function saveAccount(): Promise<void> {
    if (accountForm.value.service_type_id === null) {
        error.value = 'Select a service type first.';

        return;
    }

    await runAction('Account saved.', async () => {
        const isEdit = activeMode.value === 'edit' && resourceId.value !== null;
        const payload = await request<ApiObject<Account>>(
            isEdit ? `/api/accounts/${resourceId.value}` : '/api/accounts',
            {
                method: isEdit ? 'PATCH' : 'POST',
                body: {
                    ...accountForm.value,
                    service_type_id: accountForm.value.service_type_id,
                },
            },
        );
        const account = objectFrom<Account>(payload);
        resetAccountForm();
        await refreshAll();
        visitAdmin('accounts', 'detail', account.id);
    });
}

async function toggleAccount(account: Account): Promise<void> {
    await runAction('Account status updated.', async () => {
        await request(`/api/accounts/${account.id}`, {
            method: 'PATCH',
            body: { is_active: !account.is_active },
        });
        await refreshAll();
    });
}

async function adjustAccountBalance(): Promise<void> {
    if (adjustForm.value.account_id === null) {
        error.value = 'Select an account first.';

        return;
    }

    await runAction('Account balance adjusted.', async () => {
        await request(
            `/api/accounts/${adjustForm.value.account_id}/balance-adjust`,
            {
                method: 'POST',
                body: {
                    amount: adjustForm.value.amount,
                    remark:
                        adjustForm.value.remark ||
                        'Admin console balance adjustment.',
                },
            },
        );
        adjustForm.value = {
            account_id: adjustForm.value.account_id,
            amount: 0,
            remark: '',
        };
        await refreshAll();
    });
}

async function saveTier(): Promise<void> {
    if (tierForm.value.service_type_id === null) {
        error.value = 'Select a service type first.';

        return;
    }

    await runAction('Commission tier saved.', async () => {
        const isEdit = activeMode.value === 'edit' && resourceId.value !== null;
        const payload = await request<ApiObject<CommissionTier>>(
            isEdit
                ? `/api/commission-tiers/${resourceId.value}`
                : '/api/commission-tiers',
            {
                method: isEdit ? 'PATCH' : 'POST',
                body: {
                    ...tierForm.value,
                    service_type_id: tierForm.value.service_type_id,
                },
            },
        );
        const tier = objectFrom<CommissionTier>(payload);
        resetTierForm();
        await refreshTiers();
        visitAdmin('fees', 'detail', tier.id);
    });
}

async function deleteTier(tier: CommissionTier): Promise<void> {
    await runAction('Commission tier deleted.', async () => {
        await request(`/api/commission-tiers/${tier.id}`, { method: 'DELETE' });
        await refreshTiers();
    });
}

async function saveUser(): Promise<void> {
    await runAction('User saved.', async () => {
        const isEdit = activeMode.value === 'edit' && resourceId.value !== null;
        const body: Record<string, unknown> = {
            username: userForm.value.username,
            email: userForm.value.email || null,
            full_name: userForm.value.full_name,
            role: userForm.value.role,
            is_active: userForm.value.is_active,
        };

        if (!isEdit || userForm.value.password) {
            body.password = userForm.value.password;
        }

        if (userForm.value.pin) {
            body.pin = userForm.value.pin;
        }

        const payload = await request<ApiObject<User>>(
            isEdit ? `/api/users/${resourceId.value}` : '/api/users',
            {
                method: isEdit ? 'PATCH' : 'POST',
                body,
            },
        );
        const user = objectFrom<User>(payload);
        resetUserForm();
        await refreshAll();
        visitAdmin('users', 'detail', user.id);
    });
}

async function toggleUser(user: User): Promise<void> {
    await runAction('User status updated.', async () => {
        await request(`/api/users/${user.id}/active`, {
            method: 'PATCH',
            body: { is_active: !user.is_active },
        });
        await refreshAll();
    });
}

async function resetUserPassword(): Promise<void> {
    if (credentialForm.value.user_id === null) {
        error.value = 'Select a user first.';

        return;
    }

    await runAction('User password reset.', async () => {
        await request(
            `/api/users/${credentialForm.value.user_id}/reset-password`,
            {
                method: 'POST',
                body: { new_password: credentialForm.value.new_password },
            },
        );
        credentialForm.value.new_password = '';
    });
}

async function setUserPin(): Promise<void> {
    if (credentialForm.value.user_id === null) {
        error.value = 'Select a user first.';

        return;
    }

    await runAction('User PIN updated.', async () => {
        await request(`/api/users/${credentialForm.value.user_id}/pin`, {
            method: 'POST',
            body: { pin: credentialForm.value.pin },
        });
        credentialForm.value.pin = '';
        await refreshAll();
    });
}

async function saveExchangeRate(): Promise<void> {
    await runAction('Exchange rate saved.', async () => {
        const isEdit = activeMode.value === 'edit' && resourceId.value !== null;
        const payload = await request<ApiObject<ExchangeRate>>(
            isEdit
                ? `/api/exchange-rates/${resourceId.value}`
                : '/api/exchange-rates',
            {
                method: isEdit ? 'PATCH' : 'POST',
                body: { ...rateForm.value },
            },
        );
        const rate = objectFrom<ExchangeRate>(payload);
        resetRateForm();
        await refreshAll();
        visitAdmin('exchange-rates', 'detail', rate.id);
    });
}

async function changePassword(): Promise<void> {
    await runAction('Password changed.', async () => {
        await request('/api/users/change-password', {
            method: 'POST',
            body: { ...passwordForm.value },
        });
        passwordForm.value = { old_password: '', new_password: '' };
    });
}

async function closeDay(): Promise<void> {
    await runAction('Day closed.', async () => {
        await request('/api/reconciliation/close-day', {
            method: 'POST',
            body: {
                date: reportDate.value,
                notes: closeNotes.value || null,
            },
        });
        closeNotes.value = '';
        await refreshAll();
    });
}

async function createBackup(): Promise<void> {
    await runAction('Backup created.', async () => {
        const payload = await request<{ message?: string; path?: string }>(
            '/api/system/backup',
            { method: 'POST' },
        );

        notice.value = payload.path
            ? `Backup created: ${payload.path}`
            : (payload.message ?? 'Backup created.');
    });
}

async function sendBroadcastTest(): Promise<void> {
    await runAction('Broadcast test sent.', async () => {
        await request('/api/broadcast/test', { method: 'POST' });
    });
}
</script>

<template>
    <BankLayout
        :role="role"
        :announcement="announcement"
        :notification-count="notificationCount"
    >
        <div class="flex flex-col gap-5">
            <header
                class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
            >
                <div>
                    <p
                        class="text-xs font-black tracking-[0.18em] text-brand uppercase"
                    >
                        Owner Console
                    </p>
                    <h1
                        class="mt-1 text-2xl font-black tracking-tight text-ink"
                    >
                        {{ pageHeading }}
                    </h1>
                    <p class="mt-1 max-w-3xl text-sm font-semibold text-slate">
                        Master data, staff access, fee tiers, audit review,
                        vault visibility and daily closing.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="bank-button bank-button-primary"
                        :disabled="loading || busy !== ''"
                        @click="refreshAll"
                    >
                        {{ loading ? 'Refreshing...' : 'Refresh' }}
                    </button>
                </div>
            </header>

            <div
                v-if="notice"
                class="rounded-field border border-balance/20 bg-balance/10 px-4 py-3 text-sm font-bold text-balance"
            >
                {{ notice }}
            </div>
            <div
                v-if="error"
                class="rounded-field border border-brand/25 bg-brand-soft px-4 py-3 text-sm font-bold text-brand"
            >
                {{ error }}
            </div>

            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <div
                    class="rounded-xl border border-line bg-card p-4 shadow-sm"
                >
                    <p class="text-xs font-bold text-slate">Pending Cash In</p>
                    <p class="money mt-2 text-2xl font-black text-ink">
                        {{ pendingCashInCount }}
                    </p>
                </div>
                <div
                    class="rounded-xl border border-line bg-card p-4 shadow-sm"
                >
                    <p class="text-xs font-bold text-slate">
                        Today's Transactions
                    </p>
                    <p class="money mt-2 text-2xl font-black text-ink">
                        {{ dashboardSummary?.transaction_count ?? 0 }}
                    </p>
                </div>
                <div
                    class="rounded-xl border border-line bg-card p-4 shadow-sm"
                >
                    <p class="text-xs font-bold text-slate">Main Vault</p>
                    <p class="money mt-2 text-2xl font-black text-ink">
                        {{
                            money(
                                vaultInventory?.main_vault_total ??
                                    dashboardSummary?.main_vault_total,
                            )
                        }}
                    </p>
                </div>
                <div
                    class="rounded-xl border border-line bg-card p-4 shadow-sm"
                >
                    <p class="text-xs font-bold text-slate">Digital Balance</p>
                    <p class="money mt-2 text-2xl font-black text-ink">
                        {{
                            money(
                                digitalTotal || dashboardSummary?.total_digital,
                            )
                        }}
                    </p>
                </div>
                <div
                    class="rounded-xl border border-line bg-card p-4 shadow-sm"
                >
                    <p class="text-xs font-bold text-slate">Active Staff</p>
                    <p class="money mt-2 text-2xl font-black text-ink">
                        {{ activeUserCount }}
                    </p>
                </div>
            </section>

            <section
                v-if="activeTab === 'overview'"
                class="grid gap-5 xl:grid-cols-[1.25fr_0.75fr]"
            >
                <div
                    class="rounded-xl border border-line bg-card p-5 shadow-sm"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
                    >
                        <div>
                            <h2 class="text-lg font-black text-ink">
                                Daily Position
                            </h2>
                            <p class="text-sm font-semibold text-slate">
                                {{ dailySummary?.summary_date ?? reportDate }}
                            </p>
                        </div>
                        <form
                            class="flex flex-wrap items-end gap-2"
                            @submit.prevent="refreshDailySummary"
                        >
                            <label class="min-w-40">
                                <span class="bank-label">Date</span>
                                <input
                                    v-model="reportDate"
                                    type="date"
                                    class="bank-input py-2.5"
                                />
                            </label>
                            <button
                                type="submit"
                                class="bank-button bank-button-secondary"
                                :disabled="busy !== ''"
                            >
                                Load
                            </button>
                        </form>
                    </div>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-lg bg-mist p-4">
                            <p class="text-xs font-bold text-slate">Cash In</p>
                            <p class="money mt-1 font-black text-ink">
                                {{ money(dailySummary?.total_cash_in) }}
                            </p>
                        </div>
                        <div class="rounded-lg bg-mist p-4">
                            <p class="text-xs font-bold text-slate">Cash Out</p>
                            <p class="money mt-1 font-black text-ink">
                                {{ money(dailySummary?.total_cash_out) }}
                            </p>
                        </div>
                        <div class="rounded-lg bg-mist p-4">
                            <p class="text-xs font-bold text-slate">Transfer</p>
                            <p class="money mt-1 font-black text-ink">
                                {{ money(dailySummary?.total_transfer) }}
                            </p>
                        </div>
                        <div class="rounded-lg bg-mist p-4">
                            <p class="text-xs font-bold text-slate">Profit</p>
                            <p class="money mt-1 font-black text-balance">
                                {{ money(dailySummary?.total_profit) }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="mt-5 overflow-hidden rounded-lg border border-line"
                    >
                        <table class="w-full min-w-[640px] text-left text-sm">
                            <thead class="bg-mist text-xs text-slate uppercase">
                                <tr>
                                    <th class="px-4 py-3">Account</th>
                                    <th class="px-4 py-3">Company</th>
                                    <th class="px-4 py-3">Service</th>
                                    <th class="px-4 py-3 text-right">
                                        Balance
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr
                                    v-for="account in topAccountSnapshots"
                                    :key="account.id"
                                >
                                    <td class="px-4 py-3 font-bold text-ink">
                                        {{ account.account_name }}
                                    </td>
                                    <td class="px-4 py-3 text-slate">
                                        {{ account.company ?? '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-slate">
                                        {{ account.service_type ?? '-' }}
                                    </td>
                                    <td
                                        class="money px-4 py-3 text-right font-bold text-ink"
                                    >
                                        {{ money(account.balance) }}
                                    </td>
                                </tr>
                                <tr v-if="topAccountSnapshots.length === 0">
                                    <td
                                        colspan="4"
                                        class="px-4 py-6 text-center text-sm font-semibold text-slate"
                                    >
                                        No account snapshot yet.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div
                    class="rounded-xl border border-line bg-card p-5 shadow-sm"
                >
                    <h2 class="text-lg font-black text-ink">
                        Operating Coverage
                    </h2>
                    <div class="mt-4 grid gap-3">
                        <div
                            class="flex items-center justify-between rounded-lg bg-mist px-4 py-3"
                        >
                            <span class="text-sm font-bold text-slate"
                                >Companies</span
                            >
                            <strong class="money text-ink"
                                >{{ activeCompanyCount }} /
                                {{ companies.length }}</strong
                            >
                        </div>
                        <div
                            class="flex items-center justify-between rounded-lg bg-mist px-4 py-3"
                        >
                            <span class="text-sm font-bold text-slate"
                                >Service Types</span
                            >
                            <strong class="money text-ink"
                                >{{ activeServiceCount }} /
                                {{ serviceTypes.length }}</strong
                            >
                        </div>
                        <div
                            class="flex items-center justify-between rounded-lg bg-mist px-4 py-3"
                        >
                            <span class="text-sm font-bold text-slate"
                                >Accounts</span
                            >
                            <strong class="money text-ink"
                                >{{ activeAccountCount }} /
                                {{ accounts.length }}</strong
                            >
                        </div>
                        <div
                            class="flex items-center justify-between rounded-lg bg-mist px-4 py-3"
                        >
                            <span class="text-sm font-bold text-slate"
                                >Fee Accounts</span
                            >
                            <strong class="money text-ink">{{
                                feeAccounts.length
                            }}</strong>
                        </div>
                    </div>

                    <h3
                        class="mt-6 text-sm font-black tracking-[0.16em] text-slate uppercase"
                    >
                        Employee Floats
                    </h3>
                    <div class="mt-3 space-y-2">
                        <div
                            v-for="float in topFloatSnapshots"
                            :key="float.float_id"
                            class="flex items-center justify-between rounded-lg border border-line px-3 py-2"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-ink">
                                    {{
                                        float.employee_name ??
                                        `Employee #${float.employee_id}`
                                    }}
                                </p>
                                <p class="text-xs font-semibold text-slate">
                                    {{ float.status }}
                                </p>
                            </div>
                            <strong class="money shrink-0 text-sm text-ink">{{
                                money(
                                    float.denom_total ?? float.current_balance,
                                )
                            }}</strong>
                        </div>
                        <p
                            v-if="topFloatSnapshots.length === 0"
                            class="rounded-lg bg-mist px-3 py-4 text-center text-sm font-semibold text-slate"
                        >
                            No active float snapshot.
                        </p>
                    </div>
                </div>
            </section>

            <section
                v-if="isSetupSection(activeTab)"
                class="grid gap-5 xl:grid-cols-1"
            >
                <div
                    v-if="showSetupCard('companies')"
                    class="rounded-xl border border-line bg-card p-5 shadow-sm"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-black text-ink">Companies</h2>
                        <div class="flex gap-2">
                            <Link
                                v-if="activeMode !== 'list'"
                                :href="adminPath('companies')"
                                :headers="authHeaders()"
                                class="bank-button bank-button-secondary py-2"
                            >
                                List
                            </Link>
                            <Link
                                v-if="activeMode === 'list'"
                                :href="adminPath('companies', 'create')"
                                :headers="authHeaders()"
                                class="bank-button bank-button-primary py-2"
                            >
                                Create
                            </Link>
                            <Link
                                v-if="
                                    shouldShowDetail('companies') &&
                                    currentCompany
                                "
                                :href="
                                    adminPath(
                                        'companies',
                                        'edit',
                                        currentCompany.id,
                                    )
                                "
                                :headers="authHeaders()"
                                class="bank-button bank-button-primary py-2"
                            >
                                Edit
                            </Link>
                        </div>
                    </div>
                    <form
                        v-if="shouldShowCreateEdit('companies')"
                        class="mt-4 grid gap-3"
                        @submit.prevent="saveCompany"
                    >
                        <label>
                            <span class="bank-label">Name</span>
                            <input
                                v-model.trim="companyForm.name"
                                class="bank-input"
                                required
                                placeholder="Company name"
                            />
                        </label>
                        <label>
                            <span class="bank-label">Category</span>
                            <select
                                v-model="companyForm.category"
                                class="bank-input"
                            >
                                <option>Pay</option>
                                <option>Bank</option>
                                <option>Both</option>
                            </select>
                        </label>
                        <label
                            class="flex items-center gap-2 text-sm font-bold text-ink"
                        >
                            <input
                                v-model="companyForm.is_active"
                                type="checkbox"
                                class="size-4 accent-brand"
                            />
                            Active
                        </label>
                        <button
                            type="submit"
                            class="bank-button bank-button-primary"
                            :disabled="busy !== ''"
                        >
                            {{
                                activeMode === 'edit'
                                    ? 'Update Company'
                                    : 'Save Company'
                            }}
                        </button>
                    </form>
                    <div
                        v-else-if="shouldShowDetail('companies')"
                        class="mt-4 rounded-lg border border-line bg-mist p-4"
                    >
                        <template v-if="currentCompany">
                            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="font-bold text-slate">Name</dt>
                                    <dd class="font-black text-ink">
                                        {{ currentCompany.name }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">
                                        Category
                                    </dt>
                                    <dd class="font-black text-ink">
                                        {{ currentCompany.category }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">Status</dt>
                                    <dd class="font-black text-ink">
                                        {{
                                            currentCompany.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}
                                    </dd>
                                </div>
                            </dl>
                        </template>
                        <p v-else class="text-sm font-semibold text-slate">
                            Company not found.
                        </p>
                    </div>
                    <div
                        v-else
                        class="mt-5 max-h-[26rem] overflow-y-auto rounded-lg border border-line"
                    >
                        <table class="w-full text-left text-sm">
                            <tbody class="divide-y divide-line">
                                <tr
                                    v-for="company in companies"
                                    :key="company.id"
                                >
                                    <td class="px-3 py-3">
                                        <p class="font-bold text-ink">
                                            {{ company.name }}
                                        </p>
                                        <p
                                            class="text-xs font-semibold text-slate"
                                        >
                                            {{ company.category }}
                                        </p>
                                    </td>
                                    <td class="px-3 py-3 text-right">
                                        <div class="flex justify-end gap-1.5">
                                            <Link
                                                :href="
                                                    adminPath(
                                                        'companies',
                                                        'detail',
                                                        company.id,
                                                    )
                                                "
                                                :headers="authHeaders()"
                                                class="rounded-pill bg-mist px-3 py-1 text-xs font-black text-slate"
                                            >
                                                View
                                            </Link>
                                            <Link
                                                :href="
                                                    adminPath(
                                                        'companies',
                                                        'edit',
                                                        company.id,
                                                    )
                                                "
                                                :headers="authHeaders()"
                                                class="rounded-pill bg-ink px-3 py-1 text-xs font-black text-white"
                                            >
                                                Edit
                                            </Link>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 text-right">
                                        <button
                                            type="button"
                                            class="rounded-pill px-3 py-1 text-xs font-black"
                                            :class="
                                                statusClass(
                                                    statusTone(
                                                        company.is_active,
                                                    ),
                                                )
                                            "
                                            @click="toggleCompany(company)"
                                        >
                                            {{
                                                company.is_active
                                                    ? 'Active'
                                                    : 'Inactive'
                                            }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div
                    v-if="showSetupCard('service-types')"
                    class="rounded-xl border border-line bg-card p-5 shadow-sm"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-black text-ink">
                            Service Types
                        </h2>
                        <div class="flex gap-2">
                            <Link
                                v-if="activeMode !== 'list'"
                                :href="adminPath('service-types')"
                                :headers="authHeaders()"
                                class="bank-button bank-button-secondary py-2"
                            >
                                List
                            </Link>
                            <Link
                                v-if="activeMode === 'list'"
                                :href="adminPath('service-types', 'create')"
                                :headers="authHeaders()"
                                class="bank-button bank-button-primary py-2"
                            >
                                Create
                            </Link>
                            <Link
                                v-if="
                                    shouldShowDetail('service-types') &&
                                    currentServiceType
                                "
                                :href="
                                    adminPath(
                                        'service-types',
                                        'edit',
                                        currentServiceType.id,
                                    )
                                "
                                :headers="authHeaders()"
                                class="bank-button bank-button-primary py-2"
                            >
                                Edit
                            </Link>
                        </div>
                    </div>
                    <form
                        v-if="shouldShowCreateEdit('service-types')"
                        class="mt-4 grid gap-3"
                        @submit.prevent="saveServiceType"
                    >
                        <label>
                            <span class="bank-label">Company</span>
                            <select
                                v-model.number="serviceForm.company_id"
                                class="bank-input"
                                required
                            >
                                <option
                                    v-for="company in companies"
                                    :key="company.id"
                                    :value="company.id"
                                >
                                    {{ company.name }}
                                </option>
                            </select>
                        </label>
                        <label>
                            <span class="bank-label">Service Value</span>
                            <input
                                v-model.trim="serviceForm.name"
                                class="bank-input"
                                required
                                placeholder="WST, P2P, KPay"
                            />
                        </label>
                        <label>
                            <span class="bank-label">Operation</span>
                            <select
                                v-model="serviceForm.operation"
                                class="bank-input"
                            >
                                <option>CashIn</option>
                                <option>CashOut</option>
                                <option>Transfer</option>
                                <option>Exchange</option>
                                <option>All</option>
                            </select>
                        </label>
                        <label
                            class="flex items-center gap-2 text-sm font-bold text-ink"
                        >
                            <input
                                v-model="serviceForm.is_active"
                                type="checkbox"
                                class="size-4 accent-brand"
                            />
                            Active
                        </label>
                        <button
                            type="submit"
                            class="bank-button bank-button-primary"
                            :disabled="busy !== ''"
                        >
                            {{
                                activeMode === 'edit'
                                    ? 'Update Service'
                                    : 'Save Service'
                            }}
                        </button>
                    </form>
                    <div
                        v-else-if="shouldShowDetail('service-types')"
                        class="mt-4 rounded-lg border border-line bg-mist p-4"
                    >
                        <template v-if="currentServiceType">
                            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="font-bold text-slate">
                                        Service
                                    </dt>
                                    <dd class="font-black text-ink">
                                        {{ currentServiceType.name }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">
                                        Company
                                    </dt>
                                    <dd class="font-black text-ink">
                                        {{ companyName(currentServiceType) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">
                                        Operation
                                    </dt>
                                    <dd class="font-black text-ink">
                                        {{ currentServiceType.operation }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">Status</dt>
                                    <dd class="font-black text-ink">
                                        {{
                                            currentServiceType.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}
                                    </dd>
                                </div>
                            </dl>
                        </template>
                        <p v-else class="text-sm font-semibold text-slate">
                            Service type not found.
                        </p>
                    </div>
                    <div
                        v-else
                        class="mt-5 max-h-[26rem] overflow-y-auto rounded-lg border border-line"
                    >
                        <table class="w-full text-left text-sm">
                            <tbody class="divide-y divide-line">
                                <tr
                                    v-for="serviceType in serviceTypes"
                                    :key="serviceType.id"
                                >
                                    <td class="px-3 py-3">
                                        <p class="font-bold text-ink">
                                            {{ serviceType.name }}
                                        </p>
                                        <p
                                            class="text-xs font-semibold text-slate"
                                        >
                                            {{ companyName(serviceType) }} /
                                            {{ serviceType.operation }}
                                        </p>
                                    </td>
                                    <td class="px-3 py-3 text-right">
                                        <div class="flex justify-end gap-1.5">
                                            <Link
                                                :href="
                                                    adminPath(
                                                        'service-types',
                                                        'detail',
                                                        serviceType.id,
                                                    )
                                                "
                                                :headers="authHeaders()"
                                                class="rounded-pill bg-mist px-3 py-1 text-xs font-black text-slate"
                                            >
                                                View
                                            </Link>
                                            <Link
                                                :href="
                                                    adminPath(
                                                        'service-types',
                                                        'edit',
                                                        serviceType.id,
                                                    )
                                                "
                                                :headers="authHeaders()"
                                                class="rounded-pill bg-ink px-3 py-1 text-xs font-black text-white"
                                            >
                                                Edit
                                            </Link>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 text-right">
                                        <button
                                            type="button"
                                            class="rounded-pill px-3 py-1 text-xs font-black"
                                            :class="
                                                statusClass(
                                                    statusTone(
                                                        serviceType.is_active,
                                                    ),
                                                )
                                            "
                                            @click="
                                                toggleServiceType(serviceType)
                                            "
                                        >
                                            {{
                                                serviceType.is_active
                                                    ? 'Active'
                                                    : 'Inactive'
                                            }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div
                    v-if="showSetupCard('exchange-rates')"
                    class="rounded-xl border border-line bg-card p-5 shadow-sm"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-black text-ink">
                            Exchange Rates
                        </h2>
                        <div class="flex gap-2">
                            <Link
                                v-if="activeMode !== 'list'"
                                :href="adminPath('exchange-rates')"
                                :headers="authHeaders()"
                                class="bank-button bank-button-secondary py-2"
                            >
                                List
                            </Link>
                            <Link
                                v-if="activeMode === 'list'"
                                :href="adminPath('exchange-rates', 'create')"
                                :headers="authHeaders()"
                                class="bank-button bank-button-primary py-2"
                            >
                                Create
                            </Link>
                            <Link
                                v-if="
                                    shouldShowDetail('exchange-rates') &&
                                    currentExchangeRate
                                "
                                :href="
                                    adminPath(
                                        'exchange-rates',
                                        'edit',
                                        currentExchangeRate.id,
                                    )
                                "
                                :headers="authHeaders()"
                                class="bank-button bank-button-primary py-2"
                            >
                                Edit
                            </Link>
                        </div>
                    </div>
                    <form
                        v-if="shouldShowCreateEdit('exchange-rates')"
                        class="mt-4 grid gap-3"
                        @submit.prevent="saveExchangeRate"
                    >
                        <div class="grid grid-cols-2 gap-3">
                            <label>
                                <span class="bank-label">Base</span>
                                <input
                                    v-model.trim="rateForm.base_currency"
                                    class="bank-input uppercase"
                                    required
                                />
                            </label>
                            <label>
                                <span class="bank-label">Quote</span>
                                <input
                                    v-model.trim="rateForm.quote_currency"
                                    class="bank-input uppercase"
                                    required
                                />
                            </label>
                        </div>
                        <label>
                            <span class="bank-label">Base Amount</span>
                            <input
                                v-model.number="rateForm.base_amount"
                                type="number"
                                min="0.01"
                                step="0.01"
                                class="bank-input"
                                required
                            />
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <label>
                                <span class="bank-label">Buy Rate</span>
                                <input
                                    v-model.number="rateForm.buy_rate"
                                    type="number"
                                    min="0.0001"
                                    step="0.0001"
                                    class="bank-input"
                                    required
                                />
                            </label>
                            <label>
                                <span class="bank-label">Sell Rate</span>
                                <input
                                    v-model.number="rateForm.sell_rate"
                                    type="number"
                                    min="0.0001"
                                    step="0.0001"
                                    class="bank-input"
                                    required
                                />
                            </label>
                        </div>
                        <button
                            type="submit"
                            class="bank-button bank-button-primary"
                            :disabled="busy !== ''"
                        >
                            {{
                                activeMode === 'edit'
                                    ? 'Update Rate'
                                    : 'Save Rate'
                            }}
                        </button>
                    </form>
                    <div
                        v-else-if="shouldShowDetail('exchange-rates')"
                        class="mt-4 rounded-lg border border-line bg-mist p-4"
                    >
                        <template v-if="currentExchangeRate">
                            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="font-bold text-slate">Pair</dt>
                                    <dd class="font-black text-ink">
                                        {{
                                            currentExchangeRate.base_currency
                                        }}/{{
                                            currentExchangeRate.quote_currency
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">
                                        Base Amount
                                    </dt>
                                    <dd class="money font-black text-ink">
                                        {{
                                            money(
                                                currentExchangeRate.base_amount,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">
                                        Buy Rate
                                    </dt>
                                    <dd class="money font-black text-ink">
                                        {{
                                            money(currentExchangeRate.buy_rate)
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">
                                        Sell Rate
                                    </dt>
                                    <dd class="money font-black text-ink">
                                        {{
                                            money(currentExchangeRate.sell_rate)
                                        }}
                                    </dd>
                                </div>
                            </dl>
                        </template>
                        <p v-else class="text-sm font-semibold text-slate">
                            Exchange rate not found.
                        </p>
                    </div>
                    <div v-else class="mt-5 space-y-2">
                        <div
                            v-for="rate in latestRates"
                            :key="rate.id"
                            class="flex items-center justify-between gap-3 rounded-lg bg-mist px-3 py-2"
                        >
                            <span class="text-sm font-bold text-ink"
                                >{{ rate.base_currency }}/{{
                                    rate.quote_currency
                                }}</span
                            >
                            <span class="money text-xs font-bold text-slate"
                                >{{ money(rate.buy_rate) }} /
                                {{ money(rate.sell_rate) }}</span
                            >
                            <div class="flex gap-1.5">
                                <Link
                                    :href="
                                        adminPath(
                                            'exchange-rates',
                                            'detail',
                                            rate.id,
                                        )
                                    "
                                    :headers="authHeaders()"
                                    class="rounded-pill bg-card px-3 py-1 text-xs font-black text-slate"
                                >
                                    View
                                </Link>
                                <Link
                                    :href="
                                        adminPath(
                                            'exchange-rates',
                                            'edit',
                                            rate.id,
                                        )
                                    "
                                    :headers="authHeaders()"
                                    class="rounded-pill bg-ink px-3 py-1 text-xs font-black text-white"
                                >
                                    Edit
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="activeTab === 'accounts'" class="grid gap-5">
                <div
                    v-if="shouldShowCreateEdit('accounts')"
                    class="rounded-xl border border-line bg-card p-5 shadow-sm"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-black text-ink">
                            {{
                                activeMode === 'edit'
                                    ? 'Update Account'
                                    : 'Create Account'
                            }}
                        </h2>
                        <Link
                            :href="adminPath('accounts')"
                            :headers="authHeaders()"
                            class="bank-button bank-button-secondary py-2"
                        >
                            List
                        </Link>
                    </div>
                    <form
                        class="mt-4 grid gap-3 lg:grid-cols-2"
                        @submit.prevent="saveAccount"
                    >
                        <label>
                            <span class="bank-label">Service Type</span>
                            <select
                                v-model.number="accountForm.service_type_id"
                                class="bank-input"
                                required
                            >
                                <option
                                    v-for="serviceType in serviceTypes"
                                    :key="serviceType.id"
                                    :value="serviceType.id"
                                >
                                    {{ serviceLabel(serviceType) }}
                                </option>
                            </select>
                        </label>
                        <label>
                            <span class="bank-label">Account Name</span>
                            <input
                                v-model.trim="accountForm.account_name"
                                class="bank-input"
                                required
                            />
                        </label>
                        <label>
                            <span class="bank-label">Phone / Account No.</span>
                            <input
                                v-model.trim="accountForm.phone_number"
                                class="bank-input"
                                required
                            />
                        </label>
                        <label>
                            <span class="bank-label">Balance</span>
                            <input
                                v-model.number="accountForm.balance"
                                type="number"
                                min="0"
                                step="0.01"
                                class="bank-input"
                            />
                        </label>
                        <label>
                            <span class="bank-label">Commission Rate</span>
                            <input
                                v-model.number="accountForm.commission_rate"
                                type="number"
                                min="0"
                                step="0.0001"
                                class="bank-input"
                            />
                        </label>
                        <div
                            class="flex flex-wrap items-center gap-4 text-sm font-bold text-ink"
                        >
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="accountForm.is_fee_account"
                                    type="checkbox"
                                    class="size-4 accent-brand"
                                />
                                Fee Account
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="accountForm.is_active"
                                    type="checkbox"
                                    class="size-4 accent-brand"
                                />
                                Active
                            </label>
                        </div>
                        <div class="lg:col-span-2">
                            <button
                                type="submit"
                                class="bank-button bank-button-primary"
                                :disabled="busy !== ''"
                            >
                                {{
                                    activeMode === 'edit'
                                        ? 'Update Account'
                                        : 'Save Account'
                                }}
                            </button>
                        </div>
                    </form>
                </div>

                <div
                    v-else-if="shouldShowDetail('accounts')"
                    class="grid gap-5 xl:grid-cols-[1fr_0.75fr]"
                >
                    <div
                        class="rounded-xl border border-line bg-card p-5 shadow-sm"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="text-lg font-black text-ink">
                                Account Detail
                            </h2>
                            <div class="flex gap-2">
                                <Link
                                    :href="adminPath('accounts')"
                                    :headers="authHeaders()"
                                    class="bank-button bank-button-secondary py-2"
                                >
                                    List
                                </Link>
                                <Link
                                    v-if="currentAccount"
                                    :href="
                                        adminPath(
                                            'accounts',
                                            'edit',
                                            currentAccount.id,
                                        )
                                    "
                                    :headers="authHeaders()"
                                    class="bank-button bank-button-primary py-2"
                                >
                                    Edit
                                </Link>
                            </div>
                        </div>
                        <template v-if="currentAccount">
                            <dl
                                class="mt-4 grid gap-3 rounded-lg bg-mist p-4 text-sm sm:grid-cols-2"
                            >
                                <div>
                                    <dt class="font-bold text-slate">
                                        Account
                                    </dt>
                                    <dd class="font-black text-ink">
                                        {{ currentAccount.account_name }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">Phone</dt>
                                    <dd class="font-black text-ink">
                                        {{ currentAccount.phone_number }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">
                                        Service
                                    </dt>
                                    <dd class="font-black text-ink">
                                        {{
                                            serviceLabel(
                                                currentAccount.service_type,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">
                                        Balance
                                    </dt>
                                    <dd class="money font-black text-ink">
                                        {{ money(currentAccount.balance) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">
                                        Commission Rate
                                    </dt>
                                    <dd class="money font-black text-ink">
                                        {{
                                            percent(
                                                currentAccount.commission_rate,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">Status</dt>
                                    <dd class="font-black text-ink">
                                        {{
                                            currentAccount.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}
                                    </dd>
                                </div>
                            </dl>
                        </template>
                        <p v-else class="mt-4 text-sm font-semibold text-slate">
                            Account not found.
                        </p>
                    </div>

                    <div
                        class="rounded-xl border border-line bg-card p-5 shadow-sm"
                    >
                        <h2 class="text-lg font-black text-ink">
                            Balance Adjust
                        </h2>
                        <form
                            class="mt-4 grid gap-3"
                            @submit.prevent="adjustAccountBalance"
                        >
                            <label>
                                <span class="bank-label">Account</span>
                                <select
                                    v-model.number="adjustForm.account_id"
                                    class="bank-input"
                                    required
                                >
                                    <option :value="null" disabled>
                                        Select account
                                    </option>
                                    <option
                                        v-for="account in accounts"
                                        :key="account.id"
                                        :value="account.id"
                                    >
                                        {{ accountLabel(account) }}
                                    </option>
                                </select>
                            </label>
                            <label>
                                <span class="bank-label">Signed Amount</span>
                                <input
                                    v-model.number="adjustForm.amount"
                                    type="number"
                                    step="0.01"
                                    class="bank-input"
                                    required
                                />
                            </label>
                            <label>
                                <span class="bank-label">Remark</span>
                                <input
                                    v-model.trim="adjustForm.remark"
                                    class="bank-input"
                                    placeholder="Reason for audit log"
                                />
                            </label>
                            <button
                                type="submit"
                                class="bank-button bank-button-danger"
                                :disabled="busy !== ''"
                            >
                                Apply Adjustment
                            </button>
                        </form>
                    </div>
                </div>

                <div
                    v-else
                    class="rounded-xl border border-line bg-card p-5 shadow-sm"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-black text-ink">Accounts</h2>
                        <div class="flex items-center gap-3">
                            <strong class="money text-sm text-slate">{{
                                money(digitalTotal)
                            }}</strong>
                            <Link
                                :href="adminPath('accounts', 'create')"
                                :headers="authHeaders()"
                                class="bank-button bank-button-primary py-2"
                            >
                                Create
                            </Link>
                        </div>
                    </div>
                    <div
                        class="mt-4 overflow-auto rounded-lg border border-line"
                    >
                        <table class="w-full min-w-[820px] text-left text-sm">
                            <thead class="bg-mist text-xs text-slate uppercase">
                                <tr>
                                    <th class="px-4 py-3">Account</th>
                                    <th class="px-4 py-3">Service</th>
                                    <th class="px-4 py-3">Phone</th>
                                    <th class="px-4 py-3 text-right">
                                        Balance
                                    </th>
                                    <th class="px-4 py-3 text-right">Rate</th>
                                    <th class="px-4 py-3 text-right">Action</th>
                                    <th class="px-4 py-3 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr
                                    v-for="account in accounts"
                                    :key="account.id"
                                >
                                    <td class="px-4 py-3">
                                        <p class="font-bold text-ink">
                                            {{ account.account_name }}
                                        </p>
                                        <p
                                            v-if="account.is_fee_account"
                                            class="text-xs font-black text-brand"
                                        >
                                            Fee account
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 text-slate">
                                        {{ serviceLabel(account.service_type) }}
                                    </td>
                                    <td class="px-4 py-3 text-slate">
                                        {{ account.phone_number }}
                                    </td>
                                    <td
                                        class="money px-4 py-3 text-right font-bold text-ink"
                                    >
                                        {{ money(account.balance) }}
                                    </td>
                                    <td
                                        class="money px-4 py-3 text-right text-slate"
                                    >
                                        {{ percent(account.commission_rate) }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex justify-end gap-1.5">
                                            <Link
                                                :href="
                                                    adminPath(
                                                        'accounts',
                                                        'detail',
                                                        account.id,
                                                    )
                                                "
                                                :headers="authHeaders()"
                                                class="rounded-pill bg-mist px-3 py-1 text-xs font-black text-slate"
                                            >
                                                View
                                            </Link>
                                            <Link
                                                :href="
                                                    adminPath(
                                                        'accounts',
                                                        'edit',
                                                        account.id,
                                                    )
                                                "
                                                :headers="authHeaders()"
                                                class="rounded-pill bg-ink px-3 py-1 text-xs font-black text-white"
                                            >
                                                Edit
                                            </Link>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            class="rounded-pill px-3 py-1 text-xs font-black"
                                            :class="
                                                statusClass(
                                                    statusTone(
                                                        account.is_active,
                                                    ),
                                                )
                                            "
                                            @click="toggleAccount(account)"
                                        >
                                            {{
                                                account.is_active
                                                    ? 'Active'
                                                    : 'Inactive'
                                            }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section v-if="activeTab === 'fees'" class="grid gap-5">
                <div
                    v-if="shouldShowCreateEdit('fees')"
                    class="rounded-xl border border-line bg-card p-5 shadow-sm"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-black text-ink">
                            {{
                                activeMode === 'edit'
                                    ? 'Update Commission Tier'
                                    : 'Create Commission Tier'
                            }}
                        </h2>
                        <Link
                            :href="adminPath('fees')"
                            :headers="authHeaders()"
                            class="bank-button bank-button-secondary py-2"
                        >
                            List
                        </Link>
                    </div>
                    <label class="mt-4 block">
                        <span class="bank-label">Service Type</span>
                        <select
                            v-model.number="tierServiceTypeId"
                            class="bank-input"
                        >
                            <option
                                v-for="serviceType in serviceTypes"
                                :key="serviceType.id"
                                :value="serviceType.id"
                            >
                                {{ serviceLabel(serviceType) }}
                            </option>
                        </select>
                    </label>
                    <form class="mt-4 grid gap-3" @submit.prevent="saveTier">
                        <div class="grid grid-cols-2 gap-3">
                            <label>
                                <span class="bank-label">Amount From</span>
                                <input
                                    v-model.number="tierForm.amount_from"
                                    type="number"
                                    min="1"
                                    step="0.01"
                                    class="bank-input"
                                    required
                                />
                            </label>
                            <label>
                                <span class="bank-label">Amount To</span>
                                <input
                                    v-model.number="tierForm.amount_to"
                                    type="number"
                                    min="1"
                                    step="0.01"
                                    class="bank-input"
                                    required
                                />
                            </label>
                        </div>
                        <label>
                            <span class="bank-label">Fee Type</span>
                            <select
                                v-model="tierForm.fee_amount_type"
                                class="bank-input"
                            >
                                <option>FIXED</option>
                                <option>PERCENTAGE</option>
                            </select>
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <label>
                                <span class="bank-label">Cash In Fee</span>
                                <input
                                    v-model.number="tierForm.fee_amount_deposit"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="bank-input"
                                />
                            </label>
                            <label>
                                <span class="bank-label">Cash Out Fee</span>
                                <input
                                    v-model.number="
                                        tierForm.fee_amount_withdraw
                                    "
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="bank-input"
                                />
                            </label>
                        </div>
                        <label>
                            <span class="bank-label">Commission Type</span>
                            <select
                                v-model="tierForm.comm_type"
                                class="bank-input"
                            >
                                <option>FIXED</option>
                                <option>PERCENTAGE</option>
                            </select>
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <label>
                                <span class="bank-label">Cash In Comm.</span>
                                <input
                                    v-model.number="tierForm.comm_deposit"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="bank-input"
                                />
                            </label>
                            <label>
                                <span class="bank-label">Cash Out Comm.</span>
                                <input
                                    v-model.number="tierForm.comm_withdraw"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="bank-input"
                                />
                            </label>
                        </div>
                        <label>
                            <span class="bank-label">Additional Fee Type</span>
                            <select
                                v-model="tierForm.additional_fee_type"
                                class="bank-input"
                            >
                                <option>FIXED</option>
                                <option>PERCENTAGE</option>
                            </select>
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <label>
                                <span class="bank-label">Cash In Add.</span>
                                <input
                                    v-model.number="
                                        tierForm.additional_fee_deposit_amount
                                    "
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="bank-input"
                                />
                            </label>
                            <label>
                                <span class="bank-label">Cash Out Add.</span>
                                <input
                                    v-model.number="
                                        tierForm.additional_fee_withdraw_amount
                                    "
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="bank-input"
                                />
                            </label>
                        </div>
                        <label
                            class="flex items-center gap-2 text-sm font-bold text-ink"
                        >
                            <input
                                v-model="tierForm.is_active"
                                type="checkbox"
                                class="size-4 accent-brand"
                            />
                            Active
                        </label>
                        <button
                            type="submit"
                            class="bank-button bank-button-primary"
                            :disabled="busy !== ''"
                        >
                            {{
                                activeMode === 'edit'
                                    ? 'Update Tier'
                                    : 'Save Tier'
                            }}
                        </button>
                    </form>
                </div>

                <div
                    v-else-if="shouldShowDetail('fees')"
                    class="rounded-xl border border-line bg-card p-5 shadow-sm"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-black text-ink">
                            Commission Tier Detail
                        </h2>
                        <div class="flex gap-2">
                            <Link
                                :href="adminPath('fees')"
                                :headers="authHeaders()"
                                class="bank-button bank-button-secondary py-2"
                            >
                                List
                            </Link>
                            <Link
                                v-if="currentTier"
                                :href="
                                    adminPath('fees', 'edit', currentTier.id)
                                "
                                :headers="authHeaders()"
                                class="bank-button bank-button-primary py-2"
                            >
                                Edit
                            </Link>
                        </div>
                    </div>
                    <template v-if="currentTier">
                        <dl
                            class="mt-4 grid gap-3 rounded-lg bg-mist p-4 text-sm sm:grid-cols-2 xl:grid-cols-3"
                        >
                            <div>
                                <dt class="font-bold text-slate">Service</dt>
                                <dd class="font-black text-ink">
                                    {{ serviceLabel(currentTier.service_type) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="font-bold text-slate">Range</dt>
                                <dd class="money font-black text-ink">
                                    {{ money(currentTier.amount_from) }} -
                                    {{ money(currentTier.amount_to) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="font-bold text-slate">Status</dt>
                                <dd class="font-black text-ink">
                                    {{
                                        currentTier.is_active
                                            ? 'Active'
                                            : 'Inactive'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="font-bold text-slate">
                                    Cash In Fee
                                </dt>
                                <dd class="money font-black text-ink">
                                    {{ currentTier.fee_amount_type }} /
                                    {{ money(currentTier.fee_amount_deposit) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="font-bold text-slate">
                                    Cash Out Fee
                                </dt>
                                <dd class="money font-black text-ink">
                                    {{ currentTier.fee_amount_type }} /
                                    {{ money(currentTier.fee_amount_withdraw) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="font-bold text-slate">Commission</dt>
                                <dd class="money font-black text-ink">
                                    {{ currentTier.comm_type }} /
                                    {{ money(currentTier.comm_deposit) }} /
                                    {{ money(currentTier.comm_withdraw) }}
                                </dd>
                            </div>
                        </dl>
                    </template>
                    <p v-else class="mt-4 text-sm font-semibold text-slate">
                        Commission tier not found.
                    </p>
                </div>

                <div
                    v-else
                    class="rounded-xl border border-line bg-card p-5 shadow-sm"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
                    >
                        <div>
                            <h2 class="text-lg font-black text-ink">Tiers</h2>
                            <p class="text-sm font-bold text-slate">
                                {{
                                    selectedTierService
                                        ? serviceLabel(selectedTierService)
                                        : 'All service types'
                                }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-end gap-2">
                            <label>
                                <span class="bank-label">Service Type</span>
                                <select
                                    v-model.number="tierServiceTypeId"
                                    class="bank-input py-2.5"
                                >
                                    <option :value="null">All</option>
                                    <option
                                        v-for="serviceType in serviceTypes"
                                        :key="serviceType.id"
                                        :value="serviceType.id"
                                    >
                                        {{ serviceLabel(serviceType) }}
                                    </option>
                                </select>
                            </label>
                            <Link
                                :href="adminPath('fees', 'create')"
                                :headers="authHeaders()"
                                class="bank-button bank-button-primary"
                            >
                                Create
                            </Link>
                        </div>
                    </div>
                    <div
                        class="mt-4 overflow-auto rounded-lg border border-line"
                    >
                        <table class="w-full min-w-[860px] text-left text-sm">
                            <thead class="bg-mist text-xs text-slate uppercase">
                                <tr>
                                    <th class="px-4 py-3">Range</th>
                                    <th class="px-4 py-3">Fee In / Out</th>
                                    <th class="px-4 py-3">Comm. In / Out</th>
                                    <th class="px-4 py-3">Add. In / Out</th>
                                    <th class="px-4 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr
                                    v-for="tier in visibleCommissionTiers"
                                    :key="tier.id"
                                >
                                    <td
                                        class="money px-4 py-3 font-bold text-ink"
                                    >
                                        {{ money(tier.amount_from) }} -
                                        {{ money(tier.amount_to) }}
                                    </td>
                                    <td class="money px-4 py-3 text-slate">
                                        {{ tier.fee_amount_type }} /
                                        {{ money(tier.fee_amount_deposit) }} /
                                        {{ money(tier.fee_amount_withdraw) }}
                                    </td>
                                    <td class="money px-4 py-3 text-slate">
                                        {{ tier.comm_type }} /
                                        {{ money(tier.comm_deposit) }} /
                                        {{ money(tier.comm_withdraw) }}
                                    </td>
                                    <td class="money px-4 py-3 text-slate">
                                        {{ tier.additional_fee_type }} /
                                        {{
                                            money(
                                                tier.additional_fee_deposit_amount,
                                            )
                                        }}
                                        /
                                        {{
                                            money(
                                                tier.additional_fee_withdraw_amount,
                                            )
                                        }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex justify-end gap-1.5">
                                            <Link
                                                :href="
                                                    adminPath(
                                                        'fees',
                                                        'detail',
                                                        tier.id,
                                                    )
                                                "
                                                :headers="authHeaders()"
                                                class="rounded-pill bg-mist px-3 py-1 text-xs font-black text-slate"
                                            >
                                                View
                                            </Link>
                                            <Link
                                                :href="
                                                    adminPath(
                                                        'fees',
                                                        'edit',
                                                        tier.id,
                                                    )
                                                "
                                                :headers="authHeaders()"
                                                class="rounded-pill bg-ink px-3 py-1 text-xs font-black text-white"
                                            >
                                                Edit
                                            </Link>
                                            <button
                                                type="button"
                                                class="rounded-pill bg-brand-soft px-3 py-1 text-xs font-black text-brand"
                                                @click="deleteTier(tier)"
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="visibleCommissionTiers.length === 0">
                                    <td
                                        colspan="5"
                                        class="px-4 py-6 text-center text-sm font-semibold text-slate"
                                    >
                                        No tier for this service type.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section v-if="activeTab === 'users'" class="grid gap-5">
                <div
                    v-if="shouldShowCreateEdit('users')"
                    class="rounded-xl border border-line bg-card p-5 shadow-sm"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-black text-ink">
                            {{
                                activeMode === 'edit'
                                    ? 'Update Staff'
                                    : 'Create Staff'
                            }}
                        </h2>
                        <Link
                            :href="adminPath('users')"
                            :headers="authHeaders()"
                            class="bank-button bank-button-secondary py-2"
                        >
                            List
                        </Link>
                    </div>
                    <form
                        class="mt-4 grid gap-3 lg:grid-cols-2"
                        @submit.prevent="saveUser"
                    >
                        <label>
                            <span class="bank-label">Username</span>
                            <input
                                v-model.trim="userForm.username"
                                class="bank-input"
                                required
                            />
                        </label>
                        <label>
                            <span class="bank-label">Full Name</span>
                            <input
                                v-model.trim="userForm.full_name"
                                class="bank-input"
                                required
                            />
                        </label>
                        <label>
                            <span class="bank-label">Email</span>
                            <input
                                v-model.trim="userForm.email"
                                type="email"
                                class="bank-input"
                            />
                        </label>
                        <label>
                            <span class="bank-label">Role</span>
                            <select v-model="userForm.role" class="bank-input">
                                <option value="teller">Teller</option>
                                <option value="cashier">Cashier</option>
                                <option value="admin">Admin</option>
                            </select>
                        </label>
                        <label>
                            <span class="bank-label">Password</span>
                            <input
                                v-model="userForm.password"
                                type="password"
                                minlength="8"
                                class="bank-input"
                                :required="activeMode === 'create'"
                                :placeholder="
                                    activeMode === 'edit'
                                        ? 'Leave blank to keep current password'
                                        : ''
                                "
                            />
                        </label>
                        <label>
                            <span class="bank-label">PIN</span>
                            <input
                                v-model.trim="userForm.pin"
                                inputmode="numeric"
                                class="bank-input"
                            />
                        </label>
                        <label
                            class="flex items-center gap-2 text-sm font-bold text-ink"
                        >
                            <input
                                v-model="userForm.is_active"
                                type="checkbox"
                                class="size-4 accent-brand"
                            />
                            Active
                        </label>
                        <div class="lg:col-span-2">
                            <button
                                type="submit"
                                class="bank-button bank-button-primary"
                                :disabled="busy !== ''"
                            >
                                {{
                                    activeMode === 'edit'
                                        ? 'Update Staff'
                                        : 'Save Staff'
                                }}
                            </button>
                        </div>
                    </form>
                </div>

                <div
                    v-else-if="shouldShowDetail('users')"
                    class="grid gap-5 xl:grid-cols-[1fr_0.75fr]"
                >
                    <div
                        class="rounded-xl border border-line bg-card p-5 shadow-sm"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="text-lg font-black text-ink">
                                Staff Detail
                            </h2>
                            <div class="flex gap-2">
                                <Link
                                    :href="adminPath('users')"
                                    :headers="authHeaders()"
                                    class="bank-button bank-button-secondary py-2"
                                >
                                    List
                                </Link>
                                <Link
                                    v-if="currentUser"
                                    :href="
                                        adminPath(
                                            'users',
                                            'edit',
                                            currentUser.id,
                                        )
                                    "
                                    :headers="authHeaders()"
                                    class="bank-button bank-button-primary py-2"
                                >
                                    Edit
                                </Link>
                            </div>
                        </div>
                        <template v-if="currentUser">
                            <dl
                                class="mt-4 grid gap-3 rounded-lg bg-mist p-4 text-sm sm:grid-cols-2"
                            >
                                <div>
                                    <dt class="font-bold text-slate">Name</dt>
                                    <dd class="font-black text-ink">
                                        {{ currentUser.full_name }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">
                                        Username
                                    </dt>
                                    <dd class="font-black text-ink">
                                        {{ currentUser.username }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">Role</dt>
                                    <dd class="font-black text-ink capitalize">
                                        {{ currentUser.role }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">Email</dt>
                                    <dd class="font-black text-ink">
                                        {{ currentUser.email ?? '-' }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">PIN</dt>
                                    <dd class="font-black text-ink">
                                        {{
                                            currentUser.has_pin
                                                ? 'Set'
                                                : 'Missing'
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-bold text-slate">Status</dt>
                                    <dd class="font-black text-ink">
                                        {{
                                            currentUser.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}
                                    </dd>
                                </div>
                            </dl>
                        </template>
                        <p v-else class="mt-4 text-sm font-semibold text-slate">
                            User not found.
                        </p>
                    </div>

                    <div
                        class="rounded-xl border border-line bg-card p-5 shadow-sm"
                    >
                        <h2 class="text-lg font-black text-ink">Credentials</h2>
                        <form
                            class="mt-4 grid gap-3"
                            @submit.prevent="resetUserPassword"
                        >
                            <label>
                                <span class="bank-label">User</span>
                                <select
                                    v-model.number="credentialForm.user_id"
                                    class="bank-input"
                                    required
                                >
                                    <option :value="null" disabled>
                                        Select user
                                    </option>
                                    <option
                                        v-for="user in users"
                                        :key="user.id"
                                        :value="user.id"
                                    >
                                        {{ user.full_name }} ({{ user.role }})
                                    </option>
                                </select>
                            </label>
                            <label>
                                <span class="bank-label">New Password</span>
                                <input
                                    v-model="credentialForm.new_password"
                                    type="password"
                                    minlength="8"
                                    class="bank-input"
                                />
                            </label>
                            <button
                                type="submit"
                                class="bank-button bank-button-danger"
                                :disabled="
                                    busy !== '' ||
                                    credentialForm.new_password.length < 8
                                "
                            >
                                Reset Password
                            </button>
                        </form>
                        <form
                            class="mt-4 grid gap-3 border-t border-line pt-4"
                            @submit.prevent="setUserPin"
                        >
                            <label>
                                <span class="bank-label">New PIN</span>
                                <input
                                    v-model.trim="credentialForm.pin"
                                    inputmode="numeric"
                                    class="bank-input"
                                />
                            </label>
                            <button
                                type="submit"
                                class="bank-button bank-button-secondary"
                                :disabled="
                                    busy !== '' || credentialForm.pin.length < 4
                                "
                            >
                                Set PIN
                            </button>
                        </form>
                    </div>
                </div>

                <div
                    v-else
                    class="rounded-xl border border-line bg-card p-5 shadow-sm"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-black text-ink">Users</h2>
                        <Link
                            :href="adminPath('users', 'create')"
                            :headers="authHeaders()"
                            class="bank-button bank-button-primary py-2"
                        >
                            Create
                        </Link>
                    </div>
                    <div
                        class="mt-4 overflow-auto rounded-lg border border-line"
                    >
                        <table class="w-full min-w-[760px] text-left text-sm">
                            <thead class="bg-mist text-xs text-slate uppercase">
                                <tr>
                                    <th class="px-4 py-3">Name</th>
                                    <th class="px-4 py-3">Username</th>
                                    <th class="px-4 py-3">Role</th>
                                    <th class="px-4 py-3">PIN</th>
                                    <th class="px-4 py-3 text-right">Action</th>
                                    <th class="px-4 py-3 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="user in users" :key="user.id">
                                    <td class="px-4 py-3">
                                        <p class="font-bold text-ink">
                                            {{ user.full_name }}
                                        </p>
                                        <p
                                            class="text-xs font-semibold text-slate"
                                        >
                                            {{ user.email ?? '-' }}
                                        </p>
                                    </td>
                                    <td
                                        class="px-4 py-3 font-semibold text-slate"
                                    >
                                        {{ user.username }}
                                    </td>
                                    <td
                                        class="px-4 py-3 font-bold text-ink capitalize"
                                    >
                                        {{ user.role }}
                                    </td>
                                    <td class="px-4 py-3 text-slate">
                                        {{ user.has_pin ? 'Set' : 'Missing' }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex justify-end gap-1.5">
                                            <Link
                                                :href="
                                                    adminPath(
                                                        'users',
                                                        'detail',
                                                        user.id,
                                                    )
                                                "
                                                :headers="authHeaders()"
                                                class="rounded-pill bg-mist px-3 py-1 text-xs font-black text-slate"
                                            >
                                                View
                                            </Link>
                                            <Link
                                                :href="
                                                    adminPath(
                                                        'users',
                                                        'edit',
                                                        user.id,
                                                    )
                                                "
                                                :headers="authHeaders()"
                                                class="rounded-pill bg-ink px-3 py-1 text-xs font-black text-white"
                                            >
                                                Edit
                                            </Link>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            class="rounded-pill px-3 py-1 text-xs font-black"
                                            :class="
                                                statusClass(
                                                    statusTone(user.is_active),
                                                )
                                            "
                                            @click="toggleUser(user)"
                                        >
                                            {{
                                                user.is_active
                                                    ? 'Active'
                                                    : 'Inactive'
                                            }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section v-if="activeTab === 'transactions'" class="space-y-5">
                <div
                    v-if="shouldShowDetail('transactions')"
                    class="rounded-xl border border-line bg-card p-5 shadow-sm"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-black text-ink">
                            Transaction Detail
                        </h2>
                        <Link
                            :href="adminPath('transactions')"
                            :headers="authHeaders()"
                            class="bank-button bank-button-secondary py-2"
                        >
                            List
                        </Link>
                    </div>
                    <template v-if="currentTransaction">
                        <dl
                            class="mt-4 grid gap-3 rounded-lg bg-mist p-4 text-sm sm:grid-cols-2 xl:grid-cols-3"
                        >
                            <div>
                                <dt class="font-bold text-slate">Ref</dt>
                                <dd class="money font-black text-ink">
                                    #{{ currentTransaction.id }}
                                </dd>
                            </div>
                            <div>
                                <dt class="font-bold text-slate">Type</dt>
                                <dd class="font-black text-ink">
                                    {{
                                        transactionTypeLabel(
                                            currentTransaction.transaction_type,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="font-bold text-slate">Customer</dt>
                                <dd class="font-black text-ink">
                                    {{
                                        currentTransaction.customer_name ?? '-'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="font-bold text-slate">Amount</dt>
                                <dd class="money font-black text-ink">
                                    {{ money(currentTransaction.amount) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="font-bold text-slate">Fee</dt>
                                <dd class="money font-black text-ink">
                                    {{ money(currentTransaction.customer_fee) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="font-bold text-slate">Status</dt>
                                <dd class="font-black text-ink">
                                    {{ currentTransaction.status }}
                                </dd>
                            </div>
                            <div>
                                <dt class="font-bold text-slate">Created By</dt>
                                <dd class="font-black text-ink">
                                    {{
                                        userName(currentTransaction.created_by)
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="font-bold text-slate">Time</dt>
                                <dd class="font-black text-ink">
                                    {{
                                        dateTime(currentTransaction.created_at)
                                    }}
                                </dd>
                            </div>
                        </dl>
                    </template>
                    <p v-else class="mt-4 text-sm font-semibold text-slate">
                        Transaction not found.
                    </p>
                </div>
                <template v-else>
                    <div
                        class="rounded-xl border border-line bg-card p-5 shadow-sm"
                    >
                        <div
                            class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between"
                        >
                            <div>
                                <h2 class="text-lg font-black text-ink">
                                    Transactions
                                </h2>
                                <p class="text-sm font-semibold text-slate">
                                    Admin scope shows all employee transactions.
                                </p>
                            </div>
                            <form
                                class="grid gap-2 sm:grid-cols-4 xl:min-w-[720px]"
                                @submit.prevent="refreshTransactionsAndLogs"
                            >
                                <label>
                                    <span class="bank-label">Type</span>
                                    <select
                                        v-model="transactionFilters.type"
                                        class="bank-input py-2.5"
                                    >
                                        <option value="">All</option>
                                        <option value="cash_in">Cash In</option>
                                        <option value="cash_out">
                                            Cash Out
                                        </option>
                                        <option value="transfer">
                                            Transfer
                                        </option>
                                        <option value="exchange">
                                            Exchange
                                        </option>
                                    </select>
                                </label>
                                <label>
                                    <span class="bank-label">From</span>
                                    <input
                                        v-model="transactionFilters.date_from"
                                        type="date"
                                        class="bank-input py-2.5"
                                    />
                                </label>
                                <label>
                                    <span class="bank-label">To</span>
                                    <input
                                        v-model="transactionFilters.date_to"
                                        type="date"
                                        class="bank-input py-2.5"
                                    />
                                </label>
                                <button
                                    type="submit"
                                    class="bank-button bank-button-secondary self-end"
                                    :disabled="busy !== ''"
                                >
                                    Apply
                                </button>
                            </form>
                        </div>
                        <div
                            class="mt-4 overflow-auto rounded-lg border border-line"
                        >
                            <table
                                class="w-full min-w-[960px] text-left text-sm"
                            >
                                <thead
                                    class="bg-mist text-xs text-slate uppercase"
                                >
                                    <tr>
                                        <th class="px-4 py-3">Ref</th>
                                        <th class="px-4 py-3">Type</th>
                                        <th class="px-4 py-3">Customer</th>
                                        <th class="px-4 py-3 text-right">
                                            Amount
                                        </th>
                                        <th class="px-4 py-3 text-right">
                                            Fee
                                        </th>
                                        <th class="px-4 py-3">By</th>
                                        <th class="px-4 py-3">Status</th>
                                        <th class="px-4 py-3">Time</th>
                                        <th class="px-4 py-3 text-right">
                                            Action
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    <tr
                                        v-for="transaction in transactions"
                                        :key="transaction.id"
                                    >
                                        <td
                                            class="money px-4 py-3 font-black text-ink"
                                        >
                                            #{{ transaction.id }}
                                        </td>
                                        <td
                                            class="px-4 py-3 font-bold text-ink"
                                        >
                                            {{
                                                transactionTypeLabel(
                                                    transaction.transaction_type,
                                                )
                                            }}
                                        </td>
                                        <td class="px-4 py-3 text-slate">
                                            {{
                                                transaction.customer_name ?? '-'
                                            }}
                                        </td>
                                        <td
                                            class="money px-4 py-3 text-right font-bold text-ink"
                                        >
                                            {{ money(transaction.amount) }}
                                        </td>
                                        <td
                                            class="money px-4 py-3 text-right text-slate"
                                        >
                                            {{
                                                money(transaction.customer_fee)
                                            }}
                                        </td>
                                        <td class="px-4 py-3 text-slate">
                                            {{
                                                userName(transaction.created_by)
                                            }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <span
                                                class="rounded-pill px-3 py-1 text-xs font-black"
                                                :class="
                                                    statusClass(
                                                        transaction.status ===
                                                            'COMPLETED'
                                                            ? 'ok'
                                                            : transaction.status.includes(
                                                                    'PENDING',
                                                                )
                                                              ? 'warn'
                                                              : 'muted',
                                                    )
                                                "
                                            >
                                                {{ transaction.status }}
                                            </span>
                                        </td>
                                        <td
                                            class="px-4 py-3 text-xs font-semibold text-slate"
                                        >
                                            {{
                                                dateTime(transaction.created_at)
                                            }}
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <Link
                                                :href="
                                                    adminPath(
                                                        'transactions',
                                                        'detail',
                                                        transaction.id,
                                                    )
                                                "
                                                :headers="authHeaders()"
                                                class="rounded-pill bg-mist px-3 py-1 text-xs font-black text-slate"
                                            >
                                                View
                                            </Link>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div
                        class="rounded-xl border border-line bg-card p-5 shadow-sm"
                    >
                        <div
                            class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between"
                        >
                            <div>
                                <h2 class="text-lg font-black text-ink">
                                    Activity Logs
                                </h2>
                                <p class="text-sm font-semibold text-slate">
                                    Audit records from admin changes and money
                                    movements.
                                </p>
                            </div>
                            <form
                                class="grid gap-2 sm:grid-cols-5 xl:min-w-[840px]"
                                @submit.prevent="refreshTransactionsAndLogs"
                            >
                                <label>
                                    <span class="bank-label">User</span>
                                    <select
                                        v-model.number="logFilters.user_id"
                                        class="bank-input py-2.5"
                                    >
                                        <option :value="null">All</option>
                                        <option
                                            v-for="user in users"
                                            :key="user.id"
                                            :value="user.id"
                                        >
                                            {{ user.full_name }}
                                        </option>
                                    </select>
                                </label>
                                <label>
                                    <span class="bank-label">Action</span>
                                    <input
                                        v-model.trim="logFilters.action"
                                        class="bank-input py-2.5"
                                    />
                                </label>
                                <label>
                                    <span class="bank-label">Entity</span>
                                    <input
                                        v-model.trim="logFilters.entity_type"
                                        class="bank-input py-2.5"
                                    />
                                </label>
                                <label>
                                    <span class="bank-label">Date</span>
                                    <input
                                        v-model="logFilters.date"
                                        type="date"
                                        class="bank-input py-2.5"
                                    />
                                </label>
                                <button
                                    type="submit"
                                    class="bank-button bank-button-secondary self-end"
                                    :disabled="busy !== ''"
                                >
                                    Apply
                                </button>
                            </form>
                        </div>
                        <div
                            class="mt-4 overflow-auto rounded-lg border border-line"
                        >
                            <table
                                class="w-full min-w-[980px] text-left text-sm"
                            >
                                <thead
                                    class="bg-mist text-xs text-slate uppercase"
                                >
                                    <tr>
                                        <th class="px-4 py-3">Time</th>
                                        <th class="px-4 py-3">User</th>
                                        <th class="px-4 py-3">Action</th>
                                        <th class="px-4 py-3">Entity</th>
                                        <th class="px-4 py-3">Details</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    <tr
                                        v-for="log in activityLogs"
                                        :key="log.id"
                                    >
                                        <td
                                            class="px-4 py-3 text-xs font-semibold text-slate"
                                        >
                                            {{ dateTime(log.created_at) }}
                                        </td>
                                        <td
                                            class="px-4 py-3 font-bold text-ink"
                                        >
                                            {{
                                                log.user?.full_name ??
                                                log.user?.username ??
                                                userName(log.user_id)
                                            }}
                                        </td>
                                        <td class="px-4 py-3 text-slate">
                                            {{ log.action }}
                                        </td>
                                        <td class="px-4 py-3 text-slate">
                                            {{ log.entity_type ?? '-' }} #{{
                                                log.entity_id ?? '-'
                                            }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-xs font-semibold text-slate"
                                        >
                                            {{ compactJson(log.details) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>
            </section>

            <section
                v-if="activeTab === 'vault'"
                class="grid gap-5 xl:grid-cols-[0.8fr_1.2fr]"
            >
                <div class="space-y-5">
                    <div
                        class="rounded-xl border border-line bg-card p-5 shadow-sm"
                    >
                        <h2 class="text-lg font-black text-ink">Main Vault</h2>
                        <p class="money mt-2 text-3xl font-black text-ink">
                            {{ money(vaultInventory?.main_vault_total) }}
                        </p>
                        <div
                            class="mt-4 overflow-hidden rounded-lg border border-line"
                        >
                            <table class="w-full text-left text-sm">
                                <tbody class="divide-y divide-line">
                                    <tr
                                        v-for="row in vaultDenominations"
                                        :key="row.denomination"
                                    >
                                        <td
                                            class="money px-4 py-3 font-bold text-ink"
                                        >
                                            {{ money(row.denomination) }}
                                        </td>
                                        <td
                                            class="money px-4 py-3 text-right text-slate"
                                        >
                                            {{ row.quantity }}
                                        </td>
                                        <td
                                            class="money px-4 py-3 text-right font-bold text-ink"
                                        >
                                            {{ money(row.total) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div
                        class="rounded-xl border border-line bg-card p-5 shadow-sm"
                    >
                        <h2 class="text-lg font-black text-ink">System</h2>
                        <dl class="mt-4 space-y-2 text-sm">
                            <div
                                class="flex items-center justify-between rounded-lg bg-mist px-3 py-2"
                            >
                                <dt class="font-bold text-slate">Name</dt>
                                <dd class="font-black text-ink">
                                    {{
                                        systemStatus?.name ?? 'Ngwe Lwe System'
                                    }}
                                </dd>
                            </div>
                            <div
                                class="flex items-center justify-between rounded-lg bg-mist px-3 py-2"
                            >
                                <dt class="font-bold text-slate">Status</dt>
                                <dd class="font-black text-ink">
                                    {{ systemStatus?.status ?? '-' }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div class="space-y-5">
                    <div
                        class="rounded-xl border border-line bg-card p-5 shadow-sm"
                    >
                        <h2 class="text-lg font-black text-ink">Cash Floats</h2>
                        <div
                            class="mt-4 overflow-auto rounded-lg border border-line"
                        >
                            <table
                                class="w-full min-w-[720px] text-left text-sm"
                            >
                                <thead
                                    class="bg-mist text-xs text-slate uppercase"
                                >
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
                                        v-for="float in cashFloats"
                                        :key="float.id"
                                    >
                                        <td
                                            class="money px-4 py-3 font-black text-ink"
                                        >
                                            #{{ float.id }}
                                        </td>
                                        <td class="px-4 py-3 text-slate">
                                            {{
                                                float.employee_name ??
                                                `Employee #${float.employee_id}`
                                            }}
                                        </td>
                                        <td class="px-4 py-3 text-slate">
                                            {{ float.issued_by_name ?? '-' }}
                                        </td>
                                        <td
                                            class="money px-4 py-3 text-right font-bold text-ink"
                                        >
                                            {{ money(float.current_balance) }}
                                        </td>
                                        <td class="px-4 py-3 text-slate">
                                            {{ float.status }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div
                        class="rounded-xl border border-line bg-card p-5 shadow-sm"
                    >
                        <h2 class="text-lg font-black text-ink">Vault Log</h2>
                        <div
                            class="mt-4 overflow-auto rounded-lg border border-line"
                        >
                            <table
                                class="w-full min-w-[860px] text-left text-sm"
                            >
                                <thead
                                    class="bg-mist text-xs text-slate uppercase"
                                >
                                    <tr>
                                        <th class="px-4 py-3">Time</th>
                                        <th class="px-4 py-3">Type</th>
                                        <th class="px-4 py-3">Note</th>
                                        <th class="px-4 py-3 text-right">
                                            Qty
                                        </th>
                                        <th class="px-4 py-3">By</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    <tr v-for="log in vaultLogs" :key="log.id">
                                        <td
                                            class="px-4 py-3 text-xs font-semibold text-slate"
                                        >
                                            {{ dateTime(log.created_at) }}
                                        </td>
                                        <td
                                            class="px-4 py-3 font-bold text-ink"
                                        >
                                            {{ log.txn_type }}
                                        </td>
                                        <td class="px-4 py-3 text-slate">
                                            {{ log.note ?? '-' }}
                                        </td>
                                        <td
                                            class="money px-4 py-3 text-right text-slate"
                                        >
                                            {{ money(log.denomination) }} x
                                            {{ log.quantity }}
                                        </td>
                                        <td class="px-4 py-3 text-slate">
                                            {{ log.performed_by_name ?? '-' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <section
                v-if="activeTab === 'reports'"
                class="grid gap-5 xl:grid-cols-[1.1fr_0.9fr]"
            >
                <div class="space-y-5">
                    <div
                        class="rounded-xl border border-line bg-card p-5 shadow-sm"
                    >
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
                        >
                            <div>
                                <h2 class="text-lg font-black text-ink">
                                    Daily Closing
                                </h2>
                                <p class="text-sm font-semibold text-slate">
                                    {{ reportDate }}
                                </p>
                            </div>
                            <label class="min-w-44">
                                <span class="bank-label">Date</span>
                                <input
                                    v-model="reportDate"
                                    type="date"
                                    class="bank-input py-2.5"
                                />
                            </label>
                        </div>
                        <div class="mt-4 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-lg bg-mist p-4">
                                <p class="text-xs font-bold text-slate">Cash</p>
                                <p class="money mt-1 font-black text-ink">
                                    {{ money(dailySummary?.total_cash) }}
                                </p>
                            </div>
                            <div class="rounded-lg bg-mist p-4">
                                <p class="text-xs font-bold text-slate">
                                    Digital
                                </p>
                                <p class="money mt-1 font-black text-ink">
                                    {{ money(dailySummary?.total_digital) }}
                                </p>
                            </div>
                            <div class="rounded-lg bg-mist p-4">
                                <p class="text-xs font-bold text-slate">
                                    Grand Total
                                </p>
                                <p class="money mt-1 font-black text-ink">
                                    {{ money(dailySummary?.grand_total) }}
                                </p>
                            </div>
                        </div>
                        <form
                            class="mt-4 grid gap-3"
                            @submit.prevent="closeDay"
                        >
                            <label>
                                <span class="bank-label">Closing Notes</span>
                                <textarea
                                    v-model.trim="closeNotes"
                                    rows="3"
                                    class="bank-input resize-none"
                                />
                            </label>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="bank-button bank-button-secondary"
                                    :disabled="busy !== ''"
                                    @click="refreshDailySummary"
                                >
                                    Refresh Summary
                                </button>
                                <button
                                    type="submit"
                                    class="bank-button bank-button-danger"
                                    :disabled="busy !== ''"
                                >
                                    Close Day
                                </button>
                            </div>
                        </form>
                    </div>

                    <div
                        class="rounded-xl border border-line bg-card p-5 shadow-sm"
                    >
                        <h2 class="text-lg font-black text-ink">
                            Reconciliation History
                        </h2>
                        <div
                            class="mt-4 overflow-auto rounded-lg border border-line"
                        >
                            <table
                                class="w-full min-w-[760px] text-left text-sm"
                            >
                                <thead
                                    class="bg-mist text-xs text-slate uppercase"
                                >
                                    <tr>
                                        <th class="px-4 py-3">Date</th>
                                        <th class="px-4 py-3">Closed By</th>
                                        <th class="px-4 py-3 text-right">
                                            Cash
                                        </th>
                                        <th class="px-4 py-3 text-right">
                                            Digital
                                        </th>
                                        <th class="px-4 py-3 text-right">
                                            Grand
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    <tr
                                        v-for="row in reconciliations"
                                        :key="row.id"
                                    >
                                        <td
                                            class="px-4 py-3 font-bold text-ink"
                                        >
                                            {{ row.recon_date ?? '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-slate">
                                            {{ row.closed_by_name ?? '-' }}
                                        </td>
                                        <td
                                            class="money px-4 py-3 text-right text-slate"
                                        >
                                            {{ money(row.total_cash) }}
                                        </td>
                                        <td
                                            class="money px-4 py-3 text-right text-slate"
                                        >
                                            {{ money(row.total_digital) }}
                                        </td>
                                        <td
                                            class="money px-4 py-3 text-right font-bold text-ink"
                                        >
                                            {{ money(row.grand_total) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="space-y-5">
                    <div
                        class="rounded-xl border border-line bg-card p-5 shadow-sm"
                    >
                        <h2 class="text-lg font-black text-ink">
                            Admin Password
                        </h2>
                        <form
                            class="mt-4 grid gap-3"
                            @submit.prevent="changePassword"
                        >
                            <label>
                                <span class="bank-label">Old Password</span>
                                <input
                                    v-model="passwordForm.old_password"
                                    type="password"
                                    class="bank-input"
                                    required
                                />
                            </label>
                            <label>
                                <span class="bank-label">New Password</span>
                                <input
                                    v-model="passwordForm.new_password"
                                    type="password"
                                    minlength="8"
                                    class="bank-input"
                                    required
                                />
                            </label>
                            <button
                                type="submit"
                                class="bank-button bank-button-primary"
                                :disabled="busy !== ''"
                            >
                                Change Password
                            </button>
                        </form>
                    </div>

                    <div
                        class="rounded-xl border border-line bg-card p-5 shadow-sm"
                    >
                        <h2 class="text-lg font-black text-ink">
                            Server Tools
                        </h2>
                        <div class="mt-4 grid gap-3">
                            <button
                                type="button"
                                class="bank-button bank-button-secondary"
                                :disabled="busy !== ''"
                                @click="sendBroadcastTest"
                            >
                                Broadcast Test
                            </button>
                            <button
                                type="button"
                                class="bank-button bank-button-danger"
                                :disabled="busy !== ''"
                                @click="createBackup"
                            >
                                Create Backup
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </BankLayout>
</template>
