export type Role = 'owner' | 'cashier' | 'employee';

export type SessionUser = {
    id: number;
    username: string;
    full_name: string | null;
    role: Role;
    is_active: boolean;
    created_at: string | null;
    updated_at: string | null;
};

export type ManagedUser = SessionUser & {
    email: string | null;
    auth_version: number;
    has_pin: boolean;
};

export type LoginResponse = {
    token: string;
    user: SessionUser;
};

export type ApiCollection<T> = {
    data: T[];
    meta?: {
        current_page?: number;
        last_page?: number;
        per_page?: number;
        total?: number;
    };
};

export type ApiItem<T> = {
    data: T;
};

export type Company = {
    id: number;
    name: string;
    category: string | null;
    is_active: boolean;
};

export type ServiceType = {
    id: number;
    company_id: number;
    name: string;
    operation: string;
    is_active: boolean;
    company?: Company;
};

export type Account = {
    id: number;
    service_type_id: number;
    account_name: string;
    phone_number: string | null;
    balance: string;
    commission_rate: string | null;
    is_active: boolean;
    is_fee_account: boolean;
    service_type?: ServiceType;
};

export type Transaction = {
    id: number;
    transaction_type: string;
    account_id: number | null;
    to_account_id: number | null;
    customer_name: string | null;
    customer_phone: string | null;
    amount: string;
    customer_fee: string | null;
    currency: string | null;
    exchange_rate: string | null;
    created_by: number | null;
    created_at: string | null;
    status: string;
    vault_impact: string | null;
};

export type FloatDenomination = {
    denomination: number;
    quantity: number;
};

export type CashFloat = {
    id: number;
    employee_id: number;
    employee_name: string | null;
    issued_by: number;
    issued_by_name: string | null;
    status: string;
    total_amount: string;
    current_balance: string | null;
    closing_total: string | null;
    return_denominations_json: Record<string, number> | null;
    received_at: string | null;
    closed_at: string | null;
    note: string | null;
    created_at: string | null;
    denominations?: FloatDenomination[];
};

export type VaultInventory = {
    main_vault: Record<string, number>;
    main_vault_total: number;
    employee_floats: Array<{
        float_id: number;
        employee_id: number;
        employee_name: string | null;
        status: string;
        current_balance: string;
        total_amount: string;
        denomination_balance: Record<string, number>;
        denom_total: number;
    }>;
    total_employee_cash: number;
    grand_physical_total: number;
};

export type VaultTransaction = {
    id: number;
    txn_type: string;
    float_id: number | null;
    denomination: number;
    quantity: number;
    transaction_id: number | null;
    performed_by_name: string | null;
    verified_by_name: string | null;
    note: string | null;
    created_at: string | null;
};

export type ReportDenominationRow = {
    denomination: number;
    quantity: number;
    total: number;
};

export type ReportEmployeeSnapshot = {
    float_id: number;
    employee_id: number;
    employee_name: string | null;
    status: string;
    current_balance: string;
    total_amount: string;
    denomination_balance: Record<string, number>;
    denom_total: string;
};

export type ReportAccountSnapshot = {
    id: number;
    account_name: string;
    service_type: string | null;
    company: string | null;
    balance: string;
    is_fee_account: boolean;
};

export type ReportVaultSnapshot = {
    denominations: Record<string, number>;
    denomination_rows: ReportDenominationRow[];
    total: string;
};

export type DailySummaryReport = {
    summary_date: string;
    total_cash_in: string;
    total_cash_out: string;
    total_transfer: string;
    total_exchange: string;
    total_commission: string;
    total_customer_fees: string;
    total_profit: string;
    transaction_count: number;
    pending_cash_in_count: number;
    main_vault_total: string;
    employee_floats_total: string;
    total_cash: string;
    total_digital: string;
    grand_total: string;
    vault_snapshot: ReportVaultSnapshot;
    employee_snapshots: ReportEmployeeSnapshot[];
    account_snapshots: ReportAccountSnapshot[];
};

export type DailyReconciliation = {
    id: number;
    recon_date: string;
    closed_by: number;
    closed_by_name: string | null;
    closed_at: string | null;
    total_cash_in: string;
    total_cash_out: string;
    total_transfer: string;
    total_exchange: string;
    total_commission: string;
    total_customer_fees: string;
    main_vault_total: string;
    employee_floats_total: string;
    total_cash: string;
    total_digital: string;
    grand_total: string;
    employee_snapshots: ReportEmployeeSnapshot[] | null;
    account_snapshots: ReportAccountSnapshot[] | null;
    vault_snapshot: ReportVaultSnapshot | null;
    notes: string | null;
};

export type ExchangeRate = {
    id: number | null;
    base_currency: string;
    quote_currency: string;
    base_amount: string;
    buy_rate: string;
    sell_rate: string;
};

export type DenominationMap = Record<string, number>;
