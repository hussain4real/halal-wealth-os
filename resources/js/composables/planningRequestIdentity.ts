// Remember only the current request UUID, never financial form contents. A
// successful redirect cannot rewrite an older browser-history entry's draft.
// Keep its next identity outside that entry, while interrupted requests retain
// their identity for safe recovery. Scope includes owner, operation and version.
type RequestIdentity = ReturnType<Crypto['randomUUID']>;
const pending = new Map<string, RequestIdentity>();
const isIdentity = (value: string | null): value is RequestIdentity =>
    value !== null &&
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
        value,
    );

export function planningRequestIdentity(
    scope: string,
    restored: RequestIdentity,
) {
    const storageKey = 'hwo:planning:pending:' + scope;
    const save = (identity: RequestIdentity): RequestIdentity => {
        if (typeof window !== 'undefined') {
            pending.set(storageKey, identity);
            try {
                window.sessionStorage.setItem(storageKey, identity);
            } catch {
                // The current app session still retains safe Back navigation.
            }
        }
        return identity;
    };
    let current = pending.get(storageKey) ?? restored;
    if (typeof window !== 'undefined' && !pending.has(storageKey)) {
        try {
            const stored = window.sessionStorage.getItem(storageKey);
            if (isIdentity(stored)) current = stored;
        } catch {
            // Use the current app-session identity when storage is unavailable.
        }
    }
    return {
        current: save(current),
        complete: () => save(crypto.randomUUID()),
    };
}
