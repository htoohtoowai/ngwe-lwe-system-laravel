export type FeeKind = 'provider' | 'transfer';
export type FeeType = 'FIXED' | 'PERCENTAGE';
export type PageMode = 'list' | 'create' | 'edit' | 'detail';

export type FeeCompany = {
    id: number;
    name: string;
    logo_url: string | null;
    is_active: boolean;
};

export type FeeFeature = {
    value: string;
    label: string;
};

export type ProviderTier = {
    id: number;
    company_id: number;
    company_name: string;
    feature: string;
    amount_from: string;
    amount_to: string;
    fee_type: FeeType;
    fee_amount: string;
    additional_fee_type: FeeType;
    additional_fee_amount: string;
    comm_type: FeeType;
    comm_amount: string;
    is_active: boolean;
};

export type TransferTier = {
    id: number;
    company_from_id: number;
    company_from_name: string;
    company_to_id: number;
    company_to_name: string;
    amount_from: string;
    amount_to: string;
    fee_type: FeeType;
    fee_amount: string;
    additional_fee_type: FeeType;
    additional_fee_amount: string;
    is_active: boolean;
};

export type FeeManagementProps = {
    role: 'admin';
    announcement?: string | null;
    notificationCount?: number;
    mode: PageMode;
    editorKind: FeeKind;
    initialKind: FeeKind;
    resourceId?: number | null;
    companies: FeeCompany[];
    features: FeeFeature[];
    providerTiers: ProviderTier[];
    transferTiers: TransferTier[];
};
