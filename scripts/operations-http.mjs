import assert from 'node:assert/strict';

export async function operationsJourney({
    owner,
    foreign,
    admin,
    page,
    transaction,
    bank,
    fixture,
}) {
    const read = async (actor) =>
        page((await actor.request('GET', '/operations')).body).props.operations;
    const original = await read(owner);
    const otherBefore = await read(foreign);
    const adminBefore = await read(admin);
    await owner.request(
        'GET',
        '/drive/callback?state=' + 'f'.repeat(64) + '&code=synthetic-code',
        undefined,
        { status: [422] },
    );
    assert.deepEqual(
        await read(owner),
        original,
        'Invalid disabled callback must not allocate an operation allowance',
    );
    assert.equal(original.channels.length, 4);
    assert.equal(original.access.market, 'disabled_live_mock_only');
    for (const path of ['/operations/pause', '/operations/email']) {
        await owner.request(
            'POST',
            path,
            {},
            {
                csrf: false,
                headers: {
                    Origin: 'https://foreign.invalid',
                    'Sec-Fetch-Site': 'cross-site',
                },
                status: [419, 403],
            },
        );
        await owner.request(
            'POST',
            path,
            { owner_id: '999' },
            { status: [422] },
        );
    }
    const email = original.channels.find((c) => c.channel === 'email').policy;
    await owner.request('POST', '/operations/email', {
        ...email,
        requests_daily: 1,
        units_daily: 1,
        retention_days: 1,
    });
    await owner.request('POST', '/operations/email', email, { status: [409] });
    const beforeStop = await read(owner);
    await owner.request('POST', '/operations/pause', {});
    const stopped = await read(owner);
    assert(
        stopped.channels.every((c) => c.policy.paused && c.state === 'paused'),
    );
    await owner.request(
        'POST',
        '/operations/email',
        beforeStop.channels.find((c) => c.channel === 'email').policy,
        { status: [409] },
    );
    assert.deepEqual(await read(foreign), otherBefore);
    assert.deepEqual(await read(admin), adminBefore);
    const before = fixture('inspect').postings;
    await transaction('income', bank, '1.01');
    await transaction('expense', bank, '1.01');
    assert.equal(fixture('inspect').postings, before + 4);
    const current = stopped.channels.find((c) => c.channel === 'email').policy;
    await owner.request('POST', '/operations/email', {
        ...current,
        paused: false,
    });
    const resumed = (await read(owner)).channels.find(
        (c) => c.channel === 'email',
    );
    assert.equal(resumed.policy.paused, false);
    assert.equal(resumed.policy.requests_daily, 1);
    assert.equal(resumed.policy.units_daily, 1);
    return {
        result: 'PASS',
        real_csrf_rejection: true,
        invalid_callback_no_allowance: true,
        owner_admin_isolation: true,
        stale_resume_denied: true,
        stop_all_four: true,
        manual_posting_while_stopped: true,
        explicit_resume_saved_limits: true,
        live_access: 'UNCHANGED_DISABLED_MOCK_ONLY',
    };
}
