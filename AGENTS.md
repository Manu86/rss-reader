# AGENTS.md

## Purpose

This file defines the mandatory working rules for AI coding agents on the RSS
Reader project. It applies to the entire repository.

RSS Reader is a self-hosted, multi-user RSS/Atom reader built with PHP,
SQLite, HTML, CSS and vanilla JavaScript, exposing a JSON API and shipped as
a PWA. For the full scope and stack, read `docs/PROJECT.md`.

## Mandatory reading order

1. This `AGENTS.md`.
2. `docs/PROJECT.md` (scope, principles, V1 exclusions, documentation map).
3. Only the topic documents relevant to the task, using this routing:

| Task touches                                            | Authoritative document    |
| ------------------------------------------------------- | ------------------------- |
| Product scope, priorities, exclusions                   | `docs/PROJECT.md`         |
| Functional behavior (accounts, feeds, articles, OPML…)  | `docs/FEATURES.md`        |
| Schema, persistence, migrations                          | `docs/DATABASE.md`        |
| HTTP contract, endpoints, validation, errors             | `docs/API.md`             |
| Code organization, CLI, cron, architecture               | `docs/ARCHITECTURE.md`    |
| RSS/Atom parsing, discovery, synchronization             | `docs/RSS.md`             |
| Authentication, sessions, isolation, remote content      | `docs/SECURITY.md`        |
| UI, JavaScript, responsive, accessibility                | `docs/FRONTEND.md`        |
| PWA, service worker, offline shell                       | `docs/PWA.md`             |
| Tests, quality gates, tooling                            | `docs/TESTING.md`         |

Do not load unrelated documents unless the task crosses those boundaries.
When documents overlap, the topic-specific document is authoritative for its
subject. Before implementing or modifying a feature, also inspect the
existing implementation and existing tests.

## Do not invent requirements

The documentation is the source of truth. Do not silently invent features,
business rules, database behavior, API behavior, dependencies, architectural
patterns or user permissions.

If requirements are ambiguous, contradictory or missing, report the ambiguity
or ask, instead of making a major product decision. Small implementation
details may be chosen when they do not alter documented behavior. Document
significant technical decisions. User-requested changes that alter documented
scope also require updating the affected documentation in the same task.

## Non-negotiable security invariants

The topic documents detail these rules; they are repeated here because they
are always in scope.

-   The application is multi-user. Every user owns their feeds, categories,
    articles, read states, favorites, settings and OPML data. A user must
    never be able to read, modify or delete another user's data: server-side
    authorization and ownership-scoped queries are mandatory, frontend
    filtering is never a security boundary. Treat any isolation failure as a
    critical security bug.
-   Never trust remote content: RSS/Atom data, discovery URLs, favicons,
    images and article HTML are untrusted. Enforce SSRF protections on the
    initial URL and on redirects/discovered URLs, allow only HTTP(S) public
    destinations, validate remote media types/sizes and never disable TLS
    verification. External HTML must be sanitized before storage/display.
-   Always use prepared statements; never interpolate untrusted values into
    SQL; enable SQLite foreign keys; schema changes only through versioned
    migrations.
-   Never store, log or return plaintext passwords or secrets; never expose
    stack traces, filesystem paths, SQL or credentials through the API or
    logs.
-   Escape application output; never inject feed HTML outside the
    sanitization pipeline.

## Workflow

For each task, before coding: understand the requested behavior, locate the
relevant documentation, inspect existing code and tests, identify security
and database/API implications, then implement the smallest coherent change.
Do not rewrite unrelated parts of the application.

Dependencies follow `docs/TESTING.md`: prefer existing project dependencies;
add a Composer dependency only when necessary, maintained and PHP-compatible.
The stack stays PHP + SQLite + HTML/CSS/vanilla JS; no framework or heavy
infrastructure unless documentation explicitly changes this decision.

## Quality gates

Before reporting a task complete, when applicable run:

``` bash
composer test
composer analyse
composer lint
composer check
```

`composer check` runs the project's required validation suite. Do not claim
a test or quality check passed unless it was executed successfully, and
report any check that could not be executed. If a check fails, fix it and
re-run; failing checks block task completion.

## Definition of Done

A task is complete only when documented requirements are satisfied,
architecture stays coherent, security and user isolation are respected,
database changes use migrations, API behavior is consistent, relevant tests
exist and pass, static analysis and linting pass, and documentation updated
when behavior or architecture changed. When possible, verify with
`composer check`.

Bug fixes should include a regression test whenever practical.

## Priorities

When requirements compete, use this priority order:

1. security and user data isolation;
2. data integrity;
3. documented functional requirements;
4. correctness;
5. maintainability;
6. accessibility;
7. performance;
8. developer convenience.

Never sacrifice security or data isolation to make an implementation easier.

## Accessibility

Interactive elements must be usable with a keyboard, forms must be labeled,
state must not depend on color alone and visible focus must stay visible.
Detailed interaction rules are in `docs/FRONTEND.md`.

## Keep the project simple

The project intentionally uses PHP, SQLite, vanilla JavaScript, HTML and CSS.
Do not introduce microservices, message brokers, frontend frameworks, ORMs,
containers, caching infrastructure or complex design patterns. Add
complexity only when a concrete documented requirement justifies it.
