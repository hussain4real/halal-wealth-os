const memory = new Map<string, Record<string, string>>();
export function goalRequestDraft(
    scope: string,
    identity: string,
    fields: string[],
) {
    const key = 'hwo:goal:attempt:' + scope;
    const valid = (value: unknown): value is Record<string, string> =>
        typeof value === 'object' &&
        value !== null &&
        !Array.isArray(value) &&
        Object.keys(value).length === fields.length + 1 &&
        Object.entries(value).every(
            ([k, v]) =>
                [...fields, 'idempotency_key'].includes(k) &&
                typeof v === 'string' &&
                v.length <= 180,
        ) &&
        (value as Record<string, string>).idempotency_key === identity;
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
            if (!valid(value)) throw new Error('Invalid goal attempt');
            memory.set(key, { ...value });
            try {
                window.sessionStorage.setItem(key, JSON.stringify(value));
            } catch {
                /* App-session fallback. */
            }
        },
        clear: () => {
            memory.delete(key);
            try {
                window.sessionStorage.removeItem(key);
            } catch {
                /* App-session fallback. */
            }
        },
    };
}
