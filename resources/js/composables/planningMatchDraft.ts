type MatchDraft = { journal_id: string; amount: string };
type PendingMatch = MatchDraft & { request_id: string };

const pending = new Map<string, PendingMatch>();

// Only attempted match details are retained, scoped to owner + occurrence.
// Server ownership, exact-money and idempotency checks remain authoritative.
export function planningMatchDraft(scope: string) {
    const key = 'hwo-pending-match:' + scope;
    const read = (requestId: string): MatchDraft | null => {
        try {
            const value =
                pending.get(key) ??
                JSON.parse(window.sessionStorage.getItem(key) ?? 'null');
            if (
                value?.request_id !== requestId ||
                typeof value.journal_id !== 'string' ||
                !/^[1-9][0-9]{0,18}$/.test(value.journal_id) ||
                typeof value.amount !== 'string' ||
                !/^[0-9]{1,19}(?:\.[0-9]{1,3})?$/.test(value.amount)
            )
                return null;
            return { journal_id: value.journal_id, amount: value.amount };
        } catch {
            return null;
        }
    };
    const save = (requestId: string, draft: MatchDraft) => {
        const value = { request_id: requestId, ...draft };
        pending.set(key, value);
        try {
            window.sessionStorage.setItem(key, JSON.stringify(value));
        } catch {
            /* History remains available when tab storage is denied. */
        }
    };
    const clear = (requestId: string) => {
        if (!read(requestId)) return;
        pending.delete(key);
        try {
            window.sessionStorage.removeItem(key);
        } catch {
            /* A stale draft cannot match the next confirmed request identity. */
        }
    };
    return { read, save, clear };
}
