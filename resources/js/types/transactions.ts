export type Movement = {
    account_id: string;
    name: string;
    currency: string;
    minor: string;
    amount: string;
    internal: boolean;
};

export type Transaction = {
    id: string;
    reference: string;
    import_receipt_id: string | null;
    kind:
        | 'income'
        | 'expense'
        | 'transfer'
        | 'opening'
        | 'reversal'
        | 'adjustment';
    status: 'pending' | 'posted' | 'reversed';
    description: string;
    effective_date: string;
    recorded_at: string;
    receipt_reference: string | null;
    reverses_id: string | null;
    replaces_id: string | null;
    fx_operation_id: string | null;
    investment: { event_id: string; portfolio_account_id: string } | null;
    primary: Movement | null;
    movements: Movement[];
};

export type TransactionAccount = {
    id: string;
    name: string;
    currency: string;
    exponent: number;
    balance: string | null;
    balance_reason: string | null;
};
