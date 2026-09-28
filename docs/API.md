# RSS Reader --- JSON API Specification

## Principles

-   Base path: `/api`
-   JSON unless explicitly stated otherwise.
-   Same-origin server-side session authentication.
-   No JWT/localStorage authentication.
-   State-changing requests use the CSRF strategy from `SECURITY.md`.
-   Every user-owned resource is scoped server-side to the authenticated
    user.
-   Cross-user resource IDs should generally behave as `404`.

Success:

``` json
{"data": {}}
```

Collections:

``` json
{"data": [], "pagination": {}}
```

Errors:

``` json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The request contains invalid data.",
    "fields": {}
  }
}
```

Use correct HTTP statuses (`200`, `201`, `204`, `400`, `401`, `404`,
`409`, `413`, `415`, `422`, `429`, `500`, `502`, `503` as appropriate).

## Authentication

Authentication is session based. Before dispatching, the kernel reopens the
session from a valid "remember me" cookie when the current session is not
already authenticated, so a session that timed out does not require a new
password entry. The restore regenerates the session identifier, and the
client transparently retries once with a refreshed CSRF token when the
restored session invalidates the one it held.

`POST /api/auth/login` and `POST /api/auth/logout` are excluded from that
restore: explicit credentials must always win, and logout must not resurrect
the state it is closing.

### `GET /api/auth/csrf`

Starts or resumes the same-origin session and returns its CSRF token:

``` json
{"data":{"csrf_token":"..."}}
```

The frontend sends this value in the `X-CSRF-Token` header for every
state-changing request, including login. The token is rotated after
login and password change.

### `POST /api/auth/login`

``` json
{"username":"user","password":"secret","remember":false}
```

Creates the server-side session and returns the authenticated user plus
the rotated CSRF token. Repeated failures are rate limited with `429`.

`remember` is optional and defaults to `false`; it must be a boolean when
present, otherwise the request fails with `422` and a `remember` field error.
When true, the response also sets a `Set-Cookie` header for the "remember me"
cookie; when false, it explicitly clears it and deletes any token already
stored for the presenting cookie. The token value is never returned in the
body.

### `POST /api/auth/logout`

Invalidates the session, deletes the presented "remember me" token and clears
its cookie.

### `GET /api/auth/me`

Returns the current user (`id`, `username` and nullable `email`) or `401`.

## Categories

### `GET /api/categories`

Lists the current user's real categories, ordered by name. The virtual
`Sans catégorie` group is never stored or returned as a category.

``` json
{
  "data": [
    {
      "id": 3,
      "name": "Développement",
      "created_at": "2026-09-24T12:00:00Z",
      "updated_at": "2026-09-24T12:00:00Z"
    }
  ]
}
```

### `POST /api/categories`

``` json
{"name":"Développement"}
```

Returns the created category with `201`. Names are trimmed, must contain 1 to
200 characters and are unique case-insensitively for one user. A duplicate
returns `409 CATEGORY_ALREADY_EXISTS`.

### `PATCH /api/categories/{id}`

``` json
{"name":"PHP"}
```

Returns the updated category. A foreign or missing category returns `404`; a
name conflict returns `409 CATEGORY_ALREADY_EXISTS`.

### `DELETE /api/categories/{id}`

Deletes the owned category and returns `204`; feeds remain and become
uncategorized. A foreign or missing category returns `404`.

All category writes require a valid CSRF token and reject unknown fields.

## Feed discovery

### `POST /api/feed-discovery`

``` json
{"url":"https://example.org/"}
```

Returns normalized candidates:

``` json
{
  "data": {
    "site_url": "https://example.org/",
    "feeds": [
      {"title":"News","url":"https://example.org/feed.xml","type":"rss"}
    ]
  }
}
```

Multiple candidates are returned to the frontend; the backend does not
choose arbitrarily.

The endpoint requires an authenticated session and CSRF token. It accepts a
website or a direct RSS 2.0/Atom document, follows only redirects accepted by
the centralized safe HTTP client, resolves relative declarations and validates
every candidate against the SSRF policy. Unsafe declared candidates are
omitted. The request is limited to 10 attempts per authenticated user over
5 minutes (`429 TOO_MANY_ATTEMPTS`).

Expected discovery errors include `NO_FEED_FOUND` and
`UNSUPPORTED_REMOTE_CONTENT` (`422`), and `REMOTE_FETCH_FAILED` (`502`).

## Feeds

### `GET /api/feeds`

Optional filters:

``` text
category_id
active
```

### `GET /api/feeds/{id}`

Returns one owned feed.

### `POST /api/feeds`

