export type OperationPolicy = {
    generation: string;
    paused: boolean;
    requests_daily: number;
    units_daily: number;
    retention_days: number;
};

export function synchronizeOperationPolicy(
    draft: OperationPolicy,
    previous: OperationPolicy,
    next: OperationPolicy,
): OperationPolicy {
    return {
        generation: next.generation,
        paused: next.paused,
        requests_daily:
            draft.requests_daily === previous.requests_daily
                ? next.requests_daily
                : draft.requests_daily,
        units_daily:
            draft.units_daily === previous.units_daily
                ? next.units_daily
                : draft.units_daily,
        retention_days:
            draft.retention_days === previous.retention_days
                ? next.retention_days
                : draft.retention_days,
    };
}
