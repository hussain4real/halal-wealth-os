/** Keep the request UUID and its validated draft store in one replaceable state. */
export function driveAttempt(
    identity: { current: string; complete: () => string },
    store: (key: string) => {
        load: () => Record<string, string> | null;
        save: (payload: Record<string, string>) => void;
        clear: () => void;
    },
) {
    let key = identity.current;
    let draft = store(key);
    return {
        load: () => draft.load(),
        save: (fields: Record<string, string>) => {
            const payload = draft.load() ?? { ...fields, idempotency_key: key };
            draft.save(payload);
            return payload;
        },
        complete: () => {
            draft.clear();
            key = identity.complete();
            draft = store(key);
        },
    };
}

export function isDriveReceipt(
    value: unknown,
    requestKey: string,
    kind: 'prepare' | 'confirm',
): value is { id: string; request_key: string; kind: 'folder' | 'report' } {
    return (
        typeof value === 'object' &&
        value !== null &&
        'id' in value &&
        typeof value.id === 'string' &&
        /^[1-9][0-9]*$/.test(value.id) &&
        'request_key' in value &&
        value.request_key === requestKey &&
        'kind' in value &&
        value.kind === (kind === 'prepare' ? 'folder' : 'report')
    );
}
