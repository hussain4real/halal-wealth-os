export type LedgerMoney = { currency: string; minor: string; amount: string };
export type LedgerAccount = {
    id: string;
    name: string;
    currency: string;
    exponent: number;
    archived: boolean;
};
export type FxOperation = {
    id: string;
    reference: string;
    status: string;
    description: string;
    effective_date: string;
    recorded_at: string;
    source: { account_id: string; name: string; money: LedgerMoney };
    destination: { account_id: string; name: string; money: LedgerMoney };
    fee: { side: string; money: LedgerMoney };
    actual_rate: {
        id: string;
        value: string;
        source: string;
        observed_at: string;
        effective_at: string;
    };
    implied_rate: {
        numerator: string;
        denominator: string;
        display: string;
        rounded: boolean;
    };
    journals: { id: string; reference: string; role: string; status: string }[];
    reversal: { reference: string; reason: string; recorded_at: string } | null;
    replaces_id: string | null;
    replacement_id: string | null;
};
export type RateObservation = {
    id: string;
    base_currency: string;
    quote_currency: string;
    value: string;
    version: string;
    observed_at: string;
    effective_at: string;
    source: string;
    supersedes_id: string | null;
    correction_reason: string | null;
};
export type SnapshotComponent = {
    account_id: string;
    name: string;
    type: string;
    currency: string;
    archived: boolean;
    native: LedgerMoney | null;
    native_reason: string | null;
    state: string;
    converted: LedgerMoney | null;
    conversion: string;
    rate: {
        id: string;
        value: string;
        source: string;
        observed_at: string;
        effective_at: string;
        base: string;
        quote: string;
        version: string;
    } | null;
    opening: {
        intent_id: string;
        minor: string;
        journal_id: string | null;
        effective_date: string | null;
        state: string;
    } | null;
    journals: {
        posting_id: string;
        journal_id: string;
        minor: string;
        effective_date: string;
    }[];
};
export type FinancialEvidence = {
    policy: string;
    base_currency: string;
    cutoff: string;
    captured_at: string;
    state: string;
    total: LedgerMoney | null;
    covered_subtotal: LedgerMoney | null;
    subtotal_minor_exact: string;
    covered_accounts: number;
    components: SnapshotComponent[];
};
export type Reconciliation = {
    id: string;
    reference: string;
    account_id: string;
    status: string;
    currency: string;
    statement: LedgerMoney;
    ledger: LedgerMoney | null;
    difference_minor: string | null;
    difference_amount: string | null;
    cutoff: string;
    recorded_at: string;
    supersedes_id: string | null;
};
