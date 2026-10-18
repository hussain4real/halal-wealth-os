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

export type CashRequirement = { schedule_id: string; required: string };
export function reconcileCashRequirements(
    rows: CashRequirement[],
    choices: readonly { schedule_id: string; family: string }[],
    families: Map<string, string>,
): CashRequirement[] {
    let result = rows.map((row) => ({ ...row }));
    for (const choice of choices) {
        const matches = (row: CashRequirement) =>
            row.schedule_id === choice.schedule_id ||
            families.get(row.schedule_id) === choice.family;
        const explicit = (row: CashRequirement) =>
            row.required === '0' || row.required === '1';
        const retained =
            result.find(
                (row) =>
                    row.schedule_id === choice.schedule_id && explicit(row),
            ) ??
            result.find((row) => matches(row) && explicit(row)) ??
            result.find(matches);
        let replaced = false;
        result = result.flatMap((row) => {
            if (!matches(row)) return [row];
            if (replaced) return [];
            replaced = true;
            return [
                {
                    schedule_id: choice.schedule_id,
                    required: retained?.required ?? '',
                },
            ];
        });
        if (!replaced)
            result.push({ schedule_id: choice.schedule_id, required: '' });
        families.set(choice.schedule_id, choice.family);
    }
    return result;
}
