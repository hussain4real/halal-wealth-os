const memory = new Map<string, Record<string, string>>();
export function cashSettingsDraft(
    scope: string,
    identity: string,
    fields: string[],
) {
    const key = 'hwo:cash-settings:attempt:' + scope;
    const listsValid = (value: Record<string, string>) => {
        try {
            for (const field of [
                'requirements',
                'overlaps',
                'investment_goals',
            ]) {
                if (!fields.includes(field)) continue;
                const list: unknown = JSON.parse(value[field]);
                if (!Array.isArray(list) || list.length > 1000) return false;
                if (field === 'investment_goals') {
                    if (
                        !list.every(
                            (item) =>
                                typeof item === 'string' &&
                                /^[1-9]\d{0,18}$/.test(item),
                        )
                    )
                        return false;
                } else {
                    const allowed =
                        field === 'requirements'
                            ? ['schedule_id', 'required']
                            : [
                                  'reservation_id',
                                  'schedule_id',
                                  'due_date',
                                  'amount',
                              ];
                    if (
                        !list.every(
                            (item) =>
                                typeof item === 'object' &&
                                item !== null &&
                                !Array.isArray(item) &&
                                Object.keys(item).length === allowed.length &&
                                Object.entries(item).every(
                                    ([name, entry]) =>
                                        allowed.includes(name) &&
                                        typeof entry === 'string' &&
                                        entry.length <= 180,
                                ),
                        )
                    )
                        return false;
                }
            }
            return true;
        } catch {
            return false;
        }
    };
    const valid = (value: unknown): value is Record<string, string> =>
        typeof value === 'object' &&
        value !== null &&
        !Array.isArray(value) &&
        Object.keys(value).length === fields.length + 1 &&
        Object.entries(value).every(
            ([name, field]) =>
                [...fields, 'idempotency_key'].includes(name) &&
                typeof field === 'string' &&
                field.length <= 300000,
        ) &&
        (value as Record<string, string>).idempotency_key === identity &&
        listsValid(value as Record<string, string>);
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
            if (!valid(value)) throw new Error('Invalid cash settings attempt');
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
