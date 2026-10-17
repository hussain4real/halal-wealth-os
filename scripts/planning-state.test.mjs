import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { validatePlanningState } from './planning-state.mjs';

const directory = new URL('../docs/halal-wealth-os/', import.meta.url);
const read = (name) =>
    JSON.parse(readFileSync(new URL(name, directory), 'utf8'));
const roadmap = read('ROADMAP.json');
// Regression fixtures must not change when the live project advances.
const baseline = {
    phases: roadmap.phases.map((item) => ({
        id: item.id,
        depends_on: [...item.dependencies],
        status: 'PLANNED',
        owner: null,
        accepted_by: null,
        accepted_at: null,
        evidence: null,
    })),
    milestones: roadmap.milestones.map((item) => ({
        id: item.id,
        status: 'PLANNED',
        accepted_by: null,
        accepted_at: null,
        review_evidence: null,
    })),
    active_run: { phase: 'M0-P2', owner: 'synthetic-owner', worktree: '.' },
    execution_authorization: {
        phase: 'M0-P2',
        authorized: true,
        accepted_dependencies: ['M0-P1'],
    },
};
const phase = (state, id) => state.phases.find((p) => p.id === id);
const accept = (state, id) =>
    Object.assign(phase(state, id), {
        status: 'ACCEPTED',
        accepted_by: 'human-owner',
        accepted_at: '2026-10-17T13:00:00Z',
        evidence: 'synthetic-test-evidence.md',
    });
const acceptedP2 = () => {
    const state = structuredClone(baseline);
    accept(state, 'M0-P2');
    state.active_run = null;
    return state;
};
accept(baseline, 'M0-P1');
Object.assign(phase(baseline, 'M0-P2'), {
    status: 'BLOCKED',
    owner: 'synthetic-owner',
});

await test('the live planning state satisfies lifecycle rules', () => {
    validatePlanningState(roadmap, read('STATE.json'));
});

await test('current blocked state and later legitimate phase acceptance are valid', () => {
    validatePlanningState(roadmap, baseline);
    validatePlanningState(roadmap, acceptedP2());
});

await test('a separately authorized future phase may start after accepted dependencies', () => {
    const state = acceptedP2();
    Object.assign(phase(state, 'M0-P3'), {
        status: 'IN_PROGRESS',
        owner: 'future-owner',
    });
    state.active_run = { phase: 'M0-P3', owner: 'future-owner', worktree: '.' };
    state.execution_authorization = {
        phase: 'M0-P3',
        authorized: true,
        accepted_dependencies: ['M0-P2'],
    };
    validatePlanningState(roadmap, state);
});

await test('crossing milestones requires accepted independent milestone evidence', () => {
    const state = acceptedP2();
    accept(state, 'M0-P3');
    Object.assign(phase(state, 'M1-P1'), {
        status: 'BLOCKED',
        owner: 'future-owner',
    });
    assert.throws(
        () => validatePlanningState(roadmap, state),
        /preceding milestone review/,
    );
    Object.assign(state.milestones[0], {
        status: 'ACCEPTED',
        accepted_by: 'human-owner',
        accepted_at: '2026-10-17T13:00:00Z',
        review_evidence: 'synthetic-review.md',
    });
    validatePlanningState(roadmap, state);
});

await test('acceptance cannot omit human identity, timestamp or evidence', () => {
    for (const [key, value, message] of [
        ['accepted_by', 'worker', /human acceptance/],
        ['accepted_at', null, /timestamp/],
        ['accepted_at', 'invalid', /timestamp/],
        ['evidence', null, /evidence/],
    ]) {
        const state = acceptedP2();
        phase(state, 'M0-P2')[key] = value;
        assert.throws(() => validatePlanningState(roadmap, state), message);
    }
});

await test('unaccepted dependencies and premature milestone acceptance are rejected', () => {
    const state = structuredClone(baseline);
    accept(state, 'M0-P3');
    assert.throws(
        () => validatePlanningState(roadmap, state),
        /dependency M0-P2/,
    );
    const milestone = structuredClone(baseline);
    Object.assign(milestone.milestones[0], {
        status: 'ACCEPTED',
        accepted_by: 'human-owner',
        accepted_at: '2026-10-17T13:00:00Z',
        review_evidence: 'synthetic-review.md',
    });
    assert.throws(
        () => validatePlanningState(roadmap, milestone),
        /all phases/,
    );
});

await test('claim ownership, authorization, IDs and dependency structure remain enforced', () => {
    for (const [mutate, message] of [
        [
            (s) => {
                delete s.execution_authorization;
            },
            /authorization phases differ/,
        ],
        [
            (s) => {
                delete s.active_run.worktree;
            },
            /worktree/,
        ],
        [
            (s) => {
                s.active_run.owner = 'other-owner';
            },
            /owners differ/,
        ],
        [
            (s) => {
                s.execution_authorization.phase = 'M0-P3';
            },
            /authorization phases differ/,
        ],
        [
            (s) => {
                s.execution_authorization.authorized = false;
            },
            /execution authorization/,
        ],
        [
            (s) => {
                s.phases.push(structuredClone(s.phases[0]));
            },
            /Duplicate phase/,
        ],
        [
            (s) => {
                phase(s, 'M0-P2').depends_on = [];
            },
            /dependency drift/,
        ],
    ]) {
        const state = structuredClone(baseline);
        mutate(state);
        assert.throws(() => validatePlanningState(roadmap, state), message);
    }
});

await test('executing work cannot omit its active claim and authorization', () => {
    for (const status of ['IN_PROGRESS', 'RUNNING']) {
        const state = structuredClone(baseline);
        phase(state, 'M0-P2').status = status;
        state.active_run = null;
        delete state.execution_authorization;
        assert.throws(
            () => validatePlanningState(roadmap, state),
            /executing phase requires its active claim/,
        );
    }
});

await test('unknown phase status cannot evade the executing claim check', () => {
    for (const status of ['RUNNNING', 'ACCEPTE', 'UNKNOWN']) {
        const state = structuredClone(baseline);
        phase(state, 'M0-P2').status = status;
        state.active_run = null;
        delete state.execution_authorization;
        assert.throws(
            () => validatePlanningState(roadmap, state),
            /unsupported phase status/,
        );
    }
});

await test('unknown milestone status is rejected', () => {
    for (const status of ['ACCEPTEDD', 'UNKNOWN']) {
        const state = structuredClone(baseline);
        state.milestones[0].status = status;
        assert.throws(
            () => validatePlanningState(roadmap, state),
            /unsupported milestone status/,
        );
    }
});
