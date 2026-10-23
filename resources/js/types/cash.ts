import type { ObservationTiming } from './observation-time';
export type CashMoney = { currency: string; minor: string; amount: string };
export type CashActual = {
    state: 'complete' | 'incomplete';
    currency: string;
    missing: string[];
    raw: CashMoney | null;
    deployable: CashMoney | null;
    shortfall: CashMoney | null;
    buffer: CashMoney | null;
    breakdown: Partial<Record<'cash' | 'protected' | 'unfunded', CashMoney>>;
    forecast_inputs: Record<string, string | null>;
    components: {
        currency: string;
        native: Record<string, string>;
        reason: string | null;
        rate:
            | (ObservationTiming & {
                  id: string;
                  source: string;
                  value: string;
              })
            | null;
        conversion: string;
    }[];
};
export type CashForecast = {
    state: 'complete' | 'incomplete';
    missing: string[];
    cash: CashMoney | null;
    free: CashMoney | null;
    inputs: Record<string, string | null>;
    details: Partial<
        Record<
            | 'remaining_protection'
            | 'additional_investment'
            | 'bill_overlap'
            | 'rounding_overlap_not_applied',
            CashMoney
        >
    >;
    scenario: boolean;
};
export type CashOverlap = {
    reservation_id: string;
    schedule_id: string;
    due_date: string;
    amount: string;
};
export type CashSettings = {
    id: string;
    version: string;
    prior_id: string;
    currency: string;
    buffer: string;
    next_income_date: string;
    investment_target: string;
    reason: string;
    requirements: { schedule_id: string; family: string; required: boolean }[];
    overlaps: CashOverlap[];
    investment_goals: string[];
};
export type CashSnapshot = {
    policy: string;
    cutoff: string;
    today: string;
    fingerprint: string;
    actual: CashActual;
    forecast: CashForecast;
    forecast_period: { start: string; end: string };
    settings: CashSettings | null;
    account_count: number;
    accounts: {
        id: string;
        name: string;
        currency: string;
        minor: string;
        protected: string;
        nominal_reserved: string;
        reservation_shortfall: string;
        reason: string | null;
        archived: boolean;
    }[];
    occurrence_count: number | null;
    occurrences: {
        schedule_id: string;
        name: string;
        currency: string;
        due: string;
        expected: string;
        paid: string;
        remaining: string;
        overlap: string;
        unfunded: string;
    }[];
    required_next_cursor: string | null;
    unclassified_count: number;
    plan_after: string | null;
    required_choices: {
        schedule_id: string;
        family: string;
        name: string;
        currency: string;
    }[];
};
