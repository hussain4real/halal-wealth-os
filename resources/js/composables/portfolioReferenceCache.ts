export type PortfolioReference = {
    name: string;
    kind: string;
    version: string;
};
const fallback = new Map<string, string>();
// Display metadata only. The six financial fields and their request identity
// remain untouched; the server always resolves authoritative owned assets.
export function portfolioReferenceCache(scope: string) {
    const key = 'hwo:portfolio:references:' + scope;
    const decode = (raw: string | null): Map<string, PortfolioReference> => {
        if (!raw || raw.length > 2000000) return new Map();
        try {
            const value: unknown = JSON.parse(raw);
            if (!Array.isArray(value) || value.length > 1000) return new Map();
            const result = new Map<string, PortfolioReference>();
            for (const entry of value) {
                if (!Array.isArray(entry) || entry.length !== 2)
                    return new Map();
                const [id, ref]: unknown[] = entry;
                if (
                    typeof id !== 'string' ||
                    !/^[1-9][0-9]{0,18}$/.test(id) ||
                    typeof ref !== 'object' ||
                    ref === null ||
                    Array.isArray(ref)
                )
                    return new Map();
                if (
                    Object.keys(ref).length !== 3 ||
                    !('name' in ref) ||
                    typeof ref.name !== 'string' ||
                    Array.from(ref.name).length > 180 ||
                    !('kind' in ref) ||
                    typeof ref.kind !== 'string' ||
                    !['equity', 'unitized_fund', 'manual_investment'].includes(
                        ref.kind,
                    ) ||
                    !('version' in ref) ||
                    typeof ref.version !== 'string' ||
                    !/^[1-9][0-9]{0,18}$/.test(ref.version) ||
                    result.has(id)
                )
                    return new Map();
                result.set(id, {
                    name: ref.name,
                    kind: ref.kind,
                    version: ref.version,
                });
            }
            return result;
        } catch {
            return new Map();
        }
    };
    return {
        load: () => {
            try {
                return decode(
                    window.sessionStorage.getItem(key) ??
                        fallback.get(key) ??
                        null,
                );
            } catch {
                return decode(fallback.get(key) ?? null);
            }
        },
        save: (references: Map<string, PortfolioReference>) => {
            const raw = JSON.stringify(
                [...references].map(([id, ref]) => [
                    id,
                    { name: ref.name, kind: ref.kind, version: ref.version },
                ]),
            );
            if (decode(raw).size !== references.size || raw.length > 2000000)
                throw new Error('Invalid portfolio reference details');
            fallback.set(key, raw);
            try {
                window.sessionStorage.setItem(key, raw);
            } catch {
                /* Current-session fallback. */
            }
        },
    };
}
