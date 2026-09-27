import assert from 'assert';
import { existsSync, readFileSync, readdirSync } from 'fs';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
import { test } from './harness.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const publicRoot = join(root, 'public');

function readPublic(path) {
    return readFileSync(join(publicRoot, path), 'utf8');
}

test('le manifest PWA déclare des icônes PNG installables', () => {
    const manifest = JSON.parse(readPublic('manifest.webmanifest'));
    assert.strictEqual(manifest.start_url, '/');
    assert.strictEqual(manifest.scope, '/');
    assert.strictEqual(manifest.display, 'standalone');
    assert.deepStrictEqual(manifest.display_override, ['standalone', 'fullscreen']);
    assert.strictEqual(manifest.lang, 'fr');
    assert.ok(Array.isArray(manifest.icons));

    for (const size of ['192x192', '512x512']) {
        const icons = manifest.icons.filter((icon) => icon.sizes === size);
        assert.ok(icons.some((icon) => icon.type === 'image/png' && icon.purpose === 'any'));
        assert.ok(icons.some((icon) => icon.type === 'image/png' && icon.purpose === 'maskable'));
        icons.forEach((icon) => assert.ok(existsSync(join(publicRoot, icon.src.slice(1)))));
    }
});

test('le service worker ne met jamais les routes API en cache', () => {
    const serviceWorker = readPublic('service-worker.js');
    assert.match(serviceWorker, /rss-reader-static-v\d+/);
    assert.match(serviceWorker, /url\.pathname\.startsWith\('\/api\/'\)/);
    assert.match(serviceWorker, /request\.method !== 'GET'/);
    assert.doesNotMatch(serviceWorker, /localStorage|indexedDB|Background Sync|SyncManager/);
    assert.doesNotMatch(serviceWorker, /caches\.put\([^,]*\/api\//);

    const precache = Array.from(
        serviceWorker.matchAll(/'(\/assets\/[^']+)'/g),
        (match) => match[1],
    );
    assert.ok(precache.length > 0);

    const referenced = Array.from(
        [
            readPublic('index.html'),
            ...readdirSync(join(publicRoot, 'assets/js'), { recursive: true })
                .filter((entry) => entry.endsWith('.js'))
                .map((entry) => readPublic(join('assets/js', entry))),
        ]
            .join('\n')
            .matchAll(/'?(?:\/assets\/[\w./-]+\.js(?:\?v=\d+)?)'?/g),
        (match) => match[0].replaceAll("'", '').replaceAll('"', ''),
    );

    for (const url of precache) {
        assert.ok(existsSync(join(publicRoot, url.split('?')[0])), `absent du disque : ${url}`);
    }
    for (const url of referenced) {
        if (!url.startsWith('/assets/js/')) {
            continue;
        }
        assert.ok(precache.includes(url), `non pré-caché par le service worker : ${url}`);
    }
});

test('le shell HTML expose les points d’intégration PWA et accessibilité', () => {
    const html = readPublic('index.html');
    assert.match(html, /rel="manifest"/);
    assert.match(html, /class="skip-link" href="#contenu-principal"/);
    assert.match(html, /<main id="contenu-principal"/);
    assert.match(html, /aria-label="Navigation principale"/);
    assert.match(html, /<label[^>]+for="global-search-input">Rechercher dans les articles<\/label>/);
    assert.match(readPublic('assets/js/app.js'), /service-worker\.js/);
});

test('les barres système suivent le fond de page du thème actif', () => {
    // En plein écran les barres système sont masquées, mais theme-color reste
    // la couleur des interfaces du navigateur et des plateformes qui lisent le
    // meta : il doit donc reproduire le fond de page réel de chaque thème.
    const css = readPublic('assets/css/app.css');
    const app = readPublic('assets/js/app.js');
    const html = readPublic('index.html');

    const softColor = (pattern, label) => {
        const block = css.match(pattern);
        assert.ok(block, `bloc CSS introuvable : ${label}`);
        const color = block[1].match(/--soft:\s*(#[0-9a-f]{6})/i);
        assert.ok(color, `--soft introuvable dans ${label}`);
        return color[1];
    };

    const light = softColor(/:root\s*\{([^}]*)\}/, ':root');
    const dark = softColor(/\[data-theme="dark"\]\s*\{([^}]*)\}/, '[data-theme="dark"]');

    const meta = html.match(/<meta name="theme-color"[^>]*>/);
    assert.ok(meta, 'le shell HTML ne déclare pas theme-color');
    assert.match(meta[0], /id="theme-color"/);

    // Le shell statique reprend la valeur par défaut, sans flash de barre claire.
    assert.ok(meta[0].includes(light), 'theme-color du shell hors du fond de page clair');
    assert.strictEqual(
        app.match(/light:\s*'(#[0-9a-f]{6})'/i)[1],
        light,
        'la couleur claire de app.js ne reprend pas --soft',
    );
    assert.strictEqual(
        app.match(/dark:\s*'(#[0-9a-f]{6})'/i)[1],
        dark,
        'la couleur sombre de app.js ne reprend pas --soft',
    );
    assert.match(app, /dom\.themeColor\?\.setAttribute\('content', isDark \? THEME_COLORS\.dark : THEME_COLORS\.light\)/);
    assert.match(app, /dom\.themeColor = byId\('theme-color'\)/);
});

test('le plein écran réserve les zones sûres de l’écran', () => {
    // En plein écran le contenu passe sous l’encoche et la barre de gestes :
    // sans ces marges, l’en-tête et la déconnexion seraient illisibles.
    const css = readPublic('assets/css/app.css');
    assert.match(readPublic('index.html'), /viewport-fit=cover/);

    assert.match(css, /html\s*\{[^}]*text-size-adjust: 100%/);
    assert.match(css, /\.app-header\s*\{[^}]*padding:\s*calc\(\.75rem \+ env\(safe-area-inset-top\)\)/);
    assert.match(css, /body\s*\{[^}]*padding-bottom:\s*env\(safe-area-inset-bottom\)/);
    assert.match(css, /\.sidebar\s*\{[^}]*padding: 1\.25rem \.85rem calc\(1\.25rem \+ env\(safe-area-inset-bottom\)\)/);
});
