# PROJECT.md

# RSS Reader — Project Specification

## 1. Purpose

RSS Reader is a self-hosted, multi-user web application for subscribing to, retrieving, reading, searching and organizing RSS and Atom feeds.

The application must provide a simple and efficient alternative to hosted RSS reader services.

The project prioritizes:

* simplicity;
* privacy;
* user data isolation;
* maintainability;
* security;
* performance;
* accessibility;
* long-term sustainability;
* limited external dependencies.

The application is intended to run on a traditional PHP web hosting environment or server without requiring complex infrastructure.

---

# 2. Technology stack

The application uses:

## Backend

* PHP;
* object-oriented architecture;
* Composer;
* SQLite;
* PDO;
* JSON API.

## Frontend

* HTML;
* CSS;
* vanilla JavaScript.

## Application

* Progressive Web App (PWA);
* server-side cron for scheduled feed synchronization.

No full-stack PHP framework is planned.

No frontend JavaScript framework is planned.

The architecture must remain compatible with the simplicity of this stack.

---

# 3. Application model

RSS Reader is a multi-user application.

There is no public user registration.

The first user account is created during application installation.

Additional accounts are managed through command-line tools.

Each account represents an independent RSS environment.

---

# 4. User data isolation

User isolation is a fundamental requirement of the project.

Each user owns their own:

* subscriptions;
* categories;
* articles;
* read/unread states;
* favorites;
* settings;
* OPML imports and exports;
* locally stored article data.

Data must not be shared between users.

Even when two users subscribe to the same RSS feed, each user's subscription and imported articles belong to that user independently.

A user must never be able to access, modify or delete another user's data.

User isolation must be enforced by the backend and database access layer, not by the frontend.

---

# 5. Authentication

The application requires authentication.

There is no public account registration.

The first account is created during installation.

Additional accounts are created through the application's command-line interface.

The CLI must support at least:

* creating a user;
* listing users;
* changing or resetting a user's password;
* enabling a user;
* disabling a user.

Authenticated users can change their own password through the web application.

A password recovery system using email is not required for V1.

---

# 6. Feed subscriptions

Users can subscribe to RSS and Atom feeds.

A subscription can be created from:

* a direct RSS/Atom feed URL;
* a website URL.

When a website URL is provided, the application attempts to discover RSS/Atom feeds declared by the website.

If multiple feeds are discovered, the user chooses which feed to subscribe to.

A subscription can be:

* created;
* viewed;
* modified;
* enabled;
* disabled;
* deleted;
* manually refreshed.

Deleting a subscription also deletes all articles belonging to that subscription and associated local resources that are no longer required.

Disabled subscriptions remain stored but are excluded from automatic feed synchronization.

---

# 7. Subscription information

Each subscription contains at least:

* name;
* feed URL;
* website URL;
* category;
* favicon;
* enabled/disabled state;
* last retrieval date;
* latest article date;
* last retrieval status.

Additional technical metadata may be stored when required for reliable feed synchronization.

---

# 8. Categories

Categories are used to organize subscriptions.

Categories belong to individual users.

A subscription can belong to zero or one category.

A subscription cannot belong to multiple categories.

Subscriptions without a category are presented in the application under a virtual "Sans catégorie" / "Uncategorized" section.

Deleting a category must never delete the subscriptions contained within it.

When a category is deleted, its subscriptions become uncategorized.

---

# 9. Articles

Articles retrieved from feeds are stored locally.

Articles belong to:

* one user;
* one subscription.

Each article contains at least:

* title;
* original URL;
* RSS/Atom GUID or equivalent identifier when available;
* source subscription;
* author when available;
* publication date;
* discovery/retrieval date;
* summary;
* content;
* local image/thumbnail when available;
* read/unread state;
* favorite state.

The exact database representation is defined separately in `DATABASE.md`.

---

# 10. Article ordering

The default article list is ordered from newest to oldest.

The publication date should be used when reliable.

The application must also retain the date on which an article was discovered.

Invalid or missing publication dates must not prevent an article from being imported.

Detailed date handling rules are defined in `RSS.md`.

---

# 11. Reading articles

Article content supplied by the RSS/Atom feed can be read directly inside the application.

Opening an article automatically marks it as read.

The application provides a way to open the original article on the source website.

A user can also change an article's read/unread state when appropriate.

---

# 12. Read states

Every article has a read state belonging to its user.

Supported states are:

* unread;
* read.

Opening an article marks it as read.

The application provides dedicated views for:

* all articles;
* unread articles;
* read articles.

---

# 13. Favorites

An article can be marked as a favorite.

Favorites belong to the user.

The user can:

* add an article to favorites;
* remove an article from favorites;
* display favorite articles.

Favorite articles are protected from automatic age-based deletion.

---

# 14. Article filters

The application must provide at least the following article filters:

* all;
* unread;
* read;
* favorites;
* category.

Selecting a subscription must also allow the user to display only articles from that subscription.

