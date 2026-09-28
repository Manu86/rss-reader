# RSS Reader --- Testing and Quality

## Quality gate

Required Composer commands:

``` bash
composer test
composer analyse
composer lint
composer check
```

`composer check` runs lint, static analysis and tests and is the local
completion gate.

`composer audit` is recommended separately because it may require
network access.

A task is complete only when relevant tests exist and `composer check`
passes.

## Tools

Required:

-   PHPUnit;
-   PHPStan;
-   PHP syntax/config linting;
-   Composer validation where practical.

Use a strong practical PHPStan level (target level 8+ for this new
codebase).

Avoid broad baselines/ignores that hide new errors.

Use `declare(strict_types=1);` in application PHP code where
appropriate.

## Test structure

``` text
tests/
├── Unit/
├── Integration/
├── Api/
├── Security/
├── Cli/
├── Fixtures/
│   ├── rss/
│   ├── atom/
│   ├── html/
│   ├── opml/
│   └── media/
└── Support/
```

Create directories only when needed.

## General rules

Tests must be:

-   deterministic;
-   independent of execution order;
-   independent of public Internet access;
-   isolated from production database/media;
-   focused on observable behavior.

Use temporary SQLite databases and temporary storage directories.

Run migrations to create test databases.

SQLite integration tests use real SQLite, not mocked PDO.

## Unit tests

Cover isolated logic such as:

-   URL/date normalization;
-   deduplication identity/hash;
-   validation;
-   infinite-scroll batch loading;
-   normalized feed/article mapping.

## Integration/API tests

Cover:

-   service + repository + SQLite;
-   RSS parser + local fixtures;
-   OPML;
-   API authentication/validation/status/JSON contract;
-   database constraints and cascades.

API tests should assert documented behavior, not private method calls.

## Mandatory user-isolation tests

Create at least User A and User B.

Verify A cannot read/update/delete/refresh B's resources and search
never returns B's articles.

Two users subscribing to the same feed must have independent
subscriptions, articles, read states and favorites.

These tests are mandatory.

## Authentication/security tests

Cover:

-   valid/invalid login;
-   disabled account;
-   logout/session invalidation;
-   session regeneration where testable;
-   password change;
-   optional email update, validation and user isolation;
-   recommendation email schedule boundaries, no duplicate period delivery,
    empty recommendations, transport failure and safe email rendering;
-   generic authentication errors;
-   CSRF valid/missing/invalid;
-   cross-user resource access;
-   no sensitive information in error responses.

Security-specific cases are further listed below.

## Database tests

Verify:

-   migrations from empty database;
-   foreign keys enabled;
-   unique username;
-   unique feed per user;
-   category deletion → feeds uncategorized;
-   feed deletion → articles cascade;
-   deduplication uniqueness;
-   FTS synchronization;
-   retention behavior.

## RSS/Atom fixtures

Use local fixtures covering at least:

-   normal RSS;
-   normal Atom;
-   empty feed;
-   missing GUID/ID;
-   missing/invalid date;
-   duplicate identifier;
-   relative URLs;
-   HTML content;
-   malicious HTML;
-   media;
-   malformed XML.

Feed discovery fixtures cover zero/one/multiple feeds and relative URLs.

Never depend on live public feeds.

## Synchronization tests

Cover:

-   first import;
-   no new article;
-   new article;
-   repeated synchronization;
-   malformed feed/article;
-   disabled feed;
-   HTTP failure/timeout;
-   redirect;
-   dedicated article-page User-Agent without leaking it to feed or image requests;
-   conditional `ETag`/`Last-Modified` and `304`;
-   preservation of read/favorite/discovered state;
-   article remains when it disappears remotely;
-   one feed failure does not stop others.

Use a fake/controlled remote HTTP adapter.

## Search

Verify title, summary, content and author search, infinite-scroll loading, special
input, deletion consistency and strict user isolation.

FTS5 must be available in the test environment.

Recommendation tests cover strict user isolation, candidate generation from
FTS, tags and categories, exclusion of read/favorite articles, recent-favorite
ordering, per-feed diversification before and after scoring, weighted sampling,
freshness and bounded result sizes.

## Retention

Using deterministic time where practical, test:

-   younger than one year retained;
-   expired non-favorite removed;
-   expired favorite retained;
-   missing publication date falls back to discovery date;
-   associated unused media cleaned.

## OPML

Test:

-   valid import;
-   categories;
-   duplicates;
-   invalid entries;
-   malformed/unsafe XML;
-   export valid XML;
-   only current user's feeds;
-   import/export round trip for subscription/category structure.

## CLI

Test important commands and exit codes:

-   install/migrate;
-   user create/list/password/enable/disable;
-   feed synchronization;
-   cleanup;
-   FTS rebuild where exposed.

Success returns `0`; failure returns non-zero.

## Security fixtures/tests

At minimum cover:

### SSRF

-   localhost/loopback;
-   private/link-local IPv4;
-   local/private IPv6;
-   unsupported schemes;
-   URL credentials;
-   public redirect to private destination;
-   redirect loops/limit.

### XML

-   external entities;
-   local file/network entity attempts;
-   abusive entity expansion.

### XSS

Verify sanitizer removes scripts, event attributes, dangerous URLs and
active embeds while retaining legitimate reading markup.

### Media/files

-   valid supported images;
-   fake MIME/content;
-   oversized image;
-   SVG rejection;
-   traversal-like filename;
-   storage path cannot escape media root.
-   recommendation thumbnails are ownership-scoped, resized to 88 × 88 and
    bounded to 100 KB each.

## Frontend/PWA validation

Critical workflows should be checked in a real browser:

-   login/logout;
-   article list/read/favorite/search;
-   add/manage/refresh feed;
-   categories;
-   settings;
-   responsive layout;
-   keyboard navigation.

Accessibility checks should also cover semantic landmarks and control names,
visible keyboard focus, form label/error associations, focus behavior in the
mobile navigation and dialogs, and announcements for dynamic status/errors.
Verify that essential state is not communicated by color alone and that
responsive use does not require horizontal scrolling for normal controls.

PWA checks:

-   manifest/service worker;
-   offline shell;
-   API not cached;
-   offline mutations do not fake success;
-   account switching does not expose previous user data.

A heavy frontend test framework is optional; do not introduce a large
Node ecosystem solely for V1 tests.

## Regression rule

For meaningful bugs, especially
security/parser/authorization/deduplication bugs:

``` text
reproduce → failing test → fix → composer check
```

Keep the regression test.

## Coding-agent completion rule

Before reporting a task complete, an agent must:

1.  read the relevant specification files;
2.  implement only documented/requested scope;
3.  add/update relevant tests;
4.  run focused tests;
5.  run `composer check`;
6.  report failures honestly;
7.  update documentation when behavior changes.

Agents must not delete/skip/weaken tests or PHPStan rules merely to
obtain a green result.

## V1 final validation

Before release:

``` bash
composer check
composer audit
```

Also manually verify a fresh install, account creation/login, RSS + Atom
subscriptions, synchronization, reading/favorites/search, categories,
OPML, settings, logout, PWA installation/offline shell, mobile layout
and keyboard navigation.
