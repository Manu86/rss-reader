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
