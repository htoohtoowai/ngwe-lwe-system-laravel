export type TransactionSemanticType =
    | 'cash_in'
    | 'cash_out'
    | 'transfer'
    | 'exchange';

type TransactionTone = {
    text: string;
    badge: string;
    card: string;
    dot: string;
    icon: string;
    sidebarIcon: string;
};

const fallback: TransactionTone = {
    text: 'text-ink',
    badge: 'border-line bg-mist text-ink',
    card: 'border-line bg-mist',
    dot: 'bg-slate',
    icon: 'bg-mist text-slate',
    sidebarIcon: 'bg-white/10 text-white/70',
};

const tones: Record<TransactionSemanticType, TransactionTone> = {
    cash_in: {
        text: 'text-credit',
        badge: 'border-credit/25 bg-credit/5 text-credit',
        card: 'border-credit/25 bg-credit/5',
        dot: 'bg-credit',
        icon: 'bg-credit/10 text-credit',
        sidebarIcon: 'bg-credit text-white',
    },
    cash_out: {
        text: 'text-debit',
        badge: 'border-debit/25 bg-debit/5 text-debit',
        card: 'border-debit/25 bg-debit/5',
        dot: 'bg-debit',
        icon: 'bg-debit/10 text-debit',
        sidebarIcon: 'bg-debit text-white',
    },
    transfer: {
        text: 'text-ink-700',
        badge: 'border-ink-700/20 bg-ink-100/60 text-ink-700',
        card: 'border-ink-700/20 bg-ink-100/45',
        dot: 'bg-ink-700',
        icon: 'bg-ink-100 text-ink-700',
        sidebarIcon: 'bg-ink-700 text-white',
    },
    exchange: {
        text: 'text-held',
        badge: 'border-held/25 bg-held/5 text-held',
        card: 'border-held/25 bg-held/5',
        dot: 'bg-held',
        icon: 'bg-held/10 text-held',
        sidebarIcon: 'bg-held text-white',
    },
};

export function normalizeTransactionType(
    value: string | null | undefined,
): TransactionSemanticType | null {
    const normalized = String(value ?? '')
        .trim()
        .toLowerCase()
        .replaceAll('-', '_')
        .replaceAll(' ', '_');

    if (normalized.includes('cash_in')) return 'cash_in';
    if (normalized.includes('cash_out')) return 'cash_out';
    if (normalized.includes('transfer')) return 'transfer';
    if (normalized.includes('exchange')) return 'exchange';

    return null;
}

export function transactionTone(
    value: string | null | undefined,
): TransactionTone {
    const type = normalizeTransactionType(value);
    return type ? tones[type] : fallback;
}
