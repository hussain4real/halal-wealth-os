import { computed, ref } from 'vue';
import type { BudgetActual, BudgetReceiptPage } from '@/types/budget';

export function useReceiptPicker(schedule: string, selected: () => string) {
    const rows = ref<BudgetActual[]>([]);
    const pinned = ref<BudgetActual | null>(null);
    const search = ref('');
    const loading = ref(false);
    const error = ref('');
    const next = ref<string | null>(null);
    const cursors = ref<string[]>(['']);
    let committedQuery = '';
    let attempt = { pages: [''], query: '' };
    let controller: AbortController | null = null;
    const choices = computed(() => {
        const chosen = pinned.value;
        return chosen &&
            chosen.id === selected() &&
            !rows.value.some((r) => r.id === chosen.id)
            ? [chosen, ...rows.value]
            : rows.value;
    });
    const load = async (pages: string[], queryText: string) => {
        attempt = { pages, query: queryText };
        const cursor = pages[pages.length - 1];
        controller?.abort();
        const request = new AbortController();
        controller = request;
        loading.value = true;
        error.value = '';
        const query = new URLSearchParams({ q: queryText });
        if (cursor) query.set('before', cursor);
        if (selected()) query.set('selected', selected());
        try {
            const response = await fetch(
                `/budgets/schedules/${schedule}/receipts?${query}`,
                {
                    headers: { Accept: 'application/json' },
                    signal: request.signal,
                },
            );
            if (!response.ok) throw new Error('Receipt history unavailable');
            const data: BudgetReceiptPage = await response.json();
            if (!Array.isArray(data.receipts))
                throw new Error('Receipt history unavailable');
            if (request.signal.aborted || controller !== request) return;
            rows.value = data.receipts;
            pinned.value = data.selected;
            next.value = data.next_cursor;
            cursors.value = pages;
            committedQuery = queryText;
        } catch {
            if (!request.signal.aborted)
                error.value =
                    'Could not load posted receipts. Your match details are unchanged. Try again.';
        } finally {
            if (controller === request) loading.value = false;
        }
    };
    const start = () => load([''], search.value);
    const older = () => {
        if (!next.value) return;
        return load([...cursors.value, next.value], committedQuery);
    };
    const newer = () => {
        if (cursors.value.length < 2) return;
        return load(cursors.value.slice(0, -1), committedQuery);
    };
    const cancel = () => controller?.abort();
    const retry = () => load(attempt.pages, attempt.query);
    return {
        choices,
        search,
        loading,
        error,
        next,
        cursors,
        retry,
        start,
        older,
        newer,
        cancel,
    };
}
