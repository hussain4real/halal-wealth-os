import type { Assessment } from './shariah';
export type NativeMoney = { currency: string; minor: string; amount: string };
export type Distribution = {
    id: string;
    asset_id: string;
    portfolio_account_id: string;
    reference: string;
    effective_date: string;
    currency: string;
    amount: NativeMoney;
    fee: NativeMoney;
    tax: NativeMoney;
};
export type Obligation = {
    id: string;
    reference: string;
    series_id: string;
    version: string;
    distribution_id: string;
    assessment_id: string | null;
    method_reference: string;
    method_version: string | null;
    source_instruction: string | null;
    base_kind: string;
    base: NativeMoney | null;
    base_explanation: string | null;
    calculation: string;
    rate_percent: string | null;
    fixed: NativeMoney | null;
    due: NativeMoney | null;
    currency: string;
    reason: string;
    recorded_at: string;
    actor_id: string;
    actor_is_admin: boolean;
    status: string;
    paid: NativeMoney;
    outstanding: NativeMoney | null;
    excess: NativeMoney;
    source_changed: boolean;
    pending_explanation: string | null;
    assessment: Assessment | null;
    assessment_superseded: boolean;
};
export type Payment = {
    id: string;
    reference: string;
    payment_reference: string;
    journal_id: string;
    journal_status: string;
    account_id: string;
    currency: string;
    amount: NativeMoney;
    allocated: NativeMoney;
    available: NativeMoney;
    evidence: string;
    reason: string;
    recorded_at: string;
    effective_date: string;
    actor_id: string;
    actor_is_admin: boolean;
};
export type Allocation = {
    id: string;
    reference: string;
    obligation_id: string;
    obligation_version: string;
    payment_id: string;
    journal_id: string;
    journal_status: string;
    release_id: string | null;
    active: boolean;
    amount: NativeMoney;
    reason: string;
    recorded_at: string;
    actor_id: string;
};
export const statusLabels: Record<string, string> = {
    pending: 'Pending instructions',
    outstanding: 'Outstanding',
    partially_paid: 'Partially paid',
    settled: 'Settled',
};
