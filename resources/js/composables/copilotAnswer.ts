export type AnswerFact = {
    key: string;
    label: string;
    kind: string;
    value: unknown;
};
export type AnswerCitation = {
    kind: string;
    record_id: string;
    label: string;
    href: string;
};
export type GroundedAnswer = {
    schema: 'grounded-answer-v1';
    claim: string;
    mode: string;
    state: string;
    text: string;
    scope: string;
    generated_at: string;
    as_of: string | null;
    captured_at: string | null;
    completeness: string | null;
    limitations: string[];
    facts: AnswerFact[];
    citations: AnswerCitation[];
};
function object(v: unknown): v is Record<string, unknown> {
    return typeof v === 'object' && v !== null && !Array.isArray(v);
}
export function parseGroundedAnswer(v: unknown): GroundedAnswer | null {
    if (
        !object(v) ||
        v.schema !== 'grounded-answer-v1' ||
        typeof v.claim !== 'string' ||
        typeof v.mode !== 'string' ||
        typeof v.state !== 'string' ||
        typeof v.text !== 'string' ||
        typeof v.scope !== 'string' ||
        typeof v.generated_at !== 'string' ||
        !Array.isArray(v.facts) ||
        !Array.isArray(v.citations)
    )
        return null;
    const facts: AnswerFact[] = [];
    for (const f of v.facts) {
        if (
            !object(f) ||
            typeof f.key !== 'string' ||
            typeof f.label !== 'string' ||
            typeof f.kind !== 'string' ||
            !('value' in f)
        )
            return null;
        facts.push({
            key: f.key,
            label: f.label,
            kind: f.kind,
            value: f.value,
        });
    }
    const citations: AnswerCitation[] = [];
    for (const c of v.citations) {
        if (
            !object(c) ||
            typeof c.kind !== 'string' ||
            typeof c.record_id !== 'string' ||
            typeof c.label !== 'string' ||
            typeof c.href !== 'string' ||
            !/^(cash_settings|account|goal|valuation|asset|assessment)$/.test(
                c.kind,
            ) ||
            !/^[1-9][0-9]{0,18}$/.test(c.record_id) ||
            c.href !== '/copilot/source/' + c.kind + '/' + c.record_id
        )
            return null;
        citations.push({
            kind: c.kind,
            record_id: c.record_id,
            label: c.label,
            href: c.href,
        });
    }
    if (
        v.limitations !== undefined &&
        (!Array.isArray(v.limitations) ||
            !v.limitations.every((s: unknown) => typeof s === 'string'))
    )
        return null;
    return {
        schema: 'grounded-answer-v1',
        claim: v.claim,
        mode: v.mode,
        state: v.state,
        text: v.text,
        scope: v.scope,
        generated_at: v.generated_at,
        as_of: typeof v.as_of === 'string' ? v.as_of : null,
        captured_at: typeof v.captured_at === 'string' ? v.captured_at : null,
        completeness:
            typeof v.completeness === 'string' ? v.completeness : null,
        limitations: Array.isArray(v.limitations)
            ? v.limitations.filter(
                  (s: unknown): s is string => typeof s === 'string',
              )
            : [],
        facts,
        citations,
    };
}
export function displayAnswerFact(f: AnswerFact): string {
    if (f.value === null)
        return 'Unavailable — missing or unsupported evidence';
    if (
        f.kind === 'money' &&
        object(f.value) &&
        typeof f.value.amount === 'string' &&
        typeof f.value.currency === 'string'
    )
        return f.value.amount + ' ' + f.value.currency;
    if (typeof f.value === 'string')
        return f.value + (f.kind === 'percent' ? '%' : '');
    return JSON.stringify(f.value);
}
