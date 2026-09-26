# AGENTS.md

## Purpose

This file defines the mandatory rules for AI coding agents working on the RSS Reader project.

These instructions apply to the entire repository unless a more specific `AGENTS.md` file explicitly overrides them for a subdirectory.

---

# 1. Project goal

Build a lightweight, secure and maintainable multi-user RSS/Atom reader.

Main technologies:

* PHP;
* SQLite;
* HTML;
* CSS;
* vanilla JavaScript;
* Composer.

The backend exposes a JSON API.

The frontend consumes this API using vanilla JavaScript.

The application is also a PWA.

Do not introduce a PHP or JavaScript framework unless explicitly requested.

---

# 2. Read the specifications first

Before implementing or modifying a feature:

1. read this `AGENTS.md`;
2. read `README.md`;
3. read `docs/PROJECT.md` when available;
4. read `docs/FEATURES.md` when available;
5. read the technical document related to the task;
6. inspect the existing implementation;
7. inspect existing tests.

Do not start implementation before understanding the existing architecture and specifications.

---

# 3. Do not invent requirements

The project documentation is the source of truth.

Do not silently invent:

* features;
* business rules;
* database behavior;
* API behavior;
* dependencies;
* architectural patterns;
* user permissions.

If requirements are ambiguous, contradictory or missing, report the ambiguity instead of making a major product decision.

Small implementation details may be chosen when they do not alter documented behavior.

Document significant technical decisions.

---

# 4. User isolation is mandatory

The application is multi-user.

Every user owns their own:

* feeds;
* categories;
* articles;
* read states;
* favorites;
* settings;
* OPML data.

A user must never be able to read, modify, update or delete another user's data.

This rule must be enforced on the server.

Never rely on frontend filtering for authorization.

Every relevant repository query and API operation must take user ownership into account.

Tests must explicitly verify cross-user isolation.

Treat failure of user isolation as a critical security bug.

---

# 5. Backend architecture

Use modern object-oriented PHP.

Keep responsibilities separated.

Prefer clear concepts such as:

* controllers;
* services;
* repositories;
* domain/model objects when useful;
* validators;
* infrastructure adapters.

Controllers must remain thin.

Controllers should primarily:

1. validate the request context;
2. call application/services;
3. return the appropriate JSON response.

Business logic must not accumulate inside controllers.

SQL must not be written inside controllers.

RSS parsing must not be implemented inside controllers.

Remote HTTP retrieval must not be implemented inside controllers.

---

# 6. API

The backend exposes a JSON API consumed by the frontend.

API responses must use appropriate:

* HTTP methods;
* HTTP status codes;
* JSON structures;
* validation errors.

Do not return HTML from API endpoints.

Do not expose internal exceptions, stack traces, SQL queries or sensitive implementation details through API responses.

Keep API behavior consistent.

When an API contract has been documented, do not change it silently.

---

# 7. Frontend

Use:

* semantic HTML;
* modern CSS;
* vanilla JavaScript.

Do not introduce React, Vue, Angular, Svelte, jQuery or another frontend framework/library unless explicitly approved.

Keep JavaScript modular.

Separate:

* API access;
* state handling;
* UI rendering;
* user interactions;
* utilities.

Avoid large monolithic JavaScript files.

The frontend must never be considered a security boundary.

All authorization and validation affecting security or data integrity must also occur on the server.

---

# 8. Database

Use SQLite through PDO.

Always use prepared statements.

Never concatenate untrusted values into SQL.

Enable SQLite foreign key enforcement.

Use:

* primary keys;
* foreign keys;
* unique constraints;
* indexes;
* appropriate cascading behavior.

Database changes must use versioned migrations.

Do not manually alter the production database schema outside the migration system.

Use transactions for operations requiring atomicity.

---

# 9. Full-text search

Use SQLite FTS5 for article full-text search when supported by the target environment.

Searchable article data includes:

* title;
* summary;
* content;
* author.

The FTS index must remain synchronized with article creation, modification and deletion.

---

# 10. RSS and Atom

Support RSS and Atom feeds according to the documented project requirements.

Feed processing must tolerate reasonable real-world variations and malformed optional data.

Never assume every feed provides:

* GUID;
* author;
* image;
* summary;
* content;
* valid publication date.

Prefer the feed GUID/ID for duplicate detection when reliable.

Implement and document a fallback duplicate-detection strategy.

Store separately:

* publication date;
* discovery/retrieval date.

Do not let one malformed item prevent all valid items from being imported when safe recovery is possible.

---

# 11. Feed discovery

When given a website URL, inspect the page for declared RSS/Atom feeds.

