import type { ObservationTiming, OpeningTiming } from './observation-time';
import type { LedgerMoney } from './ledger';
export type MetricKey =
    | 'net_worth'
    | 'liabilities'
    | 'liquid_cash'
    | 'investments'
    | 'funded_goals';
type MetricBasis = {
    covered_subtotal: LedgerMoney | null;
    covered: number;
    count: number;
    missing: number;
    cutoff: string;
    native: { currency: string; value: LedgerMoney | null; missing: number }[];
};
export type DashboardMetric = MetricBasis &
    (
        | { state: 'complete'; total: LedgerMoney }
        | { state: 'partial' | 'unavailable'; total: null }
    );
export type DashboardSource = {
    account_id: string;
    account: string;
    asset: string;
    kind: string;
    currency: string;
    native: LedgerMoney | null;
    converted: LedgerMoney | null;
    reason: string | null;
    asset_id?: string;
    goal_id?: string;
    portfolio_account_id?: string;
    price?:
        | ((ObservationTiming | OpeningTiming) & {
              source: string;
              id: string;
          })
        | null;
    fx:
        | (ObservationTiming & {
              source: string;
              id: string;
              value: string;
          })
        | null;
};
export type DashboardAction = {
    key: string;
    rule: string;
    subject: string;
    title: string;
    explanation: string;
    url: string;
    dismissed: boolean;
    dismissal: { id: string; reason: string; recorded_at: string } | null;
};
export type CurrencyTarget = {
    currency: string;
    target: string;
    tolerance: string;
};
export type DashboardSettings = {
    id: string | null;
    version: string;
    data: { enabled: string[]; grace_days: string; targets: CurrencyTarget[] };
};
export type DashboardView = {
    cutoff: string;
    planning_date: string;
    base_currency: string;
    time_policy: string;
    policy: string;
    widgets: Record<MetricKey, DashboardMetric>;
    sources: Record<MetricKey, DashboardSource[]>;
    settings: DashboardSettings;
    actions: DashboardAction[];
    reconciliations: {
        account_id: string;
        account: string;
        status: string;
        cutoff: string | null;
        explanation: string;
    }[];
    allocation: {
        coverage: string;
        denominator: string;
        dimensions: {
            currency: {
                label: string;
                weight: { percent: string } | null;
                missing_components: number;
            }[];
        };
    };
};
