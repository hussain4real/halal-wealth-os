import type { ExactMoney } from './accounts';
import type { AssessmentSummary } from './shariah';

export type DecisionStatus = 'watchlist' | 'planned' | 'closed';
export type TrancheDraft = {
    key: string;
    label: string;
    amount: string;
    condition: string;
};
export type DecisionEvidence = {
    value: string | null;
    url: string | null;
    verified_on: string | null;
    review_on: string | null;
    official: boolean;
    verified: boolean;
};
export type Decision = {
    id: string;
    reference: string;
    series_id: string;
    version: string;
    supersedes_id: string | null;
    name: string;
    status: DecisionStatus;
    asset_id: string | null;
    currency: string;
    target: ExactMoney | null;
    maximum: ExactMoney | null;
    tranches: {
        key: string;
        label: string;
        condition: string;
        amount_minor: string;
        amount?: ExactMoney;
        reserved_amount?: string;
        spent_amount?: string;
        remaining_amount?: string;
        remaining_minor?: string;
    }[];
    attributes: {
        rationale: string | null;
        risks: string | null;
        horizon: string | null;
        review_triggers: string | null;
        review_on: string | null;
        concentration: {
            source: string | null;
            on: string | null;
            review_on: string | null;
        };
        evidence: Record<'status' | 'price' | 'eligibility', DecisionEvidence>;
    };
    concentration: {
        state: string;
        percent: string | null;
        threshold: string | null;
        explanation: string;
    };
    review_overdue: boolean;
    recorded_at: string;
    reason: string;
    actor_id: string;
    actor_is_admin: boolean;
};
export type DecisionReservation = {
    id: string;
    reference: string;
    opportunity_id: string;
    tranche_key: string;
    reservation_id: string;
    goal_id: string;
    account_id: string;
    amount: ExactMoney;
    released: boolean;
    reason: string;
    release_reason: string | null;
    backing: {
        amount: string | null;
        shortfall: string | null;
        reason: string | null;
    };
};
export type DecisionLink = {
    id: string;
    opportunity_id: string;
    tranche_key: string;
    event_id: string;
    event_reference: string;
    portfolio_account_id: string;
    kind: string;
    source_changed: boolean;
    amount: string;
    reason: string;
};
export type DecisionAssessment = { summary: AssessmentSummary } | null;
export const decisionLabels: Record<DecisionStatus, string> = {
    watchlist: 'Watchlist',
    planned: 'Proposal',
    closed: 'Closed',
};
