export type AdminMode = 'list' | 'detail' | 'create' | 'edit';

export type AdminOperationPageProps = {
    role: 'admin';
    mode?: AdminMode;
    resourceId?: number | null;
    announcement?: string | null;
    notificationCount?: number;
};

export type AdminBranchOption = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
};

export type AdminTransactionRow = {
    id: number;
    branch_id?: number | null;
    transaction_type: string;
    customer_name: string | null;
    customer_phone?: string | null;
    amount: string | number;
    customer_fee: string | number | null;
    status: string;
    created_at?: string | null;
    created_by?: number | null;
};

export type AdminTransactionPageProps = {
    role: 'admin';
    announcement?: string | null;
    notificationCount?: number;
    rows: AdminTransactionRow[];
    branches: AdminBranchOption[];
    selectedBranchId: number;
};

export type CashierDenoms = Record<number, number>;

export type CashierTeller = {
    id: number;
    name: string;
    open_float_id?: number | null;
    open_float_status?: string | null;
    pending_additional_issues?: number;
};

export type CashierFloatRow = {
    id: number;
    employee_id: number;
    employee_name: string;
    status: string;
    total_amount: string;
    current_balance: string;
    closing_total: string;
    return_denominations_json: CashierDenoms | null;
    created_at: string | null;
    received_at: string | null;
    closed_at: string | null;
    denominations: {
        denomination: number;
        quantity: number;
    }[];
};

export type CashierVaultLogDetail = {
    id: number;
    denomination: number;
    quantity: number;
    amount: number;
};

export type CashierVaultReconciliationStatus =
    | 'matched'
    | 'mismatch'
    | 'missing_cash_log'
    | 'legacy_unlinked'
    | 'not_applicable';

export type CashierVaultLog = {
    id: number;
    batch_id: string | null;
    type: string;
    movement_type: string | null;
    source_type: string | null;
    source_id: number | null;
    destination_type: string | null;
    destination_id: number | null;
    float_id: number | null;
    transaction_id: number | null;
    total_amount: number;
    denomination_count: number;
    details: CashierVaultLogDetail[];
    note: string | null;
    performed_by: string | null;
    verified_by: string | null;
    created_at: string | null;
    reconciliation_status: CashierVaultReconciliationStatus;
    reconciliation_issues: string[];
};

export type CashierRecentTransaction = {
    id: number;
    type: string;
    amount: string;
    fee: string;
    status: string;
    customer: string | null;
    teller: string;
    created_at: string | null;
};

export type CashierPendingCashIn = {
    id: number;
    transaction_type: string;
    amount: string;
    customer_name: string | null;
    teller: string;
    creator_role?: 'admin' | 'cashier' | 'teller' | string | null;
    settlement_amount?: string | null;
    customer_fee?: string | null;
    fee_payment_method?: string | null;
    received_denominations: CashierDenoms;
    handoff_denominations: CashierDenoms;
    change_denominations: CashierDenoms;
    change_given: string;
    created_at: string | null;
};

export type CashierSection =
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
    | 'teller-entry-history-send-money'
    | 'teller-entry-history-receive-money'
    | 'main-vault-audit-log';

export type CashierOperationsPageProps = {
    role: 'cashier';
    section: CashierSection;
    announcement?: string | null;
    notificationCount?: number;
    notes: number[];
    mainVault: Record<string, number>;
    availableVault: Record<string, number>;
    vaultTotal: number;
    vaultLogs: CashierVaultLog[];
    floats: CashierFloatRow[];
    tellers: CashierTeller[];
    transactions: CashierRecentTransaction[];
    pendingCashIns: CashierPendingCashIn[];
};
