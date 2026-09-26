# RSS Reader --- Frontend Specification

## Principles

Frontend stack:

-   semantic HTML;
-   modern CSS;
-   vanilla JavaScript;
-   ES modules;
-   JSON API from `API.md`.

No frontend framework.

The interface is primarily a reading application: simple, fast,
responsive, keyboard accessible and content-focused.

## Main layout

Large screens use three conceptual areas:

``` text
Navigation | Article list | Article reader
```

Medium screens may use navigation + list, with reader replacing the list
when opened.

Mobile uses one primary panel at a time:

``` text
navigation drawer → article list → article reader
```

Breakpoints are content-driven, not device-brand-specific.

## Navigation

Primary entries:

``` text
Tous
Non lus
Lus
Favoris

Catégories
  ...
  Sans catégorie

Flux
  Voir les flux
```

The empty application route opens `Non lus` by default. `Tous` uses its own
explicit route so the global article view remains directly accessible.

Secondary actions:

``` text
Ajouter un flux
Paramètres
Déconnexion
```

The sidebar is limited to the available viewport height and uses its own
vertical scrollbar when its navigation content does not fit. The mobile drawer
uses the full dynamic viewport height with the same overflow behavior.

The selected view must be visually and semantically identifiable
(`aria-current` where appropriate), not by color alone.

## Accessibility behavior

- Every input, select and textarea has a programmatic label; validation errors
  are associated with the controls they describe.
- Route changes move keyboard focus to the destination page heading. Returning
  from an article restores focus to that article in the list when it is present.
- The mobile navigation opens with focus inside it, keeps keyboard focus within
  the open drawer, closes with Escape, and returns focus to its toggle when
  dismissed. Background content is inert while the drawer is open.
- Modal dialogs use a heading and description when available, support Escape,
  and restore focus to the control that opened them.
- Dynamic progress, error and confirmation messages use status or alert
  semantics without relying on color alone.
- Decorative icons and thumbnails are hidden from assistive technology; content
  images retain supplied alternative text.

Unread counters may use `GET /api/counts`.

## Article list

Each item should expose, when useful:

-   unread/read state;
-   title;
-   feed/source;
-   publication date;
-   summary/excerpt;
-   optional thumbnail;
-   favorite state.

The article date is followed by a compact category badge. Uncategorized feeds
use the label `Sans catégorie`.

The source name stays on one line at every viewport size and is truncated with
an ellipsis when the available width is insufficient.

Unread articles receive clear visual emphasis.

Navigating to an article from the interface loads its detail, then marks it read
through the state-update API. Reloading an already displayed detail does not
override a subsequent explicit `Marquer comme non lu` action.

While its detail is open, the corresponding item in a visible article list
keeps the same visual treatment as its hover state and exposes its current
state semantically.

Article lists use infinite scrolling. JavaScript requests and appends the next
server-side API page shortly before the user reaches the bottom; it never loads
the complete collection at once. Concurrent page requests are prevented and a
failed continuation exposes a retry action.

The article-list column is limited to the available viewport height. Its header,
articles and infinite-scroll trigger share one keyboard-focusable vertical
scroll region.

In a feed view, the feed deletion action appears below the list title with a
neutral secondary style. The destructive visual treatment is reserved for the
confirmation action.

Do not load all articles to paginate/filter in JavaScript.

## Article reader

Display:

-   title;
-   source;
-   author when available;
-   publication date;
-   sanitized article content;
-   favorite/read actions;
-   sharing actions;
-   link to original article.

The publication date in the reader is followed by the same category badge as
the article list, including `Sans catégorie` for uncategorized feeds.

Feed HTML must only be inserted through the sanitization contract from
`SECURITY.md`.

Sanitized content images use their absolute HTTP(S) source URL, remain within
the reading column and load lazily without sending an HTTP referrer. Failed
remote images must not make the rest of the article unusable. Active video
embeds remain unavailable.

External article links use normal browser behavior and must be clearly
identifiable.

## Sharing

Article sharing is implemented in the frontend only, without third-party
scripts or SDKs:

-   Share targets are X, Facebook, LinkedIn and Bluesky, implemented as plain
    links opening the network's share intent in a new tab with
    `rel="noopener noreferrer"`;
-   When the browser's Web Share API is available, a native share button is
    offered in addition to the fixed targets;
-   Shared titles and URLs are fully URL-encoded and always validated
    against the same safe-HTTP(S) rules as the original article link;
-   Sharing is hidden for articles without a usable original link;
-   No tracking pixels, counters or social SDK requests are made.

## Filters and search

The frontend exposes:

-   all;
-   unread;
-   read;
-   favorites;
-   category;
-   feed.

Search queries the backend full-text endpoint and displays paginated
results.

Do not implement a second client-side search index.

## Feed management

Users can:

-   add a direct feed URL;
-   discover feeds from a website;
-   choose among multiple discovered feeds;
-   rename;
-   change category;
-   enable/disable;
-   refresh;
-   delete.

Display retrieval status/error without exposing technical internals.

Deletion requires confirmation.

## Categories

Users can create, rename and delete categories.

`Sans catégorie` is virtual and cannot be renamed/deleted.

Deleting a real category explains that its feeds will remain and become
uncategorized.

## Settings

Settings UI contains:

-   password change;
-   articles-per-page selection;
-   OPML import;
-   OPML export.

No general preference framework is required.

## API client

Centralize HTTP behavior in a small API module.

Responsibilities:

-   JSON requests/responses;
-   credentials/session handling;
-   CSRF token/header;
-   standard error parsing;
-   authentication expiry handling.

Do not scatter raw `fetch()` behavior throughout every view.

## JavaScript organization

A lightweight structure is sufficient:

``` text
assets/js/
├── app.js
├── api/
├── views/
├── components/
└── utils/
```

Keep server/business rules on the backend.

Avoid a complex global state-management system.

## Loading and errors

Provide clear states for:

-   initial loading;
-   view loading;
-   empty list;
-   no search result;
-   validation error;
-   feed retrieval failure;
-   expired authentication;
-   offline/server unavailable.

Do not replace the whole interface with raw API error text.

## Optimistic UI

Use optimistic updates only when failure can be safely reverted.

Do not display destructive or offline mutations as permanently
successful before server confirmation.

## Accessibility

Requirements include:

-   semantic landmarks/headings;
-   keyboard navigation;
-   visible focus;
-   correctly associated form labels;
-   accessible names for icon buttons;
-   dialogs with focus management;
-   sufficient contrast;
-   color pairs meet WCAG AA ratios, verified by automated tests;
-   the interface respects `prefers-reduced-motion` and adapts focus/borders
    for `prefers-contrast: more`;
-   the application shell passes an automated axe-core audit as part of the
    frontend test suite (`tests/Frontend/accessibility.test.mjs`);
-   status/error announcements where useful;
-   no essential information conveyed by color alone.

Article content must preserve a logical heading/reading structure as far
as sanitized source content allows.

## Responsive behavior

Desktop, tablet and mobile must remain usable.

Avoid horizontal scrolling for normal application controls.

Touch targets must remain usable on small screens.

## Dates and text

User-facing interface is French.

Dates are formatted for the user in the frontend from API UTC values.

Do not modify source article text except for safe
presentation/sanitization.

## Security

Frontend validation improves UX but is never authoritative.

Do not store passwords, auth tokens or user article data in browser
persistent storage.

Use `textContent` for untrusted plain text.

Follow `SECURITY.md` for HTML, CSRF and browser-storage rules.

## PWA

PWA registration and offline behavior follow `PWA.md`.

The ordinary online website must remain usable if service-worker
registration fails.
