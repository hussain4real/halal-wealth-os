import { createHash } from 'node:crypto';
import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';

// Shared modules, layouts, styles, views and resolver/lock changes invalidate
// every cached test. Inertia page files use Pest's observed page/test bindings.
export function frontendSnapshot(root) {
    const digest = createHash('sha256');
    const include = (relative) => {
        digest.update(relative + '\0');
        digest.update(readFileSync(path.join(root, relative)));
        digest.update('\0');
    };
    const walk = (relative) => {
        for (const entry of readdirSync(path.join(root, relative), {
            withFileTypes: true,
        }).sort((a, b) => a.name.localeCompare(b.name))) {
            const file = `${relative}/${entry.name}`;
            if (file === 'resources/js/pages') continue;
            if (entry.isDirectory()) walk(file);
            else if (entry.isFile()) include(file);
            else throw new Error(`Unsupported frontend entry: ${file}`);
        }
    };
    walk('resources');
    for (const file of [
        'vite.config.ts',
        'tsconfig.json',
        'package.json',
        'package-lock.json',
    ])
        include(file);
    return digest.digest('hex');
}