When exactly one valid feed is found, it may be proposed directly.

When multiple feeds are found, return the choices to the user.

Do not arbitrarily subscribe the user to one of several discovered feeds.

---

# 12. Remote resources and SSRF

Treat every remote URL as untrusted.

Feed discovery, RSS retrieval, favicons and article images can create SSRF vulnerabilities.

The implementation must prevent access to inappropriate destinations, including private/internal network resources where applicable.

Validate:

* URL scheme;
* hostname;
* resolved addresses;
* redirects;
* response type;
* response size;
* timeouts.

Only allow appropriate HTTP/HTTPS resources.

A redirect must not bypass SSRF protections.

Security checks must be applied to the final destination as well as the initial URL.

Do not disable TLS certificate verification.

---

# 13. Images

Primary article images and favicons are stored locally. Images embedded in
article content may retain sanitized absolute HTTP/HTTPS source URLs when this
behavior is documented; they must use lazy loading and a no-referrer policy.

Never trust:

* filename;
* extension;
* MIME type supplied only through HTTP headers.

Validate downloaded content.

Set reasonable:

* maximum download size;
* timeout;
* accepted image formats.

Generate safe local filenames.

Remote paths must never determine arbitrary filesystem paths.

Files no longer referenced by application data must eventually be cleaned up.

---

# 14. External HTML

RSS/Atom content is untrusted.

Never inject arbitrary feed HTML directly into the application.

Sanitize or transform external HTML using an appropriate, well-maintained solution.

Prevent:

* scripts;
* event handlers;
* dangerous URLs;
* unsafe embeds;
* other executable content.

Escaping and sanitization must happen at appropriate trust boundaries.

---

# 15. Authentication

There is no public registration.

The first user is created during application installation.

Additional users are managed through the command line.

Provide CLI capabilities for at least:

* creating a user;
* listing users;
* resetting/changing a password;
* enabling/disabling a user.

A logged-in user can change their own password.

Use PHP's modern password APIs.

Never:

* store plaintext passwords;
* log passwords;
* return password hashes through the API.

Use secure authentication/session practices appropriate to a same-origin web application.

Prefer secure server-side sessions unless project documentation explicitly changes this decision.

---

# 16. CSRF, XSS and session security

Apply appropriate protections for the selected authentication mechanism.

Session cookies should use appropriate security attributes.

Regenerate session identifiers at appropriate authentication boundaries.

State-changing requests must be protected against cross-site request attacks where applicable.

Escape application-generated output correctly.

External feed HTML requires sanitization in addition to normal output handling.

---

# 17. OPML

OPML import/export is scoped to the authenticated user.

Import must:

* validate input;
* create required categories;
* avoid duplicate subscriptions;
* reject or safely handle malformed input.

Export must include only the authenticated user's subscriptions and categories.

Never expose another user's data through OPML.

---

# 18. Article retention

Normal articles are retained for one year.

Favorite articles are excluded from automatic age-based deletion.

When an article is deleted, local files that are no longer referenced should also be removed safely.

When a feed subscription is deleted, its articles and associated unreferenced files are deleted.

---

# 19. Categories

Categories belong to a user.

A feed can belong to at most one category.

A category is optional.

Feeds without a category are presented as belonging to the virtual "Uncategorized" / "Sans catégorie" group.

Deleting a category must not delete its feeds.

Its feeds become uncategorized.

---

# 20. Feed updates

Automatic feed updates are executed by cron once per day.

Disabled feeds must not be fetched automatically.

Users can manually refresh:

* all of their feeds;
* one individual feed.

Manual refresh endpoints must enforce ownership and appropriate security controls.

Failures must be recorded sufficiently for diagnosis without exposing sensitive data.

---

# 21. PWA

The V1 PWA must provide:

* web app manifest;
* icons;
* service worker;
* installability;
* responsive interface;
* offline availability of essential application shell resources.

Do not implement browser-based background RSS synchronization unless requirements explicitly change.

Feed synchronization remains a server responsibility.

Do not cache sensitive API responses indiscriminately in the service worker.

---

# 22. Dependencies

Use Composer for PHP dependencies.

Prefer:

1. PHP standard functionality;
2. existing project dependencies;
3. mature, actively maintained libraries.

Do not add a dependency for trivial functionality.

Before adding a dependency, verify that it is:

* necessary;
* maintained;
* compatible with the supported PHP version;
* appropriate from a security perspective.

Avoid abandoned packages.

Commit dependency configuration files according to normal Composer practices.

---

# 23. Code quality

Code must be:

