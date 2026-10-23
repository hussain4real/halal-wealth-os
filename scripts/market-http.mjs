import assert from 'node:assert/strict';

export async function marketJourney({
    owner,
    foreign,
    admin,
    fixture,
    page,
    key,
}) {
    const target = fixture('market-prepare');
    const input = {
        kind: 'price',
        base: '',
        quote: '',
        symbol: 'SYNTH',
        currency: 'USD',
        mic_code: 'XNAS',
        exchange_timezone: 'America/New_York',
        ...target,
        raw_confirmed: '1',
        enabled: '1',
        supersedes_id: '',
        idempotency_key: key(),
    };
    const first = JSON.parse(
        (await owner.request('POST', '/market-data', input)).body,
    );
    const refreshed = fixture('market-refresh', first.id);
    assert.equal(refreshed.result.status, 'accepted');
    let current = page((await owner.request('GET', '/market-data')).body).props;
    assert.equal(current.mode, 'disabled');
    assert.equal(current.mappings[0].observation.stale, true);
    assert.equal(current.mappings[0].observation.observed_at, null);
    assert.equal(current.mappings[0].observation.effective_at, null);
    assert.equal(
        current.mappings[0].observation.provider_time.precision,
        'date',
    );
    const original = current.mappings[0].observation;
    const replacement = JSON.parse(
        (
            await owner.request('POST', '/market-data', {
                ...input,
                symbol: 'SYNTHNEXT',
                supersedes_id: first.id,
                idempotency_key: key(),
            })
        ).body,
    );
    const outage = fixture('market-refresh', replacement.id, 'timeout');
    assert.equal(outage.result.status, 'retryable');
    assert.equal(outage.result.code, 'timeout');
    assert.equal(outage.prior_unchanged, true);
    current = page((await owner.request('GET', '/market-data')).body).props;
    assert.equal(current.mappings[0].run.status, 'retryable');
    assert.equal(current.mappings[0].admission, null);
    assert.deepEqual(current.mappings[0].observation, original);
    const disabledInput = {
        ...input,
        kind: 'disable',
        enabled: '0',
        supersedes_id: replacement.id,
        idempotency_key: key(),
    };
    await owner.request('POST', '/market-data', disabledInput);
    const recovered = JSON.parse(
        (await owner.request('POST', '/market-data', input)).body,
    );
    assert.equal(recovered.id, first.id);
    await owner.request(
        'POST',
        '/market-data',
        { ...input, symbol: 'CHANGED' },
        { status: [422] },
    );
    await owner.request(
        'POST',
        '/market-data',
        { ...input, idempotency_key: key() },
        { csrf: false, status: [419] },
    );
    for (const actor of [foreign, admin]) {
        await actor.request('GET', `/market-data?edit=${first.id}`, undefined, {
            status: [404],
        });
        await actor.request(
            'GET',
            `/market-data?portfolio=${target.portfolio_account_id}`,
            undefined,
            { status: [404] },
        );
        await actor.request(
            'POST',
            '/market-data',
            { ...disabledInput, idempotency_key: key() },
            { status: [404] },
        );
        const other = page(
            (await actor.request('GET', '/market-data')).body,
        ).props;
        assert.equal(other.mapping_count, 0);
    }
    return {
        result: 'PASS',
        outage_preserves_stale_observation: true,
        honest_daily_source: true,
        exact_retry_after_supersession: true,
        owner_admin_isolation: true,
        real_csrf_rejection: true,
        live_access: 'BLOCKED_MOCK_ONLY',
    };
}
