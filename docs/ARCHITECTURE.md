# RSS Reader --- Technical Architecture

## Principles

The application must remain simple, explicit, modular, testable and
compatible with conventional PHP hosting.

Business logic must be reusable by HTTP, CLI and cron.

Avoid framework-like abstractions, service locators, unnecessary
interfaces and premature patterns.

## Project structure

``` text
rss-reader/
├── AGENTS.md
├── README.md
├── composer.json
├── bin/
│   └── console
├── config/
├── docs/
├── migrations/
├── public/
│   ├── index.php
│   ├── manifest.webmanifest
│   ├── service-worker.js
│   └── assets/
├── src/
│   ├── Controller/
│   ├── Service/
│   ├── Repository/
│   ├── Model/
│   ├── DTO/
│   ├── Validation/
│   ├── Rss/
│   ├── Http/
│   ├── Security/
│   ├── Storage/
│   ├── Database/
│   └── Console/
├── templates/
├── tests/
└── var/
    ├── database/
    ├── media/
    ├── cache/
    ├── log/
    └── tmp/
```

Refine only when implementation gives a concrete reason.

## Public root

Web server document root is `public/`.

Database, logs, source, configuration, migrations, tests and media
storage must not be directly downloadable.

`public/index.php` is a small front controller that loads
Composer/configuration, bootstraps dependencies, dispatches the request
and emits the response.

## Backend flow

``` text
HTTP request
  → Router / middleware
  → Controller
  → Application service
  → Repository / RSS / storage / security adapter
  → SQLite or external HTTP
```

Controllers:

-   parse request;
-   invoke services;
-   return API responses.

Controllers do not contain SQL, RSS parsing, filesystem logic or remote
HTTP logic.

Services own business rules and transaction coordination.

Repositories own SQL and always support user-scoped access.

Article listing is loaded in SQLite batches and joins the source feed in one query;
the frontend never loads all articles to filter them. Filter names are mapped
through a server-side allowlist, and category/feed filters are ownership-checked
before querying articles.

## Dependency management

Use Composer and PSR-4, typically:

``` text
App\ → src/
```

Prefer constructor injection and an explicit bootstrap/composition root.

A DI container is not required.

Interfaces are useful at external/testable boundaries (remote HTTP,
storage, clock), not mechanically for every class.

## Database

Use one configured PDO connection per request/command as appropriate.

On connection:

``` sql
PRAGMA foreign_keys = ON;
```

Migrations are executed by CLI and are the only supported schema
evolution mechanism.

## HTTP routing

Use a small router, custom or lightweight Composer dependency.

No full PHP framework.

API routes live under `/api`.

Global exception handling converts expected application errors into the
contract from `API.md`; production responses never expose stack traces.

## Sessions

Use native server-side PHP sessions configured according to
`SECURITY.md`.

No session table is required unless implementation needs one.

## Remote HTTP

All remote access goes through one controlled HTTP client abstraction:

-   feed discovery;
-   feed retrieval;
-   images;
-   favicons.

It enforces the SSRF, redirect, timeout, TLS and size rules from
`SECURITY.md`.

The safe client resolves every destination before connecting and pins the
validated address in cURL. Redirects are followed manually so each target is
resolved and validated independently. Environment proxies are disabled for
these requests because proxy-side DNS resolution would bypass address
pinning.

The transport boundary is injectable. Automated tests use controlled DNS and
HTTP doubles and never depend on the public Internet.

The feed-discovery service sits above this client. It recognizes direct RSS
2.0/Atom documents or parses HTML `<link rel="alternate">` declarations,
resolves relative URLs and validates each candidate without selecting one for
the user. XML documents containing a DOCTYPE are rejected at this boundary.

Do not use ad-hoc `file_get_contents()` calls for remote resources.

## RSS

RSS/Atom parsing and normalization live under the RSS layer.

`FeedFetcher` owns remote retrieval and conditional HTTP. `FeedParser`
normalizes RSS 2.0 and Atom into transport-independent models.
`FeedSynchronizationService` coordinates fetching and short database
transactions through repositories; controllers never parse XML or write SQL.
Initial import and both manual-refresh endpoints reuse these components.

Application services consume normalized feed/article objects and should
not contain format-specific XML logic.

See `RSS.md`.

## OPML

`OpmlController` handles the authenticated upload/download boundary.
`OpmlParser` performs bounded, non-networked XML parsing and returns normalized
outline data; `OpmlImportService` coordinates owned categories, duplicate
checks and the existing secure feed synchronization service. Imports are
deliberately partial so one invalid remote feed does not roll back successful
entries. `OpmlExporter` reads only user-scoped repositories and builds OPML
with DOM APIs rather than string-concatenating XML.

## Local media

