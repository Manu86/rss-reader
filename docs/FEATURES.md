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

### Staying signed in

The login form offers an opt-in `Se souvenir de moi` checkbox, **unchecked by
default**, so a new login never prolongs a session the user did not ask for.

When checked, the account stays signed in across browser restarts for up to 30
days, without weakening the password rules: no password is ever stored, and the
credential can be revoked at any time.

Expected behavior:

-   the choice is remembered only for the browser that made it, never
    synchronized to the account;
-   a session that has simply expired silently reopens, so the user lands
    directly on their articles;
-   logging out ends the remembered sign-in as well;
-   unchecking the box at the next login forgets the current browser;
-   changing the password forgets every remembered browser;
-   deactivating an account invalidates its remembered sign-ins;
-   a disabled account is never restored, even with a valid token.

See `docs/SECURITY.md` for the token mechanism and `docs/DATABASE.md` for
storage.

## User independence

Every user owns independent categories, feeds, articles, read states,
favorites and settings.

Two users subscribing to the same URL still have separate subscriptions
and article records.

## Settings

V1 provides:

-   an optional account email address and a recommendation email frequency,
    editable by the authenticated user;
-   password change;
-   a fixed 25-article load size for article lists;
-   interface theme selection (light or dark);
-   OPML import;
-   OPML export.

Article lists always load 25 articles before the infinite scroll requests the
next batch.

The email address is profile information only. It is not a login identifier,
does not need to be unique and can be cleared. It is used only for the optional
local recommendation digest and is never used for password recovery.

Recommendation emails are opt-in and disabled by default. The available
frequencies are daily, weekly and monthly, plus `never` to disable delivery.
Delivery starts at 08:00 Europe/Paris; weekly periods start on Monday and
monthly periods on the first day of the month. If the hourly job did not run at
the exact boundary, the first later run in the same period sends the digest.
No message is sent when there are no recommendations. A successful delivery is
recorded so the same user receives at most one digest per selected period; a
failed or empty delivery remains eligible for a later hourly run.
Each digest contains at most 24 articles, while the web view lists up to 48, so
a larger message cannot increase deliverability risk or the daily reading load.
The email uses the same selection as the web list. Each recommended article uses
the same information hierarchy as the web list:
source, title, publication date and category. The email uses self-contained
styles suitable for mail clients, embeds locally stored article thumbnails and
links each card back to the application. Email thumbnails are center-cropped to
88 × 88 pixels, encoded as JPEG at no more than 100 KB each, and collectively
limited to about 2 MB after MIME encoding. Articles without an available image
show the RSS pictogram used by the web list.

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
up to forty-eight unread article suggestions:

- computed server-side from the user's own reading history only;
- signals: a weighted blend of the user's recent favorites (their titles and
  tags) matched via FTS against unread candidates, with additional weight for
  shared tags and the same category as favorites;
- candidates are unread articles of the user's own feeds, never favorited
  already, never other users' articles. They enter the candidate set through
  either an FTS match, a shared tag or a category shared with a recent favorite;
- a minimum score of 2 is required for an article to be recommended: on top of
  the base point, it needs at least one shared tag (+2), the same category as a
  favorite (+2), or an FTS relevance of at least one third of the best
  candidate (+3 × ratio). Articles whose only reason to match is an incidental
  common word are not displayed;
- the threshold applies to the deterministic part of the score, before the
  jitter, so the selection is reproducible between two identical loads;
- the selection favors the closest FTS matches and prefers articles that share
  tags or categories with recent favorites;
- the selected articles are displayed from the most recent to the oldest,
  falling back to the discovery date when no publication date is available.
  Score drives which articles are selected, date drives how they are presented;
- a freshness bonus of up to +1 point favors recent articles and decreases
  progressively over 15 days; age affects ranking but does not by itself
  exclude an eligible article;
- FTS and affinity candidates are preselected with a per-feed window before the
  bounded global candidate limit is applied, so a prolific source cannot hide
  otherwise eligible articles from other feeds;
- no single feed may occupy more than five places in the pool, therefore in the
  view: a feed close to the user's favorites and rich in articles cannot take the
  whole list. The limit is applied when building the pool, since capping only the
  displayed articles would let one feed fill the pool. As a direct consequence,
  the view returns fewer than 48 articles when the user follows fewer than ten
  sources with eligible articles, and the empty state is not involved: the list
  is simply shorter;
- the 96 best eligible suggestions form a pool, from which 48 are selected by
  a score-weighted random draw for display; stronger suggestions are therefore
  more likely to survive the draw without making the list static. A small
  bounded random jitter (at most 0,6 point on a
  score whose relevance weight is 3) is applied before building the pool so
  equally close matches can vary. The pool is deliberately larger than the
  displayed list so the random draw stays meaningful; with an equally sized pool
  the draw would return the whole pool and the variety would be lost;
- no AI, no external service, no cross-user data;
- recent favorite signals are ordered by their dedicated favorite timestamp;
  reading, synchronization and media updates do not make an old favorite recent;
- the view shows the standard empty state when the user has no favorite
  history; the list may also be empty when no relevant unread article is found
  or when none of them reaches the minimum score. At most 48 articles are
  returned. When more than 48 suggestions are eligible, the response does
  not expose the total number of eligible suggestions.

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