``` json
{
  "feed_url": "https://example.org/feed.xml",
  "category_id": 3,
  "name": "Optional custom name"
}
```

Backend validates remote URL/security, retrieves the feed, rejects
duplicates, creates the subscription and performs initial import.

The URL is fetched through the centralized safe HTTP client before the
database transaction starts. `name` is optional; when omitted, the validated
feed title is used. A successful creation imports the valid initial articles
and records the retrieval metadata. An invalid or unreachable initial feed
does not create a subscription. Expected errors include `INVALID_FEED` (`422`),
`FEED_FETCH_FAILED` (`502`) and `FEED_ALREADY_EXISTS` (`409`).

### `PATCH /api/feeds/{id}`

Supports documented editable fields such as:

``` json
{"name":"New name","category_id":null,"is_active":true}
```

Changing `feed_url` is allowed only if implemented according to
`RSS.md`.

The local-subscription milestone rejects `feed_url` changes. Supported
fields are currently `name`, `category_id` and `is_active`.

### `DELETE /api/feeds/{id}`

Deletes feed, articles and associated unused media.

### `POST /api/feeds/{id}/refresh`

Refresh one active owned feed.

``` json
{
  "data": {
    "feed": {},
    "imported_articles": 3,
    "not_modified": false
  }
}
```

A disabled feed returns `409 FEED_DISABLED`. A foreign feed behaves as `404`.
Conditional retrieval can return `not_modified: true` with zero imports.

### `POST /api/feeds/refresh`

Refresh all active feeds of the current user. Partial failures are
reported without cancelling successful feeds.

``` json
{
  "data": {
    "results": [
      {"feed_id":1,"status":"success","imported_articles":2,"not_modified":false},
      {"feed_id":2,"status":"error","error":{"code":"FEED_FETCH_FAILED","message":"..."}}
    ]
  }
}
```

## Articles

### `GET /api/articles`

Parameters:

``` text
filter=all|unread|read|favorites
category_id=<id>
category=uncategorized
feed_id=<id>
page=<n>
per_page=<allowed value>
```

Response:

``` json
{
  "data": [],
  "pagination": {
    "page": 1,
    "per_page": 25,
    "total_items": 0,
    "total_pages": 0
  }
}
```

List responses should avoid returning full article content when
unnecessary.

The frontend always requests the default batch of 25 articles. The API also
accepts an explicit `per_page` value of `10`, `25`, `50` or `100` for API
clients that need another batch size; this value is not taken from user
settings. Invalid filters and batch-loading values return `422`. A referenced
category or feed owned by another user behaves as `404`.

Each list item contains the article identifier, source feed summary (including
its nullable `category` object with `id` and `name`), title,
original URL, author, publication/discovery dates, summary, read-only
`tags` array (feed-provided article tags, possibly empty), optional controlled
`image_url`, and boolean read/favorite states. The `content` field is omitted.

### `GET /api/articles/{id}`

Returns the list fields plus full sanitized `content` without changing the read
state. A foreign or missing article returns `404`. Marking an article read is an
explicit CSRF-protected `PATCH` operation.

### `PATCH /api/articles/{id}`

Supports:

``` json
{"is_read":true}
```

or:

``` json
{"is_favorite":true}
```

Both boolean fields may be supplied together. At least one is required,
unknown fields are rejected, and CSRF protection applies. The response is the
updated detailed article representation. A foreign or missing article returns
`404`.

## Recommendations

### `GET /api/recommendations`

Returns up to twenty-four unread articles from the user's own feeds that resemble
their recent favorites. The 24 articles are selected randomly from the 48 best
eligible suggestions, computed only from local data (weighted FTS match against
favorite titles and tags, plus affinity for shared tags and categories). FTS
weights title, summary and content equally, while author matches receive a
lower weight to avoid incidental author-only matches. Recent articles receive
a small progressive freshness bonus, capped at one point over 30 days.
The response is `{data: [<article>]}` with the same article representation as
`GET /api/articles` and is returned in one response. The list is empty when the user has no
favorite history or no relevant unread candidates exist. Repeated calls may
return different articles from the pool. No other user's data or external
service is involved.

Optional query parameters `category_id=<id>` or
`category=uncategorized` scope the suggestions to one owned category (or the
virtual group). A `page` parameter is accepted and ignored because the list
is returned in one response. Unknown other fields are rejected with `422` and a foreign
or missing category behaves as `404`.

## Search

### `GET /api/search?q=php`

The response uses the same compact article representation and load metadata as
`GET /api/articles`. Optional parameters are `filter`, `category_id`,
`category=uncategorized`, `feed_id`, `page` and `per_page`, with the same
validation and ownership rules as the article list.

