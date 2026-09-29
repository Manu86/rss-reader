import assert from 'assert';
import { existsSync, readFileSync, readdirSync } from 'fs';
import { dirname, join, posix } from 'path';
import { fileURLToPath } from 'url';
import { inflateSync } from 'zlib';
import { test } from './harness.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const publicRoot = join(root, 'public');

function readPublic(path) {
    return readFileSync(join(publicRoot, path), 'utf8');
}

function collectJsFiles(directory, prefix = '') {
    // `readdirSync(..., { recursive: true })` est ignoré sur Node 18.15.
    const files = [];
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
        const relative = prefix ? `${prefix}/${entry.name}` : entry.name;
        if (entry.isDirectory()) {
            files.push(...collectJsFiles(join(directory, entry.name), relative));
        } else if (entry.name.endsWith('.js')) {
            files.push(relative);
        }
    }

    return files.sort();
}

function corners(image) {
    const last = image.width - 1;

    return [[0, 0], [last, 0], [0, last], [last, last]];
}

function glyphRadius(image) {
    let radius = 0;
    for (let y = 0; y < image.height; y++) {
        for (let x = 0; x < image.width; x++) {
            const { r, g, b } = image.pixel(x, y);
            if (r <= 200 || g <= 200 || b <= 200) {
                continue;
            }
            radius = Math.max(
                radius,
                Math.hypot(x + 0.5 - image.width / 2, y + 0.5 - image.height / 2),
            );
        }
    }

    return radius;
}

