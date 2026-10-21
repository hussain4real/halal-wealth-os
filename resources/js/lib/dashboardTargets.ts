import type { CurrencyTarget } from '@/types/dashboard';
type ParsedTargets =
    | { ok: true; rows: CurrencyTarget[] }
    | { ok: false; message: string };
export function dashboardTargets(input: string): ParsedTargets {
    let value: unknown;
    try {
        value = JSON.parse(input);
    } catch {
        return {
            ok: false,
            message:
                'Saved target data is invalid. Check the interrupted request before starting again.',
        };
    }
    if (!Array.isArray(value) || value.length > 7)
        return {
            ok: false,
            message: 'Use at most one target per supported currency.',
        };
    const rows: CurrencyTarget[] = [];
    for (const item of value) {
        if (
            typeof item !== 'object' ||
            item === null ||
            !('currency' in item) ||
            !('target' in item) ||
            !('tolerance' in item) ||
            typeof item.currency !== 'string' ||
            typeof item.target !== 'string' ||
            typeof item.tolerance !== 'string'
        )
            return {
                ok: false,
                message: 'Currency, target and tolerance must stay exact text.',
            };
        rows.push({
            currency: item.currency,
            target: item.target,
            tolerance: item.tolerance,
        });
    }
    return { ok: true, rows };
}
