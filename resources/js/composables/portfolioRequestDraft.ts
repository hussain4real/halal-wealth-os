const memory = new Map<string, Record<string, string>>();
const listeners = new Set<() => void>();
export function subscribePortfolioRequests(listener: () => void): () => void {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}
function changed() {
    for (const listener of listeners) listener();
}
export function portfolioRequestDraft(
    scope: string,
    identity: string,
    fields: string[],
    fieldLimits: Record<string, number> = {},
) {
    const key = 'hwo:portfolio:attempt:' + scope;
    const valid = (value: unknown): value is Record<string, string> => {
        if (typeof value !== 'object' || value === null || Array.isArray(value))
            return false;
        const entries = Object.entries(value);
        if (
            entries.length !== fields.length + 1 ||
            !entries.every(
                ([k, v]) =>
                    [...fields, 'idempotency_key'].includes(k) &&
                    typeof v === 'string' &&
                    (k === 'rows'
                        ? v.length <= 400000
                        : Array.from(v).length <= (fieldLimits[k] ?? 180)),
            )
        )
            return false;
        if (!('idempotency_key' in value) || value.idempotency_key !== identity)
            return false;
        if ('rows' in value) {
            try {
                const rows: unknown = JSON.parse(String(value.rows));
                const keys = [
                    'asset_id',
                    'quantity',
                    'unit_price',
                    'manual_value',
                    'basis_known',
                    'cost_basis',
                ];
                if (
                    !Array.isArray(rows) ||
                    rows.length > 1000 ||
                    !rows.every(
                        (r: unknown) =>
                            typeof r === 'object' &&
                            r !== null &&
                            !Array.isArray(r) &&
                            Object.keys(r).length >= keys.length &&
                            Object.keys(r).length <= keys.length + 1 &&
                            keys.every((k) => k in r) &&
                            Object.entries(r).every(
                                ([k, v]) =>
                                    [...keys, 'acquired_on'].includes(k) &&
                                    typeof v === 'string' &&
                                    v.length <= 32,
                            ),
                    )
                )
                    return false;
            } catch {
                return false;
            }
        }
        return true;
    };
    return {
        load: () => {
            let value: unknown = memory.get(key);
            try {
                if (!value && typeof window !== 'undefined')
                    value = JSON.parse(
                        window.sessionStorage.getItem(key) ?? 'null',
                    );
            } catch {
                /* App-session fallback. */
            }
            return valid(value) ? { ...value } : null;
        },
        save: (value: Record<string, string>) => {
            if (!valid(value)) throw new Error('Invalid portfolio attempt');
            memory.set(key, { ...value });
            try {
                window.sessionStorage.setItem(key, JSON.stringify(value));
            } catch {
                /* App-session fallback. */
            }
            changed();
        },
        clear: () => {
            memory.delete(key);
            try {
                window.sessionStorage.removeItem(key);
            } catch {
                /* App-session fallback. */
            }
            changed();
        },
    };
}

// Inventory only: each form still validates its saved payload and request identity.
export function portfolioRequestScopes(prefix: string): string[] {
    const storagePrefix = 'hwo:portfolio:attempt:';
    const keys = new Set(memory.keys());
    try {
        if (typeof window !== 'undefined') {
            for (let i = 0; i < window.sessionStorage.length; i++) {
                const key = window.sessionStorage.key(i);
                if (key !== null) keys.add(key);
            }
        }
    } catch {
        // The app-session inventory remains available when storage is blocked.
    }
    return [...keys]
        .filter((key) => key.startsWith(storagePrefix + prefix))
        .map((key) => key.slice(storagePrefix.length))
        .sort();
}
