import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { stripTypeScriptTypes } from 'node:module';
const source = readFileSync(
    new URL(
        '../resources/js/composables/portfolioReferenceCache.ts',
        import.meta.url,
    ),
    'utf8',
);
const module = await import(
    'data:text/javascript;base64,' +
        Buffer.from(stripTypeScriptTypes(source)).toString('base64')
);
const store = new Map();
globalThis.window = {
    sessionStorage: {
        getItem: (key) => store.get(key) ?? null,
        setItem: (key, value) => store.set(key, value),
    },
};
await test('off-page manual reference type survives a canonical reload without touching financial attempt fields', () => {
    const financial = Object.freeze({
        asset_id: '34',
        quantity: '',
        unit_price: '',
        manual_value: '-1',
        basis_known: '0',
        cost_basis: '',
    });
    const cache = module.portfolioReferenceCache('owner1:portfolio1');
    cache.save(
        new Map([
            [
                '34',
                {
                    name: 'Synthetic off-page manual investment',
                    kind: 'manual_investment',
                    version: '1',
                },
            ],
        ]),
    );
    const reloaded = module.portfolioReferenceCache('owner1:portfolio1').load();
    for (let id = 35; id < 55; id++)
        reloaded.set(String(id), {
            name: 'Synthetic choice',
            kind: 'equity',
            version: '1',
        });
    assert.equal(reloaded.get(financial.asset_id).kind, 'manual_investment');
    assert.equal(financial.manual_value, '-1');
    assert.equal(financial.quantity, '');
    assert.equal(
        module.portfolioReferenceCache('owner2:portfolio1').load().size,
        0,
    );
    assert.equal(
        module.portfolioReferenceCache('owner1:portfolio2').load().size,
        0,
    );
});
await test('corrupt reference cache cannot introduce unvalidated kinds identities or extra fields', () => {
    for (const value of [
        'not-json',
        '{}',
        JSON.stringify([
            ['34', { name: 'Synthetic', kind: 'derivative', version: '1' }],
        ]),
        JSON.stringify([
            [
                '34',
                {
                    name: 'Synthetic',
                    kind: 'equity',
                    version: '1',
                    quantity: '7',
                },
            ],
        ]),
        JSON.stringify([
            [34, { name: 'Synthetic', kind: 'equity', version: '1' }],
        ]),
    ]) {
        store.set('hwo:portfolio:references:corrupt', value);
        assert.equal(module.portfolioReferenceCache('corrupt').load().size, 0);
    }
});
await test('full bounded reference books retain maximal unicode labels and remain financial-field-free', () => {
    const refs = new Map(
        Array.from({ length: 1000 }, (_, index) => [
            String(index + 1),
            { name: '🟢'.repeat(180), kind: 'unitized_fund', version: '1' },
        ]),
    );
    const cache = module.portfolioReferenceCache('large');
    cache.save(refs);
    assert.equal(cache.load().size, 1000);
    assert.deepEqual(Object.keys(cache.load().get('1')), [
        'name',
        'kind',
        'version',
    ]);
    assert.throws(() =>
        cache.save(
            new Map([
                ...refs,
                [
                    '1001',
                    { name: 'Synthetic extra', kind: 'equity', version: '1' },
                ],
            ]),
        ),
    );
});
