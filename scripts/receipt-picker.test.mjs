import assert from 'node:assert/strict';
import test from 'node:test';
import { useReceiptPicker } from '../resources/js/composables/useReceiptPicker.ts';

await test('Older and Retry retain their committed query when the search draft changes', async () => {
    const original = globalThis.fetch;
    const queries = [];
    let fail = false;
    globalThis.fetch = async (url) => {
        const q = new URL(url, 'http://localhost').searchParams;
        queries.push([q.get('q'), q.get('before')]);
        if (fail) throw new Error('Synthetic offline');
        return {
            ok: true,
            json: async () => ({
                receipts: [],
                selected: null,
                next_cursor: '40',
            }),
        };
    };
    try {
        const picker = useReceiptPicker('7', () => '');
        picker.search.value = 'original query';
        await picker.start();
        picker.search.value = 'unsent draft';
        fail = true;
        await picker.older();
        picker.search.value = 'another unsent draft';
        fail = false;
        await picker.retry();
        assert.deepEqual(queries, [
            ['original query', null],
            ['original query', '40'],
            ['original query', '40'],
        ]);
        await picker.start();
        assert.deepEqual(queries.at(-1), ['another unsent draft', null]);
    } finally {
        globalThis.fetch = original;
    }
});

await test('failed older navigation retries the requested page without advancing successful history', async () => {
    const original = globalThis.fetch;
    const requests = [];
    let fail = false;
    globalThis.fetch = async (url) => {
        requests.push(
            new URL(url, 'http://localhost').searchParams.get('before'),
        );
        if (fail) throw new Error('Synthetic offline');
        return {
            ok: true,
            json: async () => ({
                receipts: [],
                selected: null,
                next_cursor: requests.length === 1 ? '40' : '20',
            }),
        };
    };
    try {
        const picker = useReceiptPicker('7', () => '');
        await picker.start();
        fail = true;
        await picker.older();
        assert.deepEqual(picker.cursors.value, ['']);
        assert.match(picker.error.value, /unchanged/);
        fail = false;
        await picker.retry();
        assert.deepEqual(requests, [null, '40', '40']);
        assert.deepEqual(picker.cursors.value, ['', '40']);
        await picker.newer();
        assert.deepEqual(picker.cursors.value, ['']);
    } finally {
        globalThis.fetch = original;
    }
});

await test('search and paging pin the remembered fully allocated selection for uncertain-response retry', async () => {
    const original = globalThis.fetch;
    const selected = { id: '9', remaining: { minor: '0' }, status: 'posted' };
    const urls = [];
    globalThis.fetch = async (url) => {
        urls.push(new URL(url, 'http://localhost'));
        return {
            ok: true,
            json: async () => ({
                receipts: [{ id: '8' }],
                selected,
                next_cursor: '8',
            }),
        };
    };
    try {
        const picker = useReceiptPicker('7', () => '9');
        await picker.start();
        picker.search.value = 'older %_ receipt';
        await picker.start();
        await picker.older();
        assert.deepEqual(
            picker.choices.value.map((r) => r.id),
            ['9', '8'],
        );
        assert.equal(picker.choices.value[0].remaining.minor, '0');
        assert.ok(urls.every((u) => u.searchParams.get('selected') === '9'));
        assert.equal(urls.at(-1).searchParams.get('q'), 'older %_ receipt');
    } finally {
        globalThis.fetch = original;
    }
});

await test('a superseded or cancelled request cannot replace a newer search result', async () => {
    const original = globalThis.fetch;
    const pending = [];
    globalThis.fetch = () => new Promise((resolve) => pending.push(resolve));
    try {
        const picker = useReceiptPicker('7', () => '');
        const old = picker.start();
        const fresh = picker.start();
        pending[1]({
            ok: true,
            json: async () => ({
                receipts: [{ id: 'new' }],
                selected: null,
                next_cursor: null,
            }),
        });
        await fresh;
        pending[0]({
            ok: true,
            json: async () => ({
                receipts: [{ id: 'old' }],
                selected: null,
                next_cursor: null,
            }),
        });
        await old;
        assert.equal(picker.choices.value[0].id, 'new');
        const cancelled = picker.start();
        picker.cancel();
        pending[2]({
            ok: true,
            json: async () => ({
                receipts: [{ id: 'cancelled' }],
                selected: null,
                next_cursor: null,
            }),
        });
        await cancelled;
        assert.equal(picker.choices.value[0].id, 'new');
    } finally {
        globalThis.fetch = original;
    }
});
