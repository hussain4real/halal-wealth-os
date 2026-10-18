import type { ExactMoney } from './accounts';
export type Page<T> = { items: T[]; next: string | null };
export type Asset = {
    id: string;
    family: string;
    version: string;
    prior_id: string;
    name: string;
    kind: string;
    currency: string;
    identifier: string | null;
    market: string | null;
    source: string;
    unsupported_terms: string | null;
    reason: string;
};
export type Position = {
    id: string;
    asset_id: string;
    asset_family: string;
    asset_version: string;
    name: string;
    kind: string;
    currency: string;
    identifier: string | null;
    market: string | null;
    asset_source: string;
    unsupported_terms: string | null;
    quantity: string | null;
    unit_price: string | null;
    manual_value: ExactMoney | null;
    basis_known: boolean;
    cost_basis: ExactMoney | null;
};
export type Contribution = {
    mode: string | null;
    state: string;
    value: ExactMoney | null;
    missing: string[];
    selected: string[];
    excluded: string[];
    as_of: string;
    source: string;
};
export type OpeningEvidence = {
    id: string;
    reference: string;
    version: string;
    as_of: string;
    source: string;
    reason: string;
};
export type PortfolioAccount = {
    portfolio_id: string;
    account_id: string;
    name: string;
    currency: string;
    archived: boolean;
    platform_label: string;
    source: string;
    mode: string | null;
    mode_id: string | null;
    mode_version: string | null;
    aggregate_value: ExactMoney | null;
    aggregate_as_of: string | null;
    opening: OpeningEvidence | null;
    position_count: number;
    ledger_cash: ExactMoney | null;
    contribution: Contribution;
    date: string;
};
export type OpeningRow = {
    asset_id: string;
    quantity: string;
    unit_price: string;
    manual_value: string;
    basis_known: string;
    cost_basis: string;
};
export const modeLabel = (mode: string | null) =>
    mode === 'aggregate'
        ? 'Whole-account value'
        : mode === 'detailed'
          ? 'Detailed holdings'
          : mode === 'legacy_ledger'
            ? 'Ledger cash only'
            : 'Mode needs review';
export const kindLabel = (kind: string) =>
    kind === 'unitized_fund'
        ? 'Unitized fund'
        : kind === 'equity'
          ? 'Equity'
          : 'Manually valued investment';
export const evidenceLabel = (value: string) =>
    ({
        whole_account_value_including_cash:
            'Whole-account value, including cash',
        ledger_cash: 'Posted ledger cash',
        opening_positions: 'Opening positions',
        paid_ipo_receivables: 'Paid IPO receivables, not shares',
        aggregate_value_precedes_investment_events:
            'New investment facts require a fresh whole-account value review.',
        whole_account_value: 'Whole-account value',
        mode_not_confirmed: 'Choose and confirm a valuation mode.',
        aggregate_value_missing_for_date:
            'No whole-account value for this UTC date.',
    })[value] ??
    (value.startsWith('position:')
        ? 'A position has a missing, older or unrepresentable value.'
        : 'Ledger cash is unavailable. Review the account opening and journals.');
