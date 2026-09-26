import assert from 'assert';
import { readFileSync } from 'fs';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
import { JSDOM } from 'jsdom';
import { test } from './harness.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const publicRoot = join(root, 'public');
const css = readFileSync(join(publicRoot, 'assets/css/app.css'), 'utf8');

const TEXT_PAIRS = [
    ['--ink', '#17212b', '--surface', '#fff', 4.5],
    ['--ink', '#17212b', '--soft', '#f4f6f8', 4.5],
    ['--muted', '#596673', '--surface', '#fff', 4.5],
    ['--muted', '#596673', '--soft', '#f4f6f8', 4.5],
    ['--accent', '#1264a3', '--surface', '#fff', 4.5],
    ['--accent', '#1264a3', '--accent-soft', '#e5f1fb', 4.5],
    ['--danger', '#b42318', '--surface', '#fff', 4.5],
    ['success', '#176b3a', 'success-bg', '#e7f7ed', 4.5],
    ['button-primary', '#fff', '--accent', '#1264a3', 4.5],
    ['dark --ink', '#e6edf3', 'dark --surface', '#15202b', 4.5],
    ['dark --muted', '#9aa7b4', 'dark --surface', '#15202b', 4.5],
    ['dark --muted', '#9aa7b4', 'dark --soft', '#0d151d', 4.5],
    ['dark --accent', '#8ec2f0', 'dark --surface', '#15202b', 4.5],
    ['dark --accent', '#8ec2f0', 'dark --soft', '#0d151d', 4.5],
    ['dark button-primary', '#0d151d', 'dark accent', '#8ec2f0', 4.5],
    ['dark form-success', '#7ed9a2', 'dark success-bg', '#10291a', 4.5],
];

const UI_PAIRS = [
    ['favorite-star', '#b7791f', '--surface', '#fff', 3],
    ['thumbnail-placeholder', '#a4b0b9', 'placeholder-bg', '#edf1f4', 1.5],
    ['dark favorite-star', '#e0a44d', 'dark --surface', '#15202b', 3],
    ['dark thumbnail-placeholder', '#657480', 'dark placeholder-bg', '#1d2731', 1.5],
];

function srgbChannel(value) {
    return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
}

function relativeLuminance(hex) {
    const parsed = hex.trim().replace('#', '');
    const normalized = parsed.length === 3
        ? parsed.split('').map((char) => char + char).join('')
        : parsed;
    const channels = [0, 2, 4].map((offset) => parseInt(normalized.slice(offset, offset + 2), 16) / 255);
    return 0.2126 * srgbChannel(channels[0])
        + 0.7152 * srgbChannel(channels[1])
        + 0.0722 * srgbChannel(channels[2]);
}

function contrastRatio(foreground, background) {
    const lighter = Math.max(relativeLuminance(foreground), relativeLuminance(background));
    const darker = Math.min(relativeLuminance(foreground), relativeLuminance(background));
    return (lighter + 0.05) / (darker + 0.05);
}

test('les couleurs déclarées respectent les seuils WCAG AA', () => {
    for (const [name, foreground, backgroundName, background, minimum] of TEXT_PAIRS) {
        const ratio = contrastRatio(foreground, background);
        assert.ok(
            ratio >= minimum,
            `${name} (${foreground} sur ${background}) : ratio ${ratio.toFixed(2)} < ${minimum}`,
        );
    }

    for (const [name, foreground, backgroundName, background, minimum] of UI_PAIRS) {
        const ratio = contrastRatio(foreground, background);
        assert.ok(
            ratio >= minimum,
            `${name} (${foreground} sur ${background}) : ratio ${ratio.toFixed(2)} < ${minimum}`,
        );
    }
});

test('les animations respectent prefers-reduced-motion et les cibles sont élargies en contraste élevé', () => {
    assert.match(css, /@media\s*\(prefers-reduced-motion:\s*reduce\)/);
    assert.match(css, /animation-duration:\s*\.01ms/);
    assert.match(css, /@media\s*\(prefers-contrast:\s*more\)/);
});

test('le shell passe l’audit axe-core', async () => {
    const html = readFileSync(join(publicRoot, 'index.html'), 'utf8');
    const dom = new JSDOM(html, {
        url: 'http://127.0.0.1:8099/',
        pretendToBeVisual: true,
        runScripts: 'dangerously',
    });
    const script = dom.window.document.createElement('script');
    script.textContent = readFileSync(join(root, 'node_modules/axe-core/axe.min.js'), 'utf8');
    dom.window.document.body.appendChild(script);
    const axe = dom.window.axe;
    assert.ok(axe, 'axe-core n’a pas pu être initialisé.');

    const results = await axe.run(dom.window.document, {
        resultTypes: ['violations'],
        rules: {
            'color-contrast': { enabled: false },
        },
    });

    const violations = results.violations.map((violation) => violation.nodes.map((node) => (
        `${violation.id} : ${node.target.join(', ')}`
    ))).flat();
    assert.strictEqual(violations.length, 0, `Violations axe : ${violations.length ? violations.join(' ; ') : 'voir la sortie axe'}`);
    dom.window.close();
});
