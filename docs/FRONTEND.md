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

## Login

The login view is a single labelled form: username, password, the
`Se souvenir de moi` checkbox, an error region and the submit button.

-   the checkbox is **unchecked on every visit**: the choice is never
    pre-selected, so staying signed in is always an explicit act;
-   it is a real `<input type="checkbox">` associated with its `<label>` through
    `for`/`id`, reachable by keyboard and operable with <kbd>Space</kbd>;
-   the row is a left-aligned flex line, with the box hugging the left edge and
    the label beside it; the box is deliberately small (`.6rem`) so it does not
    compete with the text, and the label, not the box, is the pointer target;
-   the label keeps a `1.75rem` minimum height so the row stays comfortable to
    tap on a touch screen;
-   its state is submitted as `remember` with the credentials, and nothing about
    it is stored client-side;
-   it is disabled while the request is in flight, like the other controls.

The frontend holds no token of its own: the "remember me" cookie is `HttpOnly`
and is set and cleared by the server, so the choice cannot be read or forged
from JavaScript. Restoring a session needs no special client code, because the
API client already retries once with a refreshed CSRF token.

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
Recommandé
Non lus
Lus
Tous
Favoris

Catégories
  +
  ...
  Sans catégorie

Flux
  +
  Voir les flux
```

The `Catégories` and `Flux` headings each expose an icon button in the heading
row, in the same visual style, that opens the creation dialog of that section
directly. Each button carries an accessible name and a tooltip naming what it
adds, because the `+` icon alone conveys nothing to assistive technology.

The header actions expose the same `Ajouter un flux` button, which replaces the
previous settings shortcut there. Settings stay reachable from the sidebar, so
no route is lost. Like the refresh button, the header action is hidden on narrow
viewports where the sidebar is the primary way to reach these actions.

The empty application route opens `Recommandé` by default. `Tous` uses its own
explicit route so the global article view remains directly accessible.

Each category row expands a sublist of its subscriptions. A subscription is
presented exactly like a source line in the article list: the same 1.25rem
local favicon, the same `.4rem` gap, the same muted `.78rem` name. The
presentation is declared once, by sharing the `article-card-topline`,
`article-source-name` and `article-favicon` rules with the navigation, so the
two cannot drift apart. The sublist has no left border and no left indent, so
subscription names align with their category. A long name is truncated with an
ellipsis and must never widen the sidebar, so the sublist, its rows and the
name itself all declare `min-width: 0`. The favicon is decorative, so it is
hidden from assistive technology and the link keeps the feed name as its
accessible text.

Secondary actions:

``` text
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
server-side batch shortly before the user reaches the bottom; it never loads
the complete collection at once. Concurrent batch requests are prevented and a
failed continuation exposes a retry action.

The article-list column is limited to the available viewport height. Its header,
articles and infinite-scroll trigger share one keyboard-focusable vertical
scroll region.

In a feed view, the feed deletion action appears below the list title with a
neutral secondary style. The destructive visual treatment is reserved for the
confirmation action.

Do not load all articles to filter in JavaScript.

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

When the reader replaces the article list (mobile), the `Retour à la liste`
action appears both at the top and at the bottom of the article, using the
same control and the same accessible name. On large screens the bottom action
is hidden because the article list stays visible.

The bottom of the reader also exposes `Précédent` and `Suivant` links when the
corresponding neighbouring article exists in the current loaded list. These
links preserve the originating view, category, feed or search context. A
missing neighbour is omitted rather than rendered as a disabled control.

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

## Article tags

The reader displays feed-provided article tags at the bottom of the
article, after the content and before the external link and sharing:

-   The tags come from the read-only `tags` field of the article API
    response;
-   Tags render as small rounded chips with a tag icon, without
    color-only meaning;
-   Articles without tags display nothing in their place;
-   Tags are not interactive: they do not filter, search or link.

## Recommendations

The main navigation menu has a `Recommandé` item (`/#/recommandations`) as its
first entry, and it is the default view of the application. It opens a
dedicated view that lists the backend suggestions as normal article cards:
unread articles computed by the recommendation endpoint. With no suggestion,
the view shows the standard empty state. Opening an article from the view
keeps a working back navigation to the list.

Because suggestions are capped, the view closes with a `Voir les articles non
lus` link at the bottom of the list, pointing at the unread view. It is the
only view that shows this footer. The link is a real anchor, so it is
keyboard reachable and supports opening in a new tab, and the icon is
decorative.

## Filters and search

The frontend exposes:

-   all;
-   unread;
-   read;
-   favorites;
-   category;
-   feed.

Search queries the backend full-text endpoint and displays results through the
same progressive batch loading as article lists.

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

In the feeds tab, each feed initially displays only its first line. The line is
an accessible accordion summary; activating it with a pointer or keyboard
reveals the feed details and actions. The details are collapsed by default.

In the feeds tab, the feed count and the panel actions (add a feed, refresh
all) share a single row, with the actions aligned to the trailing edge on
every screen width.

Deletion requires confirmation.

## Categories

Users can create, rename and delete categories.

`Sans catégorie` is virtual and cannot be renamed/deleted.

Deleting a real category explains that its feeds will remain and become
uncategorized.

## Settings

Settings UI contains:

-   optional account email editing and recommendation email frequency;
-   password change;
-   articles-per-page selection;
-   OPML import;
-   OPML export.

Section order is stable: appearance, profile, feed refresh, OPML, then
password change last. The password section is the destructive/rare action and
stays at the bottom of the page.

The profile section exposes a labelled `type="email"` control, prefilled with
the current user's address when present, and a labelled frequency select with
`Jamais`, `Quotidienne`, `Hebdomadaire` and `Mensuelle`. Submitting an empty
address is possible only with `Jamais` and clears it. The interface explains
that the address is optional, is not used for login, and that delivery occurs
from 08:00 Europe/Paris on the relevant daily, Monday or first-of-month period.

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

When an API error code has a known user-facing meaning, show that specific
text rather than the generic status-based message. In particular, duplicate
subscription and duplicate category codes must state that the entry already
exists, instead of the generic conflict wording.

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

## Typography

Text must render identically in the browser and in the installed PWA.

Use the platform UI font stack starting with `system-ui`. Do not reference a
font that is not shipped with the application and do not load remote fonts:
the shell CSP restricts `font-src` to the application origin.

Use standard font weights only (multiples of 100). Fractional or unavailable
weights are synthesized by the browser and render thin and inconsistent text
across platforms.

Set `text-size-adjust: 100%` so installed standalone windows do not inflate
text or apply font boosting.

Ship CSS changes through the service-worker cache version described in
`PWA.md` so installed applications receive them.

## Theme and system bars

The interface theme is a user setting (light or dark) and does not follow the
system preference.

The installed PWA runs in full screen, as described in `PWA.md`: the layout
must stay clear of the display cutout and of the gesture area, through
`viewport-fit=cover` and the `env(safe-area-inset-*)` margins applied to the
header, the page and the navigation drawer.

The theme color of the page, used by the browser interfaces and by the
platforms that honor the meta element, always matches the page background of
the active theme. Changing the theme updates it immediately.

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
