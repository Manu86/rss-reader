# RSS Reader --- Database Specification

## Principles

-   SQLite accessed through PDO.
-   `PRAGMA foreign_keys = ON` for every connection.
-   WAL may be enabled when compatible with hosting.
-   UTC dates, preferably ISO 8601.
-   Booleans stored as `0/1`.
-   Database file stored outside `public/`.
-   All user-owned queries are scoped by `user_id`.
-   Schema changes use versioned migrations.

## `users`

``` sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    email TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
```

Passwords use PHP's password API.

`email` is optional profile information, limited to 254 characters and
validated by the application. It is not unique and is not used for login.

## `user_settings`

``` sql
CREATE TABLE user_settings (
    user_id INTEGER PRIMARY KEY,
    articles_per_page INTEGER NOT NULL DEFAULT 25,
    theme TEXT NOT NULL DEFAULT 'light',
    recommendation_email_frequency TEXT NOT NULL DEFAULT 'never',
    recommendation_email_last_sent_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

The `articles_per_page` column is retained for API compatibility and defaults to
25, but the frontend always loads fixed batches of 25 articles. Allowed explicit
API batch sizes and themes are controlled by the application.

`recommendation_email_frequency` is one of `never`, `daily`, `weekly` or
`monthly`. `recommendation_email_last_sent_at` stores the UTC time of the last
successful digest only; failures and empty recommendation sets do not advance
it.

## `categories`

``` sql
CREATE TABLE categories (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

Unique case-insensitive name per user:

``` sql
CREATE UNIQUE INDEX idx_categories_user_name
ON categories(user_id, name COLLATE NOCASE);
```

`Sans catégorie` is virtual; uncategorized feeds use
`category_id = NULL`.

## `feeds`

Required columns:

``` text
id
user_id
category_id NULL
name
feed_url
site_url NULL
favicon_path NULL
is_active
last_fetch_attempt_at NULL
last_successful_fetch_at NULL
last_article_at NULL
last_fetch_status NULL
last_fetch_error NULL
etag NULL
last_modified NULL
created_at
updated_at
```

Foreign keys:

-   `user_id -> users(id) ON DELETE CASCADE`
-   `category_id -> categories(id) ON DELETE SET NULL`

Rules:

-   feed URL unique per user after application normalization;
-   assigned category must belong to the same user;
-   status values should remain simple, e.g. `never`, `success`,
    `error`.

## `articles`

Required columns:

``` text
id
user_id
feed_id
guid NULL
guid_hash NULL
title
url NULL
author NULL
published_at NULL
discovered_at
summary NULL
content NULL
tags NULL
image_path NULL
image_metadata_checked_at NULL
is_read
is_favorite
deduplication_hash
created_at
updated_at
```

Foreign keys:

-   `user_id -> users(id) ON DELETE CASCADE`
-   `feed_id -> feeds(id) ON DELETE CASCADE`

Rules:

-   article and feed must belong to the same user;
-   `(feed_id, deduplication_hash)` is unique;
-   `discovered_at` is set on first import and remains stable;
-   synchronization must not reset `is_read` or `is_favorite`;
-   `image_metadata_checked_at` records when the article-page image metadata
    fallback was attempted; it is set only for articles without a feed image
    candidate and prevents repeating that fallback on every synchronization;
-   `tags` stores the feed-provided article tags as a JSON array of
    non-empty strings (duplicates removed, at most 10 tags of at most
    100 characters each); the value is `NULL` when the item has no tag.

Default ordering:

``` sql
ORDER BY COALESCE(published_at, discovered_at) DESC, id DESC
```

## Full-text search

Use SQLite FTS5 for title, summary, content and author.

Recommended external-content table:

``` sql
CREATE VIRTUAL TABLE articles_fts USING fts5(
    title,
    summary,
    content,
    author,
    content='articles',
    content_rowid='id'
);
```

Keep FTS synchronized with article creation/deletion, preferably through
simple database triggers.

Search results must still be joined/scoped to `user_id`.

Provide a way to rebuild the FTS index.

The implementation converts user input to bounded literal prefix terms before
passing it to FTS5. Search queries join indexed row identifiers back to
`articles` and `feeds`, then apply the authenticated `user_id` and owned
category/feed filters. Run `php bin/console fts:rebuild` to reconstruct the
index from the `articles` table.

## Indexes

Add indexes supporting common queries, especially:

-   articles by user/date;
-   articles by feed/date;
-   unread by user;
-   favorites by user;
-   feeds by user/category;
-   active feeds.

Avoid speculative indexes without a query need.

## Deletion behavior

-   Delete category → feeds remain with `category_id = NULL`.
-   Delete feed → its articles are deleted by cascade.
-   Media deletion is coordinated by application services.
-   Database cascade may support user deletion, but V1 does not need to
    expose user deletion.

## Retention

Cleanup removes non-favorite articles older than one year.

Reference date:

1.  valid `published_at`;
2.  otherwise `discovered_at`.

Malformed/implausible remote dates are normalized according to `RSS.md`.

The implemented cutoff is the current UTC clock minus one calendar year.
Rows whose reference date is strictly earlier than that cutoff are deleted;
the exact boundary is retained. Cleanup runs in bounded batches, rechecks the
favorite/reference-date predicates inside an immediate SQLite transaction and
lets the existing delete trigger remove corresponding FTS rows. Candidate
image files are removed only after commit and only when no owned feed/article
still references them.

## Transactions

Use short transactions for multi-step database writes.

Do not keep a write transaction open during remote HTTP requests.

Unique constraints are the final protection against concurrent duplicate
insertion.

## Migrations

Store migrations under `migrations/`, for example:

``` text
001_initial_schema.sql
002_articles_fts.sql
003_user_settings_theme.sql
004_article_tags.sql
005_user_remember_tokens.sql
006_users_email.sql
007_recommendation_email_delivery.sql
008_article_image_metadata_checked.sql
```

Track applied versions in a migration table.

A fresh installation must be reproducible entirely from migrations.

`remote_action_attempts` stores short-lived per-user counters for expensive
remote operations such as feed discovery. Rows reference `users` with
`ON DELETE CASCADE`; they contain no URL, response body, session identifier or
credential.

## `user_remember_tokens`

Opt-in persistent-login tokens backing the "remember me" cookie.

``` sql
CREATE TABLE user_remember_tokens (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    selector TEXT NOT NULL UNIQUE,
    token_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

-   `selector` is the indexed lookup key of the `selector.validator` cookie
    value; it is random but is not the secret;
-   `token_hash` stores `sha256(validator)`, never the validator itself, so the
    table can never be replayed as a credential;
-   `expires_at` is absolute: tokens are not extended on use;
-   indexes cover `user_id` (revocation) and `expires_at` (pruning);
-   rows are removed with their user through `ON DELETE CASCADE`.

The table holds no session identifier, and its contents must never be exposed
through the API or the logs.

## Storage

Recommended database path:

``` text
var/database/rss-reader.sqlite
```

Backups should include both SQLite data and local media.
