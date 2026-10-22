import assert from 'node:assert/strict';

/** Runs against the actual cookie/CSRF/native HTTP actors owned by acceptance-http. */
export async function statementJourney({
    owner,
    foreign,
    admin,
    account,
    date,
    page,
    fixture,
    key,
}) {
    const csv =
        [
            [
                'date',
                'type',
                'amount',
                'currency',
                'description',
                'provider_id',
            ],
            [
                date,
                'income',
                '3.21',
                'QAR',
                '=SUM(1+1)',
                'SYNTHETIC-NATIVE-IMPORT',
            ],
            [
                date,
                'expense',
                '-1',
                'QAR',
                'Synthetic-import-private-marker',
                'INVALID-ROW',
            ],
        ]
            .map((row) =>
                row
                    .map((cell) => '"' + cell.replaceAll('"', '""') + '"')
                    .join(','),
            )
            .join('\r\n') + '\r\n';
    const mapping = {
        version: 1,
        delimiter: ',',
        date_format: 'Y-m-d',
        decimal_separator: '.',
        columns: {
            date: 'date',
            type: 'type',
            amount: 'amount',
            currency: 'currency',
            description: 'description',
            provider_id: 'provider_id',
        },
        type_map: [],
        currency_map: [],
        default_asset_id: '',
    };
    const upload = () => {
        const data = new FormData();
        data.append(
            'file',
            new Blob([csv], { type: 'text/csv' }),
            'synthetic-statement.csv',
        );
        data.append('namespace', 'Synthetic native source');
        data.append('account_id', account);
        data.append('adapter_name', 'Synthetic native mapping');
        data.append('mapping', JSON.stringify(mapping));
        return data;
    };
    const before = fixture('inspect');
    const uploaded = JSON.parse(
        (await owner.request('POST', '/imports', upload())).body,
    );
    const preview = page((await owner.request('GET', uploaded.url)).body).props
        .preview;
    assert.equal(preview.rows[0].ready, true);
    assert.equal(preview.rows[1].ready, false);
    assert.equal(fixture('inspect').postings, before.postings);
    const noCsrf = await owner.request(
        'POST',
        `${uploaded.url}/approve`,
        { idempotency_key: key(), selection: ['1'] },
        { csrf: false, status: [419] },
    );
    assert.equal(noCsrf.status, 419);
    const invalid = JSON.parse(
        (
            await owner.request(
                'POST',
                `${uploaded.url}/approve`,
                { idempotency_key: key(), selection: ['1', '2'] },
                { status: [422] },
            )
        ).body,
    );
    assert.equal(invalid.definitive_no_write, true);
    assert.equal(fixture('inspect').postings, before.postings);
    const requestKey = key();
    const payload = { idempotency_key: requestKey, selection: ['1'] };
    const receipt = JSON.parse(
        (await owner.request('POST', `${uploaded.url}/approve`, payload)).body,
    );
    const repeated = JSON.parse(
        (await owner.request('POST', `${uploaded.url}/approve`, payload)).body,
    );
    assert.equal(receipt.id, repeated.id);
    assert.equal(
        JSON.parse(
            (await owner.request('GET', '/imports/requests/' + requestKey))
                .body,
        ).id,
        receipt.id,
    );
    const posted = fixture('inspect').postings;
    assert.equal(posted, before.postings + 2);
    const reuploaded = JSON.parse(
        (await owner.request('POST', '/imports', upload())).body,
    );
    assert.equal(reuploaded.id, uploaded.id);
    const duplicate = page((await owner.request('GET', reuploaded.url)).body)
        .props.preview.rows[0];
    assert.equal(duplicate.existing.receipt_id, receipt.id);
    const exported = await owner.request('GET', `${uploaded.url}/export`);
    assert(exported.body.includes("'=SUM(1+1)"));
    const paths = [
        uploaded.url,
        `${uploaded.url}/export`,
        receipt.url,
        '/imports/requests/' + requestKey,
    ];
    for (const actor of [foreign, admin]) {
        for (const path of paths)
            await actor.request('GET', path, undefined, { status: [404] });
        await actor.request(
            'POST',
            `${uploaded.url}/approve`,
            { idempotency_key: key(), selection: ['1'] },
            { status: [404] },
        );
        await actor.request(
            'POST',
            `${uploaded.url}/revise`,
            { resolutions: {} },
            { status: [404] },
        );
        await actor.request(
            'POST',
            `${receipt.url}/1/correct`,
            { idempotency_key: key(), reason: 'Synthetic foreign rejection' },
            { status: [404] },
        );
    }
    assert.equal(fixture('inspect').postings, posted);
    const correction = JSON.parse(
        (
            await owner.request('POST', `${receipt.url}/1/correct`, {
                idempotency_key: key(),
                reason: 'Synthetic native linked reversal',
            })
        ).body,
    );
    const corrected = page((await owner.request('GET', correction.url)).body)
        .props.receipt;
    assert.equal(corrected.outcomes[0].type, 'cancelled');
    const final = fixture('inspect');
    assert.equal(final.unbalanced, 0);
    assert.equal(
        final.dashboard.facts.widgets.net_worth.total.minor,
        before.dashboard.facts.widgets.net_worth.total.minor,
    );
    return {
        result: 'PASS',
        supported_csv_upload: 'PRIVATE_DATABASE_SOURCE_NO_SERVED_FILE',
        preview_no_financial_writes: true,
        malformed_selection_atomic: true,
        exact_request_and_reupload_once: true,
        safe_formula_download: true,
        foreign_and_admin_paths: paths.length * 2,
        foreign_write_paths: 6,
        real_csrf_rejection: true,
        linked_root_reversal: true,
        private_markers: [
            'Synthetic-import-private-marker',
            'SYNTHETIC-NATIVE-IMPORT',
            '=SUM(1+1)',
        ],
        preview_id: uploaded.id,
        receipt_id: receipt.id,
    };
}