Filters may be combined when doing so remains understandable and consistent with the user interface specification.

---

# 15. Full-text search

The application provides local full-text article search.

Search covers:

* title;
* summary;
* content;
* author.

SQLite FTS5 is the preferred search mechanism.

Search results must only contain articles belonging to the authenticated user.

Detailed implementation is defined in `DATABASE.md` and `FEATURES.md`.

---

# 16. Images and favicons

When available, article images or thumbnails are downloaded and stored locally.

Feed/site favicons are also stored locally when available.

The application must not depend permanently on remote image URLs for normal display of already imported resources.

Remote media must be treated as untrusted content.

Security, validation, storage and cleanup rules are defined in `SECURITY.md` and `RSS.md`.

---

# 17. Feed synchronization

RSS/Atom feeds are synchronized automatically by the server.

Automatic synchronization is triggered by cron every hour.

The cron process retrieves active subscriptions and imports newly available articles.

Disabled subscriptions are ignored.

The application also provides manual synchronization.

An authenticated user can request:

* synchronization of all their active subscriptions;
* synchronization of one specific subscription.

Manual synchronization must not bypass normal security, validation or duplicate-detection rules.

---

# 18. Retrieval errors

Feed retrieval can fail.

The application must detect and record retrieval errors.

A failure must not corrupt existing subscription or article data.

The application must retain sufficient information to communicate the state of the subscription and support diagnosis.

The exact error model is defined in `RSS.md`.

---

# 19. Duplicate detection

The same article must not be imported repeatedly for the same user subscription.

RSS/Atom GUID or ID should be used when it provides a reliable identifier.

A fallback strategy must exist for feeds that:

* provide no GUID;
* provide unstable identifiers;
* contain malformed identifiers.

The detailed duplicate-detection algorithm is defined in `RSS.md`.

---

# 20. Article retention

Normal articles are retained for one year.

Articles older than one year can be removed automatically.

Favorite articles must not be deleted by age-based cleanup.

When an article is removed, locally stored resources that are no longer referenced must also be eligible for cleanup.

Article cleanup must never delete another user's data.

---

# 21. OPML

The application supports OPML import and export.

OPML operations always apply to the authenticated user.

## Import

Import should:

* read subscriptions from an OPML file;
* recreate relevant categories;
* associate subscriptions with their categories;
* detect subscriptions already present;
* avoid creating duplicate subscriptions.

## Export

Export contains:

* the user's subscriptions;
* their category organization.

An OPML export must never contain another user's subscriptions.

---

# 22. User settings

The V1 user settings are intentionally limited.

Users can manage:

* their password;
* the number of articles displayed per page;
* OPML import;
* OPML export.

Additional settings should not be introduced without a concrete requirement.

---

# 23. Progressive Web App

RSS Reader is a Progressive Web App.

V1 must provide:

* a web app manifest;
* application icons;
* a service worker;
* installability on compatible devices;
* responsive user interface;
* offline availability of essential application interface resources.

The browser is not responsible for scheduled RSS synchronization.

RSS synchronization remains a server-side responsibility.

Offline synchronization of the complete article database is outside the V1 scope.

---

# 24. Backend API

The PHP backend exposes a JSON API.

The JavaScript frontend consumes this API.

The API handles application operations including:

* authentication;
* articles;
* favorites;
* read states;
* categories;
* subscriptions;
* feed discovery;
* synchronization;
* search;
* OPML;
* settings.

The complete API contract is defined in `API.md`.

---

# 25. Frontend

The frontend is built using:

* HTML;
* CSS;
* vanilla JavaScript.

The frontend must be responsive.

It must support desktop and mobile usage.

The frontend is responsible for presentation and interaction but is not a security boundary.

The backend must independently validate every operation affecting data or permissions.

Detailed frontend behavior is defined in `FRONTEND.md`.

---

# 26. Security

Security is a core project requirement.

The application must be designed to address at least:

* authentication security;
* authorization;
* user isolation;
* SQL injection;
* cross-site scripting;
* cross-site request forgery where applicable;
* server-side request forgery;
* session security;
* malicious RSS/Atom content;
* malicious OPML files;
* malicious remote images;
* unsafe URLs;
* unsafe HTTP redirects;
* local file access;
* sensitive error disclosure.

RSS feeds, websites, OPML files and remote media must always be considered untrusted input.

Detailed security requirements are defined in `SECURITY.md`.

---

# 27. Code quality

The project requires a high level of code quality.

The PHP application uses object-oriented programming.

Composer is used for dependencies, autoloading and development tools.

The project must include:

* PHPUnit;
* PHPStan;
* PHP linting;
* appropriate JavaScript quality checks;
* appropriate CSS quality checks.

Development commands should include at least:

```bash
composer test
composer analyse
composer lint
composer check
```

A feature should not be considered complete while required quality checks fail.

Detailed testing requirements are defined in `TESTING.md`.

---

