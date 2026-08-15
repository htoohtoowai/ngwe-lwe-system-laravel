export type FeeKind = 'provider' | 'agent' | 'transfer';
export type CalculationType = 'FIXED' | 'PERCENTAGE';
export type PageMode = 'list' | 'create' | 'edit' | 'detail';

export type FeeCompany = {
    id: number;
    name: string;
    logo_url: string | null;
    category: 'Pay' | 'Bank' | 'Both';
    is_active: boolean;
};

export type FeeFeature = {
    value: string;
    label: string;
};

export type CalculationTypeOption = {
    value: CalculationType;
    label: string;
};

export type ProviderTier = {
    id: number;
    company_id: number;
    company_name: string;
    feature: string;
    amount_from: string;
    amount_to: string;
    fee_type: CalculationType;
    fee_value: string;
    additional_fee_type: CalculationType;
    additional_fee_value: string;
    is_active: boolean;
};

export type AgentCommissionTier = {
    id: number;
    company_id: number;
    company_name: string;
    amount_from: string;
    amount_to: string;
    commission_type: CalculationType;
    out_commission_value: string;
    in_commission_value: string;
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
    fee_type: CalculationType;
    fee_value: string;
    additional_fee_type: CalculationType;
    additional_fee_value: string;
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
    calculationTypes: CalculationTypeOption[];
    providerTiers: ProviderTier[];
    agentCommissionTiers: AgentCommissionTier[];
    transferTiers: TransferTier[];
};
