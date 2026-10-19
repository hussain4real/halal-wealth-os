import type { AssessmentSummary } from '@/types/shariah';

export type OpeningGuidanceState = {
    status: 'loading' | 'ready' | 'unavailable';
    cutoff: string | null;
    items: ReadonlyMap<string, AssessmentSummary>;
};

const summaryValid = (value: unknown): value is AssessmentSummary => {
    if (!value || typeof value !== 'object') return false;
    const item = value as Record<string, unknown>;
    return (
        [
            'pending_review',
            'compliant',
            'non_compliant',
            'questionable',
        ].includes(String(item.classification)) &&
        [
            'conflict',
            'overdue',
            'review_date_missing',
            'other_reference_evidence',
        ].every((field) => typeof item[field] === 'boolean') &&
        typeof item.source_count === 'number' &&
        Number.isSafeInteger(item.source_count) &&
        item.source_count >= 0 &&
        ['recorded_compliant', 'unresolved'].includes(
            String(item.guidance_state),
        ) &&
        typeof item.guidance === 'string' &&
        typeof item.factual_recording === 'string'
    );
};

// View state only: never persist classifications or touch a financial attempt.
export function openingAssessmentGuidance(
    endpoint: string,
    apply: (state: OpeningGuidanceState) => void,
    request: typeof fetch = fetch,
) {
    let generation = 0;
    let active: AbortController | undefined;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let disposed = false;
    const cancel = () => {
        active?.abort();
        if (timer !== undefined) clearTimeout(timer);
    };
    return {
        async refresh(references: string[]): Promise<void> {
            if (disposed) return;
            const current = ++generation;
            cancel();
            const ids = [...new Set(references)];
            apply({ status: 'loading', cutoff: null, items: new Map() });
            if (ids.length === 0) {
                apply({ status: 'ready', cutoff: null, items: new Map() });
                return;
            }
            if (
                ids.length > 21 ||
                ids.some((id) => !/^[1-9][0-9]{0,18}$/.test(id))
            ) {
                apply({
                    status: 'unavailable',
                    cutoff: null,
                    items: new Map(),
                });
                return;
            }
            const controller = new AbortController();
            active = controller;
            const timeout = setTimeout(() => controller.abort(), 15000);
            timer = timeout;
            const query = new URLSearchParams();
            for (const id of ids) query.append('assets[]', id);
            try {
                const response = await request(
                    endpoint + '?' + query.toString(),
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                        signal: controller.signal,
                    },
                );
                if (!response.ok)
                    throw new Error('Assessment evidence unavailable');
                const payload: unknown = await response.json();
                if (!payload || typeof payload !== 'object')
                    throw new Error('Invalid assessment response');
                const data = payload as { cutoff?: unknown; items?: unknown };
                if (
                    typeof data.cutoff !== 'string' ||
                    !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(
                        data.cutoff,
                    ) ||
                    !Array.isArray(data.items) ||
                    data.items.length !== ids.length
                )
                    throw new Error('Invalid assessment response');
                const items = new Map<string, AssessmentSummary>();
                for (const value of data.items) {
                    if (!value || typeof value !== 'object')
                        throw new Error('Invalid assessment response');
                    const item = value as {
                        asset_id?: unknown;
                        summary?: unknown;
                    };
                    if (
                        typeof item.asset_id !== 'string' ||
                        !ids.includes(item.asset_id) ||
                        items.has(item.asset_id) ||
                        !summaryValid(item.summary)
                    )
                        throw new Error('Invalid assessment response');
                    items.set(item.asset_id, item.summary);
                }
                if (!disposed && current === generation) {
                    apply({ status: 'ready', cutoff: data.cutoff, items });
                }
            } catch {
                if (!disposed && current === generation) {
                    apply({
                        status: 'unavailable',
                        cutoff: null,
                        items: new Map(),
                    });
                }
            } finally {
                clearTimeout(timeout);
            }
        },
        dispose() {
            disposed = true;
            generation++;
            cancel();
        },
    };
}