* readable;
* typed where appropriate;
* cohesive;
* testable;
* documented where necessary;
* free of unnecessary abstraction.

Prefer explicit code over clever code.

Do not over-engineer the application.

Follow consistent PHP coding standards.

Use strict typing for new PHP files where appropriate:

```php
declare(strict_types=1);
```

Use meaningful names.

Avoid hidden side effects and unnecessary global state.

---

# 24. Quality tools

The project must use:

* PHPUnit;
* PHPStan;
* PHP linting;
* appropriate JavaScript/CSS quality checks.

Composer scripts should expose at least:

```bash
composer test
composer analyse
composer lint
composer check
```

`composer check` should run the project's required validation suite.

Do not declare a task complete while required checks are failing.

---

# 25. Testing

Add or update tests when behavior changes.

Critical areas require tests, especially:

* authentication;
* authorization;
* user isolation;
* repositories;
* API endpoints;
* RSS parsing;
* Atom parsing;
* duplicate detection;
* publication dates;
* feed discovery;
* OPML;
* FTS search;
* URL validation;
* SSRF protections;
* image handling;
* article retention.

Bug fixes should include a regression test whenever practical.

Tests must not depend unnecessarily on external live websites.

Use fixtures or controlled HTTP test doubles for RSS/Atom behavior.

---

# 26. Error handling

Expected application errors must be handled deliberately.

API errors should be predictable and useful without leaking sensitive details.

Unexpected exceptions should be logged server-side.

Never expose:

* stack traces;
* filesystem paths;
* SQL queries;
* credentials;
* environment secrets.

One invalid RSS item should not necessarily invalidate an otherwise usable feed.

---

# 27. Logging

Log useful operational information such as:

* feed update failures;
* unexpected exceptions;
* cron failures;
* important security events.

Do not log:

* passwords;
* session identifiers;
* authorization secrets;
* full sensitive request bodies.

Logs must not become an alternative datastore for user content.

---

# 28. Configuration and secrets

Configuration that differs between environments must not be hardcoded.

Secrets must not be committed to Git.

Provide safe example configuration when needed.

The SQLite database, downloaded media, caches and runtime logs must be stored in appropriate writable locations and excluded from version control when necessary.

---

# 29. CLI and cron

CLI commands and cron entry points must reuse application services.

Do not duplicate RSS retrieval or user-management business logic in standalone scripts.

CLI scripts should bootstrap the same application infrastructure used elsewhere.

Commands must return meaningful exit codes.

Cron execution must be safe to run unattended.

Prevent problematic concurrent synchronization runs when necessary.

---

# 30. Performance

Avoid obvious N+1 query patterns.

Use indexes for common filters and relationships.

Paginate article lists.

Do not load every article into memory.

Remote resources must use reasonable timeout and size limits.

SQLite is the selected database: design for it rather than pretending the application uses a client/server database.

---

# 31. Accessibility and responsive UI

Use semantic HTML.

Interactive elements must be usable with a keyboard.

Forms require proper labels.

Do not rely exclusively on color to communicate state.

Maintain visible focus states.

The interface must work on desktop and mobile screen sizes.

Accessibility must not be knowingly degraded for visual convenience.

---

# 32. Before coding

For each task:

1. understand the requested behavior;
2. locate the relevant documentation;
3. inspect existing code;
4. inspect existing tests;
5. identify security implications;
6. identify database/API implications;
7. implement the smallest coherent change.

Do not rewrite unrelated parts of the application.

---

# 33. Before completing a task

Before reporting completion:

1. run relevant tests;
2. run static analysis;
3. run linting;
4. verify database migrations when applicable;
5. verify authorization/user isolation;
6. review security implications;
7. update documentation when behavior or architecture changed.

When possible, run:

```bash
composer check
```

Report any check that could not be executed.

Do not claim that a test or quality check passed unless it was actually executed successfully.

---

# 34. Definition of Done

A feature is complete only when:

* documented requirements are satisfied;
* architecture remains coherent;
* security requirements are respected;
* user isolation is enforced;
* database changes have migrations;
* API behavior is consistent;
* relevant tests exist and pass;
* static analysis passes;
* linting passes;
* documentation is updated when necessary.

---

# 35. Priorities

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

---

# 36. Keep the project simple

This project intentionally uses:

* PHP;
* SQLite;
* vanilla JavaScript;
* HTML;
* CSS.

Respect that simplicity.

Do not turn the project into a distributed system.

Do not introduce unnecessary:

* microservices;
* message brokers;
* frontend frameworks;
* ORMs;
* containers;
* caching infrastructure;
* complex design patterns.

Add complexity only when a concrete documented requirement justifies it.
