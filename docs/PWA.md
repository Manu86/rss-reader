# RSS Reader --- PWA Specification

## V1 contract

The PWA provides:

-   installation;
-   application icons;
-   standalone display;
-   cached static application shell;
-   basic offline startup.

It does **not** provide complete offline RSS reading.

RSS synchronization remains server-side through cron/manual API
requests.

## Files

``` text
public/
├── manifest.webmanifest
├── service-worker.js
└── assets/icons/
```

## Manifest

Minimum concept:

``` json
{
  "name": "RSS Reader",
  "short_name": "RSS Reader",
  "start_url": "/",
  "scope": "/",
  "display": "standalone",
  "lang": "fr",
  "icons": []
}
```

Provide suitable 192×192 and 512×512 icons, including maskable variants
where appropriate.

Production requires HTTPS.

## Service worker

Registration failure must not prevent normal website use.

Responsibilities:

-   precache/cache known static application assets;
-   provide cached shell when offline;
-   remove obsolete application cache versions.

It does not synchronize feeds or application data.

## Cache strategy

Conceptually:

``` text
non-GET            → network
/api/*             → network only
known static asset → cache first
navigation         → network first, cached shell fallback
other requests     → network
```

Use versioned cache names such as `rss-reader-static-v1`.

Only cache known safe static resources. Do not dynamically cache
arbitrary successful same-origin responses.

## Never cache in V1

Do not intentionally persist in service-worker Cache Storage:

-   authenticated API responses;
-   article lists/content;
-   feeds/categories/settings;
-   authentication endpoints;
-   OPML operations;
-   manual refresh responses;
-   user article images/favicons.

Do not store application data in IndexedDB/localStorage to simulate
offline mode.

## Offline behavior

Offline shell may load CSS, JS and icons.

Server-dependent features remain unavailable:

``` text
authentication check
articles
search
favorites
read state
feed/category management
settings
OPML
RSS synchronization
```

Show a clear French offline message rather than stale/fake success.

`navigator.onLine` may improve UX but API reachability is authoritative.

When connectivity returns, reload/retry normal view initialization; do
not replay failed mutations automatically.

## No background features

V1 does not use:

-   Background Sync;
-   Periodic Background Sync;
-   Push API;
-   Web Push;
-   browser-side RSS fetching;
-   offline mutation queues.

## Authentication/privacy

A cached shell is not proof of authentication.

`/api/auth/me` requires the server.

After logout/account switch, previous user data must not be shown.

Static cache can remain because it contains no user RSS data.

## Updates

Cache versioning must allow new frontend releases to replace old assets
without manual cache clearing.

A simple optional "new version available / refresh" message is
sufficient.

Avoid aggressive update behavior that breaks an active session.

## Testing

Verify at least:

-   valid manifest/icons;
-   service-worker registration;
-   static shell offline;
-   `/api/*` remains network-only;
-   no authenticated API data in Cache Storage;
-   old cache cleanup;
-   reconnect behavior;
-   user A → logout → user B never exposes user A data.

## Core rule

> The PWA caches the application, not the user's RSS data.

Any future complete offline-reading feature requires a new specification
covering storage, synchronization, privacy, account switching and
conflicts.
