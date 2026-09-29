const CACHE_NAME = 'rss-reader-static-v91';
const STATIC_ASSETS = [
    '/',
    '/manifest.webmanifest',
    '/assets/css/app.css?v=66',
    '/assets/js/app.js?v=52',
    '/assets/js/api/client.js?v=3',
    '/assets/js/router.js?v=26',
    '/assets/js/components/dialog.js',
    '/assets/js/components/feedback.js',
    '/assets/js/utils/dom.js',
    '/assets/js/utils/format.js',
    '/assets/js/views/articles.js?v=28',
    '/assets/js/views/feed-dialogs.js',
    '/assets/js/views/login.js',
    '/assets/js/views/management.js?v=4',
    '/assets/js/views/reader.js?v=35',
    '/assets/js/views/settings.js?v=8',
    '/assets/icons/icon.svg',
    '/assets/icons/icon-192.svg',
    '/assets/icons/icon-512.svg',
    '/assets/icons/apple-touch-icon.png',
    '/assets/icons/icon-192-v2.png',
    '/assets/icons/icon-192-maskable-v2.png',
    '/assets/icons/icon-512-v2.png',
    '/assets/icons/icon-512-maskable-v2.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key.startsWith('rss-reader-static-') && key !== CACHE_NAME)
                    .map((key) => caches.delete(key)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/')) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put('/', copy));
                    return response;
                })
                .catch(() => caches.match('/')),
        );
        return;
    }

    event.respondWith(
        caches.match(request).then((cached) => cached || fetch(request)),
    );
});
