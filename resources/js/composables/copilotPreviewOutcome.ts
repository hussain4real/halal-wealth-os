export type CopilotPreviewOutcome =
    | { kind: 'complete'; evidence: Record<string, unknown> }
    | {
          kind:
              | 'already_charged'
              | 'unavailable'
              | 'rejected'
              | 'limited'
              | 'uncertain';
      };

export function copilotPreviewOutcome({
    status,
    body,
    reference,
    recovery,
}: {
    status: number;
    body: unknown;
    reference: string;
    recovery: 'new' | 'unconfirmed';
}): CopilotPreviewOutcome {
    if (typeof body !== 'object' || body === null || Array.isArray(body)) {
        return { kind: 'uncertain' };
    }
    if (
        status === 200 &&
        'reference' in body &&
        body.reference === reference &&
        'status' in body
    ) {
        if (
            body.status === 'complete' &&
            'evidence' in body &&
            typeof body.evidence === 'object' &&
            body.evidence !== null &&
            !Array.isArray(body.evidence)
        ) {
            return {
                kind: 'complete',
                evidence: Object.fromEntries(Object.entries(body.evidence)),
            };
        }
        if (
            body.status === 'already_charged' ||
            body.status === 'unavailable'
        ) {
            return { kind: body.status };
        }
    }
    if (
        [400, 401, 403, 404, 409, 419, 422, 429].includes(status) &&
        'message' in body &&
        typeof body.message === 'string'
    ) {
        return {
            kind:
                recovery === 'unconfirmed'
                    ? 'uncertain'
                    : status === 429
                      ? 'limited'
                      : 'rejected',
        };
    }
    return { kind: 'uncertain' };
}