Search covers title, summary, content and author. Input is converted to at
most 20 literal Unicode terms and prefix-matched with SQLite FTS5; client input
cannot inject FTS operators. The query must contain at least one searchable
term, is limited to 200 characters, and returns `422` when invalid.

Search is always scoped to the current user.

## Counts

### `GET /api/counts`

Returns the authenticated user's global article counts and unread counts for
every owned category and feed, including resources whose count is zero:

``` json
{
  "data": {
    "all": 12,
    "unread": 7,
    "read": 5,
    "favorites": 2,
    "categories": [{"category_id": 3, "unread": 4}],
    "uncategorized": 1,
    "feeds": [{"feed_id": 8, "unread": 3}]
  }
}
```

All aggregations are scoped server-side to the authenticated user.

## Settings

### `PATCH /api/settings/profile`

Updates the authenticated user's optional profile email address:

``` json
{"email":"user@example.org","recommendation_email_frequency":"weekly"}
```

The email is trimmed and must be valid and at most 254 characters. The
frequency is required and must be `never`, `daily`, `weekly` or `monthly`.
An empty email is accepted only with `never` and clears the address. The
address is not a login identifier and does not need to be unique. Both values
are changed atomically. The endpoint returns the updated public user and
frequency, requires a valid CSRF token, rejects unknown fields and never
accepts a user identifier.

### `GET /api/settings`

Returns the authenticated user's V1 application settings. The
`articles_per_page` field is retained for API compatibility and does not alter
the frontend's fixed 25-article batches:

``` json
{"data":{"articles_per_page":25,"theme":"light","recommendation_email_frequency":"never"}}
```

No user identifier is accepted; settings are always resolved from the
server-side session.

### `PATCH /api/settings`

``` json
{"articles_per_page":25,"theme":"dark"}
```

`articles_per_page` must be the JSON integer `10`, `25`, `50` or `100` for
backward-compatible API clients; the frontend ignores this value and always
loads 25 articles at a time.
`theme` must be the JSON string `light` or `dark`. At least one field must
be present and valid; both may be sent at once and only supplied fields
are changed. The endpoint returns the updated settings, requires a valid
CSRF token and rejects unknown or missing fields with `422`.

### `POST /api/settings/password`

``` json
{
  "current_password":"...",
  "new_password":"..."
}
```

## OPML

### `POST /api/opml/import`

Accepts a `multipart/form-data` upload in the `file` field. The file is limited
to 1 MiB and 100 subscriptions. Filenames and client MIME declarations are not
trusted. XML containing a DOCTYPE/entity declaration, malformed XML and a
non-OPML document return `422`; an oversized file returns
`413 OPML_TOO_LARGE`.

Each URL passes the normal URL normalization, SSRF-safe retrieval and RSS/Atom
validation pipeline. Valid entries are retained when another entry fails.
Existing normalized URLs are reported as duplicates for that user.

``` json
{
  "data": {
    "imported": 3,
    "duplicates": 1,
    "failed": 2,
    "categories_created": 2
  }
}
```

The endpoint is authenticated, CSRF-protected and rate limited.

### `GET /api/opml/export`

Returns an `application/xml` OPML 2.0 attachment containing only the current
user's subscriptions and category organization. Real empty categories are
included; uncategorized subscriptions are direct children of `<body>`.

## Media

Local media is exposed through controlled application URLs, never raw
filesystem paths.

Media access must preserve user isolation.

### `GET /api/feeds/{id}/favicon`

Returns the locally stored favicon of an owned feed with its validated image
content type. A missing favicon or a feed owned by another user returns `404`.

Feed representations expose this route as `favicon_url`; storage keys are not
part of the public API.

### `GET /api/articles/{id}/image`

Returns the locally stored image of an owned article. A missing image or an
article owned by another user returns `404`.

Both responses are authenticated, use `Cache-Control: no-store` and never
accept a filesystem path or storage key from the request.

## Validation and security

-   Validate all input server-side.
-   Prefer rejecting unknown write fields.
-   Remote-fetching endpoints use timeouts, size limits and SSRF
    controls.
-   No permissive authenticated CORS.
-   Do not expose stack traces or internal exception names.
-   Authenticated API responses must not be indiscriminately cached by
    the PWA.

## V1 exclusions

The V1 exclusion list is owned by `PROJECT.md`. API consequences: no
endpoints for public registration, email password recovery, user-managed
tags, sharing, comments, AI features, cross-user or AI recommendations, or
push notifications. Feed-sourced article tags are exposed read-only, and
local favorite-based recommendations are exposed as described above.
