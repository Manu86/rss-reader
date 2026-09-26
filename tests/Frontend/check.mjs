import { readdirSync } from 'fs';
import { join, relative, resolve } from 'path';
import { spawnSync } from 'child_process';
import { fileURLToPath } from 'url';

const root = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const excluded = new Set(['.git', 'node_modules', 'vendor', 'var']);

function collect(directory, files) {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
        if (excluded.has(entry.name)) {
            continue;
        }
        const path = join(directory, entry.name);
        if (entry.isDirectory()) {
            collect(path, files);
        } else if (
            entry.isFile()
            && (entry.name.endsWith('.js') || entry.name.endsWith('.mjs'))
        ) {
            files.push(path);
        }
    }
}

const files = [];
let failedFile = null;
collect(root, files);
files.sort();

for (const file of files) {
    const result = spawnSync(process.execPath, ['--check', file], { stdio: 'inherit' });
    if (result.error || result.status !== 0) {
        if (result.error) {
            process.stderr.write(`${result.error.message}\n`);
        }
        process.exitCode = 1;
        failedFile = file;
        break;
    }
}

if (failedFile === null) {
    process.stdout.write(`${files.length} fichier(s) JavaScript vérifié(s).\n`);
} else {
    process.stderr.write(`Échec de la vérification de ${relative(root, failedFile)}.\n`);
}