Store downloaded media outside `public/`, for example under:

``` text
var/media/
```

Database fields store application-controlled relative storage keys,
never arbitrary filesystem paths.

Serve user media through an authenticated/authorized application route
or equivalent controlled mechanism.

File deletion occurs after successful database operations where
practical. Orphan cleanup may remove leftovers from filesystem failures.

Media storage keys are generated by the application and partitioned by user.
The filesystem adapter validates every key before reading or deleting it.
Authenticated resource routes expose feed favicons and article images by their
database identifiers; clients never submit storage keys or paths.

## CLI

`bin/console` reuses the same services as the web application.

Required capabilities include:

``` text
app:install
db:migrate
user:create
user:list
user:password
user:enable
user:disable
feeds:refresh
articles:cleanup
fts:rebuild
```

Exact names may vary slightly but must remain documented and stable.

Commands return `0` on success and non-zero on failure.

`feeds:refresh` returns `0` when every selected feed succeeds, `1` after a
completed batch containing failures, and `75` when the non-blocking process
lock is already held. After synchronization it runs annual article retention.
`articles:cleanup` exposes the same retention service independently and shares
the maintenance lock so cleanup cannot overlap feed imports.

## Cron

Cron calls CLI, never a public HTTP endpoint.

Hourly processing includes feed synchronization and retention cleanup.
It then evaluates recommendation email schedules and sends due digests. Email
failures are isolated per user and never roll back feed synchronization. The
last-send timestamp advances only after the configured transport accepts a message.

One feed failure must not stop processing unrelated feeds.

Automatic feed synchronization selects active feeds through an explicit join
with enabled users, then calls the same `FeedSynchronizationService` used by
manual refresh. A configurable local `flock()` lock prevents overlapping
processes. The lock covers the whole batch and is released in a `finally`
boundary and on process shutdown.

## Configuration

Runtime configuration comes from explicit config/environment values.

Typical values:

-   environment;
-   database path;
-   media path;
-   session settings;
-   HTTP timeouts/limits;
-   base URL when needed.
-   email transport DSN and sender identity.

Recommendation email delivery uses Symfony Mailer with SMTP or a local
sendmail-compatible relay. It is synchronous inside the existing hourly CLI job; no queue or
worker is introduced. Configuration uses `APP_MAILER_DSN`, `APP_MAIL_FROM`,
optional `APP_MAIL_FROM_NAME` (default `RSS Reader`) and `APP_BASE_URL`.
Without `APP_MAILER_DSN`, delivery is disabled and the CLI reports that the
transport is not configured. Transport credentials belong only in the environment and are never
stored or logged.

`APP_SESSION_SECURE` defaults to enabled. It may be disabled only outside
production for local HTTP development when `APP_ENV` is explicitly set to a
non-production value. Production forces secure cookies on regardless of this
setting. Invalid values are rejected at startup.

Session and "remember me" settings:

| Variable                  | Default                | Meaning                                        |
| ------------------------- | ---------------------- | ---------------------------------------------- |
| `APP_SESSION_NAME`        | `rss_reader_session`   | session cookie name                            |
| `APP_SESSION_LIFETIME`    | `7200`                 | session cookie lifetime, in seconds (min 300)  |
| `APP_REMEMBER_COOKIE_NAME` | `rss_reader_remember`  | "remember me" cookie name                      |
| `APP_REMEMBER_LIFETIME`   | `2592000`              | token lifetime, in seconds (min 3600, 30 days) |

The "remember me" cookie reuses `APP_SESSION_SECURE` for its `Secure` flag.

Secrets must not be committed.

## Frontend

The frontend is HTML/CSS/vanilla JS with ES modules and consumes only
the JSON API.

Detailed UI/module behavior belongs in `FRONTEND.md`.

## Logging

Log operational failures without passwords, session IDs, sensitive
article data or secrets.

Cron/CLI should produce useful summaries and exit codes.

## Tests

Tests mirror the architecture:

``` text
tests/Unit/
tests/Integration/
tests/Api/
tests/Security/
tests/Fixtures/
```

Use temporary SQLite databases and local HTTP/feed fixtures. Automated
tests must not require the public Internet.

## Deployment

Production requirements:

-   HTTPS;
-   document root `public/`;
-   writable `var/`;
-   Composer production dependencies installed;
-   migrations applied;
-   cron configured;
-   secure configuration.

Migrations are not applied automatically at runtime. After every update, run:

``` bash
php bin/console db:migrate
```

Skipping this step is not harmless in general: endpoints that read a table
introduced by a pending migration fail. The optional persistent-login table is
the one documented exception, because it degrades to a logged-out user instead
of failing the request.

No container, queue, cache server or microservice infrastructure is
required for V1.
