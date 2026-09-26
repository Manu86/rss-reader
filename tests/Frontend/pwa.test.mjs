import assert from 'assert';
import { existsSync, readFileSync } from 'fs';
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
    assert.match(serviceWorker, /rss-reader-static-v53/);
    assert.match(readPublic('index.html'), /assets\/css\/app\.css\?v=46/);
    assert.match(serviceWorker, /assets\/css\/app\.css\?v=46/);
    assert.match(readPublic('index.html'), /assets\/js\/app\.js\?v=31/);
    assert.match(readPublic('assets/js/app.js'), /views\/articles\.js\?v=25/);
    assert.match(serviceWorker, /assets\/js\/views\/articles\.js\?v=25/);
    assert.match(readPublic('assets/js/app.js'), /views\/reader\.js\?v=28/);
    assert.match(readPublic('assets/js/app.js'), /views\/settings\.js\?v=2/);
    assert.match(serviceWorker, /assets\/js\/views\/reader\.js\?v=28/);
    assert.match(readPublic('assets/js/app.js'), /router\.js\?v=25/);
    assert.match(readPublic('assets/js/views/articles.js'), /router\.js\?v=24/);
    assert.match(serviceWorker, /assets\/js\/router\.js\?v=24/);
    assert.match(serviceWorker, /assets\/js\/router\.js\?v=25/);
    assert.match(serviceWorker, /url\.pathname\.startsWith\('\/api\/'\)/);
    assert.match(serviceWorker, /request\.method !== 'GET'/);
    assert.doesNotMatch(serviceWorker, /localStorage|indexedDB|Background Sync|SyncManager/);
    assert.doesNotMatch(serviceWorker, /caches\.put\([^,]*\/api\//);
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
