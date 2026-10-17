import assert from 'node:assert/strict';

const nonempty = (value) =>
    typeof value === 'string' && value.trim().length > 0;
const compareIds = (left, right) => left.localeCompare(right);
const phaseStatuses = new Set([
    'PLANNED',
    'IN_PROGRESS',
    'RUNNING',
    'BLOCKED',
    'READY_FOR_REVIEW',
    'PAUSED',
    'HANDED_OFF',
    'ACCEPTED',
]);
const milestoneStatuses = new Set([
    'PLANNED',
    'IN_PROGRESS',
    'BLOCKED',
    'READY_FOR_REVIEW',
    'READY_FOR_OWNER',
    'ACCEPTED',
]);

// Validate recorded lifecycle structure. JSON cannot authenticate a human decision;
// the manager must still verify its authority and the referenced evidence.
export function validatePlanningState(roadmap, state) {
    const indexed = (items, label) => {
        const index = new Map(items.map((item) => [item.id, item]));
        assert.equal(index.size, items.length, `Duplicate ${label} IDs`);
        return index;
    };
    const phases = indexed(state.phases, 'phase');
    const milestones = indexed(state.milestones, 'milestone');
    assert.deepEqual(
        [...phases.keys()].sort(compareIds),
        roadmap.phases.map((p) => p.id).sort(compareIds),
    );
    assert.deepEqual(
        [...milestones.keys()].sort(compareIds),
        roadmap.milestones.map((m) => m.id).sort(compareIds),
    );

    function acceptance(record, evidenceKey) {
        assert(nonempty(record.status), `${record.id}: missing status`);
        if (record.status === 'ACCEPTED') {
            assert.equal(
                record.accepted_by,
                'human-owner',
                `${record.id}: human acceptance required`,
            );
            assert(
                nonempty(record.accepted_at) &&
                    Number.isFinite(Date.parse(record.accepted_at)),
                `${record.id}: acceptance timestamp required`,
            );
            assert(
                nonempty(record[evidenceKey]),
                `${record.id}: acceptance evidence required`,
            );
        } else {
            assert.equal(
                record.accepted_by,
                null,
                `${record.id}: unaccepted record has acceptor`,
            );
            assert.equal(
                record.accepted_at,
                null,
                `${record.id}: unaccepted record has acceptance time`,
            );
        }
    }

    for (const phase of roadmap.phases) {
        const record = phases.get(phase.id);
        assert(
            phaseStatuses.has(record.status),
            `${phase.id}: unsupported phase status`,
        );
        assert.deepEqual(
            record.depends_on,
            phase.dependencies,
            `${phase.id}: dependency drift`,
        );
        acceptance(record, 'evidence');
        if (['IN_PROGRESS', 'RUNNING'].includes(record.status)) {
            assert.equal(
                state.active_run?.phase,
                record.id,
                `${record.id}: executing phase requires its active claim`,
            );
        }
        if (record.status !== 'PLANNED') {
            for (const dependency of phase.dependencies) {
                assert.equal(
                    phases.get(dependency)?.status,
                    'ACCEPTED',
                    `${phase.id}: dependency ${dependency} must be accepted`,
                );
            }
            const milestoneIndex = roadmap.milestones.findIndex(
                (m) => m.id === phase.milestone,
            );
            assert(milestoneIndex >= 0, `${phase.id}: unknown milestone`);
            if (milestoneIndex > 0) {
                const preceding = roadmap.milestones[milestoneIndex - 1].id;
                assert.equal(
                    milestones.get(preceding).status,
                    'ACCEPTED',
                    `${phase.id}: preceding milestone review must be accepted`,
                );
            }
        }
    }
    for (const milestone of roadmap.milestones) {
        const record = milestones.get(milestone.id);
        assert(
            milestoneStatuses.has(record.status),
            `${milestone.id}: unsupported milestone status`,
        );
        acceptance(record, 'review_evidence');
        if (record.status === 'ACCEPTED') {
            for (const phase of roadmap.phases.filter(
                (p) => p.milestone === milestone.id,
            )) {
                assert.equal(
                    phases.get(phase.id).status,
                    'ACCEPTED',
                    `${milestone.id}: all phases must be accepted`,
                );
            }
        }
    }

    const authorization = state.execution_authorization;
    if (authorization) {
        assert(
            phases.has(authorization.phase),
            'Authorization references unknown phase',
        );
        assert.equal(typeof authorization.authorized, 'boolean');
        for (const dependency of authorization.accepted_dependencies ?? []) {
            assert.equal(
                phases.get(dependency)?.status,
                'ACCEPTED',
                `Authorization dependency ${dependency} must be accepted`,
            );
        }
    }
    if (state.active_run) {
        const claim = state.active_run;
        const phase = phases.get(claim.phase);
        assert(phase, 'Claim references unknown phase');
        assert(
            !['PLANNED', 'ACCEPTED'].includes(phase.status),
            'Claim requires an active unaccepted phase',
        );
        assert(nonempty(claim.owner), 'Claim requires an owner');
        assert(nonempty(claim.worktree), 'Claim requires a worktree');
        assert.equal(claim.owner, phase.owner, 'Claim and phase owners differ');
        assert.equal(
            authorization?.phase,
            claim.phase,
            'Claim and authorization phases differ',
        );
        assert.equal(
            authorization?.authorized,
            true,
            'Claim requires execution authorization',
        );
    }
}
