import { createHash } from 'node:crypto';
import { readFileSync, statSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
export const root = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '..',
);
export const liveReport =
    'docs/halal-wealth-os/runs/M7-P3-live-evaluation.json';
export function evaluationBasis(directory = root) {
    const inventory = spawnSync(
        'git',
        ['ls-files', '-z', '--cached', '--others', '--exclude-standard'],
        { cwd: directory, encoding: 'utf8' },
    );
    if (inventory.status !== 0)
        throw Error('Evaluation source inventory failed');
    const files = [
        ...new Set(
            inventory.stdout
                .split('\0')
                .filter(
                    (f) =>
                        f !== liveReport &&
                        (/^(app|bootstrap|config|routes|resources|scripts|tests|bin|database|ops|\.github)\//.test(
                            f,
                        ) ||
                            /^(composer\.(json|lock)|package(-lock)?\.json|phpunit\.xml|phpstan\.neon|vite\.config\.ts|tsconfig\.json)$/.test(
                                f,
                            )),
                ),
        ),
    ].sort();
    if (files.length < 1) throw Error('Evaluation basis is empty');
    const hash = createHash('sha256');
    for (const file of files) {
        let bytes = readFileSync(path.join(directory, file));
        // Only this generated receipt's own reference is normalized. Every other
        // selector, evidence reference, runtime/prompt/eval/test byte remains bound.
        if (file === 'scripts/acceptance-map.json') {
            const map = JSON.parse(bytes);
            const ref = map.manual_artifacts?.['m7p3-live-evaluation'];
            if (ref) ref.sha256 = '0'.repeat(64);
            bytes = Buffer.from(JSON.stringify(map));
        }
        hash.update(file)
            .update('\0')
            .update(
                (statSync(path.join(directory, file)).mode & 0o777).toString(8),
            )
            .update('\0')
            .update(bytes)
            .update('\0');
    }
    return hash.digest('hex');
}
if (process.argv[1] === fileURLToPath(import.meta.url))
    console.log(evaluationBasis());