function decodePng(buffer) {
    assert.deepStrictEqual(
        Array.from(buffer.subarray(0, 8)),
        [137, 80, 78, 71, 13, 10, 26, 10],
        'signature PNG invalide',
    );

    const chunks = [];
    let header = null;
    let offset = 8;
    while (offset < buffer.length) {
        const length = buffer.readUInt32BE(offset);
        const type = buffer.toString('ascii', offset + 4, offset + 8);
        if (type === 'IHDR') {
            header = {
                width: buffer.readUInt32BE(offset + 8),
                height: buffer.readUInt32BE(offset + 12),
                depth: buffer[offset + 16],
                colorType: buffer[offset + 17],
            };
        } else if (type === 'IDAT') {
            chunks.push(buffer.subarray(offset + 8, offset + 8 + length));
        } else if (type === 'IEND') {
            break;
        }
        offset += 12 + length;
    }

    assert.ok(header, 'en-tête PNG absent');
    assert.strictEqual(header.depth, 8, 'profondeur PNG non gérée');
    const channels = { 0: 1, 2: 3, 4: 2, 6: 4 }[header.colorType];
    assert.ok(channels, `type de couleur PNG non géré : ${header.colorType}`);

    const raw = inflateSync(Buffer.concat(chunks));
    const stride = header.width * channels;
    const pixels = Buffer.alloc(stride * header.height);
    let previous = Buffer.alloc(stride);
    for (let y = 0; y < header.height; y++) {
        const filter = raw[y * (stride + 1)];
        const line = raw.subarray(y * (stride + 1) + 1, y * (stride + 1) + 1 + stride);
        const current = pixels.subarray(y * stride, (y + 1) * stride);
        for (let i = 0; i < stride; i++) {
            const left = i >= channels ? current[i - channels] : 0;
            const up = previous[i];
            const upLeft = i >= channels ? previous[i - channels] : 0;
            const estimate = left + up - upLeft;
            const distance = [
                Math.abs(estimate - left),
                Math.abs(estimate - up),
                Math.abs(estimate - upLeft),
            ];
            const prediction = [left, up, upLeft][distance.indexOf(Math.min(...distance))];
            const value = line[i]
                + [0, left, up, Math.floor((left + up) / 2), prediction][filter];
            current[i] = value & 0xFF;
        }
        previous = current;
    }

    return {
        width: header.width,
        height: header.height,
        pixel(x, y) {
            const start = y * stride + x * channels;
            return {
                r: pixels[start],
                g: pixels[start + (channels > 1 ? 1 : 0)],
                b: pixels[start + (channels > 2 ? 2 : 0)],
                a: channels === 4 ? pixels[start + 3] : channels === 2 ? pixels[start + 1] : 255,
            };
        },
    };
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

test('les icônes d’installation occupent toute la tuile', () => {
    // Le système affiche ces icônes en grand (écran de démarrage, lanceur) et
    // centre l’icône sur background_color : un cadre clair ou transparent
    // autour du carré bleu se lit alors comme un élément supplémentaire. Les
    // icônes d’installation sont donc pleines, et le glyphe reste dans la zone
    // sûre de 80 % laissée libre par le masque du système.
    const manifest = JSON.parse(readPublic('manifest.webmanifest'));
    assert.match(manifest.background_color, /^#[0-9a-f]{6}$/i);

    for (const icon of manifest.icons) {
        const image = decodePng(readFileSync(join(publicRoot, icon.src.slice(1))));
        const last = image.width - 1;
        const samples = [...corners(image), [image.width / 2, 0], [image.width / 2, last], [0, image.height / 2], [last, image.height / 2]];
        for (const [x, y] of samples) {
            const { r, g, b, a } = image.pixel(Math.floor(x), Math.floor(y));
            assert.strictEqual(a, 255, `bord transparent dans ${icon.src} en (${x},${y})`);
            assert.ok(b > r && b > g, `bord non bleu dans ${icon.src} en (${x},${y})`);
        }
        assert.ok(glyphRadius(image) <= image.width * 0.4, `glyphe hors zone sûre : ${icon.src}`);
    }

    const apple = readPublic('index.html').match(/<link rel="apple-touch-icon" href="([^"]+)"/);
    assert.ok(apple, 'le shell HTML ne déclare pas d’icône apple-touch');
    const icon = decodePng(readFileSync(join(publicRoot, apple[1].slice(1))));
    for (const [x, y] of corners(icon)) {
        const { r, g, b, a } = icon.pixel(x, y);
        assert.strictEqual(a, 255, `icône apple-touch transparente en (${x},${y})`);
        assert.ok(b > r && b > g, `icône apple-touch sans fond bleu en (${x},${y})`);
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
            ...collectJsFiles(join(publicRoot, 'assets/js')).map((entry) => readPublic(join('assets/js', entry))),
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

test('le graphe de modules et le pré-cache du service worker concordent', () => {
    // Les imports relatifs échappent au scan des URL absolues : sans cette
    // vérification, un module importé sans sa version était absent du
    // pré-cache et cassait le chargement hors ligne.
    const serviceWorker = readPublic('service-worker.js');
    const precache = Array.from(
        serviceWorker.matchAll(/'(\/assets\/[^']+)'/g),
        (match) => match[1],
    );
    const moduleFiles = collectJsFiles(join(publicRoot, 'assets/js'));
    const specifiers = new Map();

    for (const entry of moduleFiles) {
        const file = join('assets/js', entry);
        const source = readPublic(file);
        for (const match of source.matchAll(/(?:from|import)\s*\(?\s*'([^']+\.js(?:\?v=\d+)?)'/g)) {
            const [target, query] = match[1].split('?');
            const resolved = `${posix.normalize(posix.join(posix.dirname(`/${file}`), target))}${query ? `?${query}` : ''}`;
            assert.ok(precache.includes(resolved), `non pré-caché par le service worker : ${resolved} (importé par ${file})`);
            if (!specifiers.has(target)) {
                specifiers.set(target, new Map());
            }
            const byModule = specifiers.get(target);
            byModule.set(file, match[1]);
        }
    }

    // Un même module importé avec deux chaînes différentes est instancié deux
    // fois par le navigateur : son état local n'est alors pas partagé.
    for (const [target, byModule] of specifiers) {
        const distinct = new Set(byModule.values());
        assert.equal(
            distinct.size,
            1,
            `${target} est importé avec ${distinct.size} chaînes différentes : ${[...distinct].join(', ')}`,
        );
    }

    const shell = readPublic('index.html');
    for (const match of shell.matchAll(/src="(\/assets\/js\/[^"]+\.js(?:\?v=\d+)?)"/g)) {
        assert.ok(precache.includes(match[1]), `non pré-caché par le service worker : ${match[1]}`);
    }
    assert.match(shell, /CACHE_NAME|app\.js\?v=\d+/);
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
