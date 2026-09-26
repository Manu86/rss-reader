# RSS Reader --- Project Specification

## Purpose

RSS Reader is a self-hosted, multi-user web application for subscribing
to, retrieving, reading, searching and organizing RSS and Atom feeds.

Priorities: simplicity, privacy, user isolation, security,
accessibility, maintainability, performance and limited external
dependencies.

Accessibility is a technical requirement across the interface: use semantic
landmarks and headings, make controls keyboard-operable with visible focus,
associate labels and validation errors with their fields, and manage focus
when navigation or dialogs change. Announce relevant dynamic status and error
messages to assistive technology; never communicate essential state by color
alone. See `FRONTEND.md` for interaction-specific behavior and `TESTING.md`
for validation expectations. These requirements do not authorize unrelated
changes to the interface's visual presentation.

## Stack

-   PHP, object-oriented, Composer
-   SQLite through PDO
-   JSON API
-   HTML, CSS and vanilla JavaScript with ES modules
-   PWA
-   Server-side cron for feed synchronization
-   No full PHP framework
-   No frontend framework

Use a recent, maintained PHP version. Exact runtime and dependency
versions belong in `composer.json`.

## Users

There is no public registration.

-   The first account is created during installation.
-   Additional accounts are managed through CLI.
-   CLI supports create, list, password reset/change, enable and
    disable.
-   An authenticated user can change their own password.
-   Email password recovery is outside V1.

Each user has an independent RSS environment. Subscriptions, categories,
articles, read states, favorites, settings and local media are never
shared between users, even when two users subscribe to the same feed.

Backend authorization must enforce this isolation.

## Subscriptions

Users can subscribe using:

-   a direct RSS/Atom URL;
-   a website URL with feed discovery.

If multiple feeds are discovered, the user chooses one.

A subscription has at least:

-   name;
-   feed URL;
-   site URL;
-   optional category;
-   favicon;
-   active/disabled state;
-   last retrieval information;
-   latest article date.

Users can create, edit, enable, disable, delete and manually refresh
subscriptions.

Deleting a subscription deletes its articles and associated unused local
files.

## Categories

Categories belong to one user.

A subscription belongs to zero or one category. `Sans catégorie` is a
virtual group for subscriptions without a category.

Deleting a category does not delete subscriptions; they become
uncategorized.

## Articles

Articles are stored locally and contain, when available:

-   title;
-   original URL;
-   GUID/identifier;
-   source feed;
-   author;
-   publication date;
-   discovery date;
-   summary;
-   content;
-   local image;
-   read state;
-   favorite state.

Default order is newest first.

Opening an article marks it read. Users can change read state and
favorite state.

Views/filters:

-   all;
-   unread;
-   read;
-   favorites;
-   category;
-   feed.

Full-text search covers title, summary, content and author.

Feed-provided article content is displayed inside the application after
sanitization. The original article remains accessible through a link.

## Synchronization

-   Automatic synchronization runs every hour through cron.
-   Disabled feeds are not automatically fetched.
-   Users can refresh one feed or all their active feeds manually.
-   Duplicate articles must not be created.
-   Publication date and first discovery date are distinct.
-   Primary article images and favicons are downloaded locally.
-   Images embedded inside feed-provided article HTML use sanitized absolute
    HTTP(S) source URLs, lazy loading and a no-referrer policy.

Detailed rules belong in `RSS.md`.

## Retention

Normal articles older than one year are automatically removable.

Favorites are exempt from age-based cleanup.

The retention reference date and malformed-date behavior are defined in
`DATABASE.md` and `RSS.md`.

## OPML

Users can import and export subscriptions through OPML.

Export includes categories. Import creates missing categories and avoids
duplicate feed URLs.

## Settings

V1 settings are limited to:

-   password change;
-   articles per page;
-   interface theme (light/dark);
-   OPML import/export.

## PWA

V1 is installable and provides an offline application shell.

It does not provide complete offline article storage, browser background
RSS synchronization or push notifications.

See `PWA.md`.

## Security

Security requirements are centralized in `SECURITY.md`.

Key principles:

-   server-side sessions;
-   server-side authorization;
-   prepared SQL;
-   CSRF protection;
-   HTML sanitization;
-   SSRF protection for every remote URL;
-   safe XML parsing;
-   validated remote media;
-   HTTPS in production.

## Quality

Required project commands:

``` bash
composer test
composer analyse
composer lint
composer check
```

A feature is complete only when relevant tests exist and
`composer check` passes.

## V1 exclusions

V1 excludes:

-   public registration;
-   email password recovery;
-   OAuth/social login;
-   sharing between users;
-   comments/social features;
-   AI summaries/classification;
-   recommendations;
-   push notifications;
-   complete offline article synchronization;
-   tags;
-   multiple categories per feed;
-   third-party synchronization;
-   native mobile applications;
-   microservices;
-   external database/search infrastructure.

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
