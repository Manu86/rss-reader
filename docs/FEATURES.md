# RSS Reader --- Functional Specifications

## Purpose

This document defines V1 behavior from the user's point of view.
Technical implementation belongs in the specialized documents.

## Accounts

-   No public registration.
-   First account created during installation.
-   Additional accounts managed by CLI.
-   Disabled users cannot log in but retain their data.
-   Login errors must not reveal whether an account exists.
-   Users can log out and change their own password.

## User independence

Every user owns independent categories, feeds, articles, read states,
favorites and settings.

Two users subscribing to the same URL still have separate subscriptions
and article records.

## Settings

V1 provides:

-   password change;
-   articles-per-page selection;
-   interface theme selection (light or dark);
-   OPML import;
-   OPML export.

Suggested page sizes: 10, 25, 50, 100. Default: 25.

## Categories

Users can create, rename and delete categories.

Rules:

-   category names are validated;
-   a category belongs to one user;
-   a feed belongs to zero or one category;
-   `Sans catégorie` represents `category_id = null`;
-   deleting a category keeps its feeds and makes them uncategorized.

## Adding a subscription

A user enters either a direct feed URL or a website URL.

### Direct feed

If the URL is a valid RSS/Atom feed, create the subscription and perform
the initial article import.

### Website discovery

The application searches declared RSS/Atom feeds.

-   none: display an error;
-   one: present/continue with that feed;
-   several: let the user choose.

The backend must not arbitrarily choose among multiple feeds.

Duplicate feed URLs for the same user are rejected.

## Managing subscriptions

Users can:

-   list subscriptions;
-   view feed information;
-   rename;
-   change category;
-   enable/disable;
-   delete;
-   refresh manually.

Displayed information includes name, URLs, category, favicon, status,
last retrieval and latest article date.

Deleting a feed deletes its articles and associated unused local media.

## Synchronization

Automatic synchronization runs hourly through cron.

Manual refresh is available:

-   for one feed;
-   for all active feeds of the current user.

A failure on one feed must not corrupt existing data.

Disabled feeds remain visible and keep their articles but are not
synchronized.

## Articles

Imported articles are stored locally.

Each article contains the normalized fields defined in
`PROJECT.md`/`DATABASE.md`.

Rules:

-   newest first;
-   listing an article does not mark it read;
-   opening it marks it read;
-   read/unread can be changed;
-   favorite can be toggled;
-   favorites survive normal age cleanup;
-   disappearing from the remote feed does not delete an already
    imported article.

## Filters and navigation

Article views support:

-   all;
-   unread;
-   read;
-   favorites;
-   category;
-   feed.

Unread counters may be displayed for the main views, categories and
feeds using the API counts endpoint.

## Search

Search is local and full-text over:

-   title;
-   summary;
-   content;
-   author.

Search results are always limited to the authenticated user's data.

## Article content

The application displays content supplied by the feed; it does not
scrape the source website to reconstruct full articles.

Remote HTML is untrusted and must follow `SECURITY.md`.

A link opens the original article.

## Recommendations

A `Recommandé` entry in the main navigation opens a dedicated view listing
up to twenty-four unread article suggestions:

- computed server-side from the user's own reading history only;
- signals: a weighted blend of the user's recent favorites (their titles and
  tags) matched via FTS against unread candidates, with additional weight for
  shared tags and the same category as favorites;
- candidates are unread articles of the user's own feeds, never favorited
  already, never other users' articles;
- the selection favors the closest FTS matches and prefers articles that share
  tags or categories with recent favorites;
- the final order applies a small bounded random jitter (at most 0,6 point on a
  score whose relevance weight is 3) so the same articles do not stay pinned at
  the top of the view; a clearly stronger match still comes first;
- no AI, no external service, no cross-user data;
- the view shows the standard empty state when the user has no favorite
  history; the list may also be empty when no relevant unread article is found.

## Article tags

Feed-provided article tags (RSS `category`, Atom `category` term/label) are
imported when valid: sanitized, deduplicated, at most 10 per article and 100
characters each. They are displayed as non-interactive chips at the bottom of
the article in the reader. Tags are not user-managed and are not used by
filters or search.

## Images and favicons

When available, primary article images and feed favicons are downloaded and
stored locally after validation. Images embedded in feed-provided article
content retain sanitized absolute HTTP(S) source URLs and are lazy-loaded
without an HTTP referrer. Active video embeds remain removed.

## Retention

Normal non-favorite articles older than one year are removed by cleanup.

Favorites are retained regardless of age.

Unused associated local files are removed.

## OPML

### Export

Exports the current user's subscriptions and category organization.

### Import

-   parses the OPML safely;
-   creates missing categories;
-   imports valid feeds;
-   avoids duplicate feed URLs;
-   reports skipped/failed entries without discarding successful
    imports.

## PWA

The application is installable. V1 offline behavior is limited to the
application shell as defined in `PWA.md`.

## Share article

From the reader, users can share an article to X, Facebook, LinkedIn and
Bluesky through dedicated share links, plus the browser's native Web Share
API when available. No third-party script is embedded in the application.
Details are defined in `FRONTEND.md`.

## Destructive actions

Deletion actions require clear confirmation in the UI.

## Empty and error states

The UI must provide understandable states for:

-   no subscriptions;
-   no articles;
-   no search result;
-   loading;
-   offline;
-   retrieval errors;
-   validation errors.

## V1 completion

V1 is functionally complete when a user can authenticate, organize
feeds, synchronize them, read/filter/search/favorite articles, manage
basic settings, exchange OPML and use the installable responsive
application.
