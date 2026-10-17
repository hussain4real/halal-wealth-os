import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { validatePlanningState } from './planning-state.mjs';
import { root } from './verification.mjs';

const directory = path.join(root, 'docs/halal-wealth-os');
const read = (name) =>
    JSON.parse(readFileSync(path.join(directory, name), 'utf8'));
const roadmap = read('ROADMAP.json');
const state = read('STATE.json');
const bootstrap = read('bootstrap-manifest.json');
const phases = new Map(roadmap.phases.map((phase) => [phase.id, phase]));
assert.equal(roadmap.milestones.length, 8);
assert.equal(phases.size, 25);
assert.equal(roadmap.phases.flatMap((phase) => phase.tasks).length, 150);
assert.equal(
    roadmap.phases.flatMap((phase) => phase.acceptanceIds).length,
    125,
);
assert.equal(roadmap.invariantIds.length, 12);
const visiting = new Set();
const visited = new Set();
function visit(id) {
    assert(phases.has(id), `Unknown dependency ${id}`);
    assert(!visiting.has(id), `Cyclic dependency ${id}`);
    if (visited.has(id)) return;
    visiting.add(id);
    for (const dependency of phases.get(id).dependencies) visit(dependency);
    visiting.delete(id);
    visited.add(id);
}
for (const id of phases.keys()) visit(id);
validatePlanningState(roadmap, state);
assert.equal(state.policy.default_remote_writes, false);
assert.equal(typeof bootstrap.compatibilityVerified, 'boolean');
assert(existsSync(path.join(directory, 'phases/M0-P2.md')));
assert.equal(
    read('starter-source.json').commit,
    'd282e817c6c2fa1bd475f7c42ea785ccfc67d0ab',
);
console.log(
    'PASS planning JSON, IDs, dependencies, acceptance boundaries and pinned starter identity',
);

function checkMarkdown(directory) {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
        if (
            ['.git', 'node_modules', 'vendor', '.runtime', 'build'].includes(
                entry.name,
            )
        )
            continue;
        const file = path.join(directory, entry.name);
        if (entry.isDirectory()) checkMarkdown(file);
        else if (entry.name.endsWith('.md')) {
            const text = readFileSync(file, 'utf8');
            for (const match of text.matchAll(/\[[^\]]*\]\(([^)]+)\)/g)) {
                const target = match[1].replace(/^<|>$/g, '').split('#')[0];
                if (!target || /^[a-z][a-z0-9+.-]*:/i.test(target)) continue;
                const resolved = path.resolve(
                    path.dirname(file),
                    decodeURIComponent(target),
                );
                assert(
                    resolved === root ||
                        resolved.startsWith(`${root}${path.sep}`),
                    `Link escapes checkout: ${target}`,
                );
                assert(
                    existsSync(resolved),
                    `Broken link in ${path.relative(root, file)}: ${target}`,
                );
            }
        }
    }
}
checkMarkdown(root);
assert.equal(new Set(roadmap.phases.flatMap((phase) => phase.tasks)).size, 150);
assert.equal(
    new Set(roadmap.phases.flatMap((phase) => phase.acceptanceIds)).size,
    125,
);
console.log('PASS relative Markdown links and unique task/acceptance IDs');
