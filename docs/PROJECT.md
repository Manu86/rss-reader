# RSS Reader --- Project Specification

## Purpose

RSS Reader is a self-hosted, multi-user web application for subscribing
to, retrieving, reading, searching and organizing RSS and Atom feeds.

Priorities: simplicity, privacy, user isolation, security,
accessibility, maintainability, performance and limited external
dependencies.

Accessibility is a technical requirement across the interface. The
interaction rules live in `FRONTEND.md`; validation expectations live in
`TESTING.md`. These requirements do not authorize unrelated changes to the
interface's visual presentation.

## Stack

-   PHP, object-oriented, Composer
-   SQLite through PDO
-   JSON API
-   HTML, CSS and vanilla JavaScript with ES modules
-   PWA, offline application shell
-   Server-side cron for feed synchronization
-   No full PHP framework
-   No frontend framework

Use a recent, maintained PHP version. Exact runtime and dependency
versions belong in `composer.json`.

## Users and isolation

-   No public registration in V1: the first account is created during
    installation, additional accounts are managed through the CLI, and an
    authenticated user can change their own password. The CLI command list
    lives in `ARCHITECTURE.md`; account behavior in `FEATURES.md`.
-   Each user has an independent RSS environment. Subscriptions, categories,
    articles, read states, favorites, settings and local media are never
    shared between users, even when two users subscribe to the same feed.
-   Backend authorization must enforce this isolation; the security model
    (sessions, CSRF, SSRF, sanitization) is defined in `SECURITY.md`.

## Functional scope

V1 covers the following capabilities. The detailed functional behavior is
owned by `FEATURES.md`, the HTTP contract by `API.md`, the persistence
model by `DATABASE.md`, and the synchronization/parsing rules by `RSS.md`.

-   Subscriptions: direct RSS/Atom URLs or website discovery, per-feed
    settings (name, URLs, category, active state), manual refresh, delete
    cascades to articles and unused local media.
-   Categories: optional, one per subscription; `Sans catégorie` is the
    virtual group for uncategorized subscriptions; deleting a category
    keeps its subscriptions.
-   Articles: stored locally (title, URLs, GUID, author, dates, summary,
    content, local image, read/favorite state), newest first, filtered by
    all/unread/read/favorites/category/feed, full-text search, in-app
    display of sanitized feed content, tags imported from feeds displayed
    read-only, favorite-based local recommendations.
-   Synchronization: hourly cron, manual refresh (one feed or all),
    no duplicate articles, distinct published/discovered dates, local
    download of primary images and favicons. Parsing rules in `RSS.md`.
-   Retention: normal articles older than one year are removable; favorites
    exempt; dates and rules in `DATABASE.md` and `RSS.md`.
-   OPML import and export, including categories.
-   Settings: optional account email and recommendation digest frequency,
    password change, fixed 25-article loads, light/dark theme, OPML
    import/export.
-   PWA: installable with offline shell, no offline article storage, no
    push notifications, details in `PWA.md`.

## V1 exclusions

V1 excludes:

-   public registration;
-   email password recovery;
-   OAuth/social login;
-   sharing between users;
-   comments/social features;
-   AI summaries/classification;
-   AI or cross-user recommendations (basic local content-based feed
    recommendations are provided, see `FEATURES.md`);
-   push notifications;
-   complete offline article synchronization;
-   user-managed tags (feed-provided article tags are imported, stored and
    displayed read-only);
-   multiple categories per feed;
-   third-party synchronization;
-   native mobile applications;
-   microservices;
-   external database/search infrastructure.

## Quality

Quality gates (lint, static analysis, tests) and acceptance rules are
defined in `TESTING.md`; run `composer check` before considering a task
complete.

## Documentation ownership

Each topic has one primary source of truth:

-   `PROJECT.md` --- scope and product principles
-   `FEATURES.md` --- functional behavior
-   `DATABASE.md` --- persistence model
-   `API.md` --- HTTP contract
-   `ARCHITECTURE.md` --- code organization
-   `RSS.md` --- RSS/Atom processing
-   `SECURITY.md` --- security rules
-   `FRONTEND.md` --- UI behavior
-   `PWA.md` --- PWA/offline behavior
-   `TESTING.md` --- tests and quality gates

When documents overlap, the topic-specific document owns the detailed
rule.
