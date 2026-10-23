import type { ObservationTiming, OpeningTiming } from './observation-time';
import type { ExactMoney } from './accounts';
export type Ratio = { numerator: string; denominator: string; percent: string };
export type EvidenceRate = (ObservationTiming | OpeningTiming) & {
    id?: string | null;
    value: string;
    source: string;
    version?: string;
};
export type ValuationComponent = {
    portfolio_account_id?: string;
    account_id: string;
    account: string;
    currency: string;
    kind: string;
    asset: string;
    geography: string;
    native: ExactMoney | null;
    converted: ExactMoney | null;
    reason: string | null;
    quantity?: string | null;
    price?: EvidenceRate | null;
    fx: EvidenceRate | null;
    native_cost?: ExactMoney | null;
    base_cost?: ExactMoney | null;
    native_unrealized_gain?: ExactMoney | null;
    base_unrealized_gain?: ExactMoney | null;
    cost_reason?: string | null;
    geography_source?: string | null;
};
export type AllocationGroup = {
    label: string;
    minor: string;
    known_components: number;
    missing_components: number;
    weight: Ratio | null;
};
export type ValuationPoint = {
    total_reason?: string | null;
    financial_results?: {
        account_id: string;
        account: string;
        currency: string;
        results: {
            receivable_refund_FX_gain: ExactMoney | null;
            range: string;
            realized_gain: ExactMoney | null;
            native_realized_gain: ExactMoney | null;
            net_income: ExactMoney | null;
            native_net_income: ExactMoney | null;
            standalone_fees: ExactMoney | null;
            native_standalone_fees: ExactMoney | null;
        };
    }[];
    state: string;
    total: ExactMoney | null;
    covered_subtotal: ExactMoney | null;
    covered_components: number;
    component_count: number;
    components?: ValuationComponent[];
    allocation: {
        denominator_minor: string;
        denominator: string;
        coverage: string;
        percentage_policy: string;
        dimensions: Record<string, AllocationGroup[]>;
    };
};
export type Performance = {
    eligible: boolean;
    reasons: string[];
    base_return: Ratio | null;
    native_return: Ratio | null;
    monetary_change: ExactMoney | null;
    native_monetary_change: ExactMoney | null;
    native_currency: string | null;
    external_movements: {
        journal_id: string;
        effective_date: string;
        kind: string;
        legs: { account_id: string; minor: string; currency: string }[];
    }[];
    cash_flow_adjusted_metrics: string;
    native_movement_at_start_FX?: ExactMoney | null;
    FX_and_settlement_effect?: ExactMoney | null;
};
export type ValuationResult = {
    policy: string;
    cache_key: string;
    base_currency: string;
    scope_account_id: string | null;
    cutoff: string;
    start: string | null;
    captured_at: string;
    ending: ValuationPoint;
    beginning: ValuationPoint | null;
    performance: Performance | null;
};
export type Price = ObservationTiming & {
    id: string;
    asset_id: string;
    version: string;
    series_id: string;
    unit_price: string;
    source: string;
    reason: string;
    split_basis: string;
};