# 28. Dependencies

Dependencies are allowed when they provide meaningful value.

Dependencies should be:

* actively maintained;
* compatible with the project's PHP version;
* appropriate from a security perspective;
* reasonably lightweight;
* documented.

A dependency should not be added for functionality that can be implemented clearly and safely using the platform itself.

No major application framework should be introduced without an explicit project decision.

---

# 29. Deployment philosophy

The application should remain deployable on a conventional PHP server.

The project should not require infrastructure such as:

* Node.js application servers;
* Redis;
* message brokers;
* Elasticsearch;
* external database servers;
* container orchestration;
* microservices.

Development tools may have their own requirements, but production should remain simple.

SQLite is the production database.

Cron is the production scheduling mechanism.

---

# 30. Installation

The project must provide an installation process capable of:

1. checking server requirements;
2. creating or initializing the SQLite database;
3. running database migrations;
4. creating required writable directories;
5. creating the first user account;
6. preparing application configuration;
7. explaining how to configure the synchronization cron.

The installation process must not require public registration to create the first account.

---

# 31. Data ownership

All user-created or user-imported application data belongs to the user account that created it.

The application must maintain this ownership relationship throughout:

* API requests;
* database queries;
* background synchronization;
* cron tasks;
* CLI operations;
* search;
* OPML;
* cleanup jobs.

A background process must preserve the same isolation rules as an interactive API request.

---

# 32. V1 scope

The V1 includes:

* authentication;
* multi-user isolation;
* CLI user management;
* categories;
* RSS subscriptions;
* Atom subscriptions;
* feed discovery from websites;
* article retrieval;
* local article storage;
* local image storage;
* article reading;
* read/unread state;
* favorites;
* filters;
* full-text search;
* automatic synchronization;
* manual synchronization;
* duplicate detection;
* retrieval error handling;
* OPML import/export;
* article retention;
* user settings;
* PWA installation;
* responsive interface.

---

# 33. Explicitly out of scope for V1

The following features are not part of V1 unless the project requirements are explicitly changed:

* public user registration;
* email-based password recovery;
* social login;
* sharing articles between users;
* shared subscriptions between users;
* shared articles between users;
* comments;
* social features;
* article recommendations;
* recommendation algorithms;
* AI-generated summaries;
* AI classification;
* push notifications;
* browser background RSS synchronization;
* complete offline article synchronization;
* multiple categories per subscription;
* article tagging;
* third-party RSS synchronization services;
* native mobile applications;
* PHP frameworks;
* frontend JavaScript frameworks;
* external search engines;
* external database servers;
* microservices.

An AI coding agent must not implement these features unless explicitly instructed to change the project scope.

---

# 34. Design principles

Development decisions should favor the following principles.

## Simplicity

Prefer the simplest implementation that correctly satisfies the documented requirements.

## Security

Do not trade security for implementation convenience.

## Isolation

User data isolation is mandatory.

## Maintainability

Code should remain understandable and modifiable without requiring extensive framework knowledge.

## Reliability

Malformed or unavailable external feeds should not destabilize the application.

## Progressive enhancement

Core application behavior should remain understandable and robust even when optional browser capabilities are unavailable.

## Accessibility

The application must use semantic HTML and support keyboard operation, visible
focus, accessible names for controls, and properly associated form labels and
errors. Focus must remain understandable across navigation, dialogs and
dynamic updates. State and errors must not be conveyed by color alone. These
requirements must be met without relying on visual changes that are not
otherwise requested.

## Performance

Avoid unnecessary queries, network requests and client-side processing.

Design specifically for SQLite rather than reproducing architecture intended for large client/server databases.

---

# 35. Sources of truth

Project documentation has distinct responsibilities:

* `README.md` — project overview and installation entry point;
* `AGENTS.md` — mandatory instructions for coding agents;
* `docs/PROJECT.md` — product scope and high-level project rules;
* `docs/FEATURES.md` — detailed functional behavior;
* `docs/DATABASE.md` — database schema and persistence rules;
* `docs/API.md` — JSON API contract;
* `docs/ARCHITECTURE.md` — technical architecture;
* `docs/RSS.md` — RSS/Atom processing rules;
* `docs/SECURITY.md` — security requirements;
* `docs/FRONTEND.md` — frontend and interaction specification;
* `docs/PWA.md` — PWA behavior;
* `docs/TESTING.md` — quality and testing strategy.

When documents appear to conflict, implementation must not silently choose one interpretation.

The conflict must be identified and resolved before implementing the affected behavior.

---

# 36. Development rule

Before implementing a feature, the coding agent must:

1. read `AGENTS.md`;
2. identify the relevant project documentation;
3. understand the existing implementation;
4. inspect relevant tests;
5. verify user isolation implications;
6. verify security implications;
7. implement only the documented behavior;
8. add or update tests;
9. run the project's quality checks.

The application must evolve from documented decisions rather than undocumented assumptions.
