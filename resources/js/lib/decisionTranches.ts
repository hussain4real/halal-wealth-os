// Decode saved request JSON at the storage boundary, without converting money to floats.
export function decisionTranches(
    json: string,
): { key: string; label: string; amount: string; condition: string }[] | null {
    try {
        const value: unknown = JSON.parse(json);
        if (!Array.isArray(value) || value.length > 24) return null;
        const rows = [];
        const keys = new Set<string>();
        const entries: unknown[] = value;
        for (const item of entries) {
            if (
                typeof item !== 'object' ||
                item === null ||
                !('key' in item) ||
                !('label' in item) ||
                !('amount' in item) ||
                !('condition' in item)
            )
                return null;
            const { key, label, amount, condition } = item;
            if (
                typeof key !== 'string' ||
                !/^[a-zA-Z0-9_-]{1,60}$/.test(key) ||
                keys.has(key) ||
                typeof label !== 'string' ||
                typeof amount !== 'string' ||
                typeof condition !== 'string'
            )
                return null;
            keys.add(key);
            rows.push({ key, label, amount, condition });
        }
        return rows;
    } catch {
        return null;
    }
}
