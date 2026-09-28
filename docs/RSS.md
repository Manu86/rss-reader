# RSS Reader --- RSS / Atom Processing

## Scope

This document owns feed discovery, retrieval, parsing, normalization,
deduplication, dates, media and synchronization behavior.

All remote content is untrusted.

## Supported formats

V1 supports:

-   RSS 2.0;
-   Atom 1.0.

Common real-world variations may be tolerated when safe.

Normalize both formats before application services consume them.

Normalized article fields include:

``` text
guid
title
url
author
published_at
summary
content
image candidates
```

The application adds `discovered_at`, deduplication identity, local
media and user state.

## Feed discovery

For a website URL:

1.  retrieve HTML through the centralized safe HTTP client;
2.  find declared RSS/Atom `<link rel="alternate">` entries;
3.  resolve relative URLs correctly;
4.  normalize/deduplicate candidates;
5.  return zero, one or several candidates.

A discovered URL must pass the same security and validation rules as a
manually entered URL.

If the submitted URL is itself a valid feed, treat it as a direct feed.

## Remote HTTP

Only `http` and `https` are allowed.

Requirements:

-   TLS verification enabled;
-   bounded connection/total timeout;
-   response size limits;
-   redirect limit;
-   validate every redirect target;
-   SSRF protections from `SECURITY.md`;
-   controlled User-Agent;
-   no arbitrary protocol handlers.

Support conditional requests when available:

-   store `ETag` and send `If-None-Match`;
-   store `Last-Modified` and send `If-Modified-Since`;
-   `304 Not Modified` is a successful synchronization with no import.

## Feed validation

A successful HTTP response is not sufficient.

The response must parse as supported RSS/Atom and contain enough
structure to be considered a feed.

Malformed or unsupported feeds return a controlled error and must not
destroy existing local data.

## Feed metadata

Extract when available:

-   title;
-   canonical/site URL;
-   feed URL;
-   description;
-   favicon candidates.

User-customized feed names must not be overwritten by routine
synchronization.

## Article identity and deduplication

Prefer stable feed identifiers:

1.  GUID/Atom ID when available;
2.  normalized article URL;
3.  deterministic fallback from stable article fields.

Generate a deterministic `deduplication_hash` scoped to the feed.

Never use title alone as identity.

Database uniqueness on `(feed_id, deduplication_hash)` is the final
duplicate guard.

Because a publisher may republish an item under a new GUID, a republish is
recognized before insertion: an incoming item whose URL and title are already
stored for the same feed, published the same UTC day, is treated as the
existing article and refreshed, even when its GUID differs.

Both conditions are required. The title prevents merging distinct articles
that share a non-specific URL, such as a site root URL repeated across many
items of the same feed. The publication day prevents merging scheduled
rebroadcasts: some feeds replay the same episode every week under an identical
URL and title, and each airing is a distinct article. The rule is feed-agnostic
and applies uniformly to every feed and every user.

The same article identity in different feeds or different users remains
independent.

## Existing articles

When synchronization sees an already imported article:

-   do not create a duplicate;
-   never reset read/favorite state;
-   preserve original `discovered_at`.

Remote disappearance does not delete the local article.

Textual updates may update safe remote fields if implementation chooses
a deterministic policy, but must not change article identity or local
user state.

## Article tags

Feed-provided article tags are imported and stored per article:

-   RSS: the `category` item elements;
-   Atom: the `category` entry elements (`term`, falling back to `label`).

Rules:

-   tags are plain text, sanitized like other feed text;
-   duplicates and empty values are removed;
-   at most 10 tags per article, at most 100 characters each;
-   missing or invalid tags never block article import;
-   tags are display-only data and are not used for filtering, search
    or article identity in V1.

## Dates

Keep separate:

-   `published_at`: normalized remote publication date;
-   `discovered_at`: first successful local discovery.

Rules:

-   convert valid dates to UTC;
-   missing/invalid dates do not block import;
-   implausible dates must not break ordering/retention;
-   use `discovered_at` as fallback when publication date is unusable.

Default ordering is defined in `DATABASE.md`.

## Content

Store feed-provided summary and content separately.

Do not scrape source websites to reconstruct missing full article
content.

HTML is sanitized according to `SECURITY.md` before safe display/storage
strategy chosen by implementation.

The current storage boundary retains allowlisted reading markup. Script, style
and active/embed elements are removed. Embedded image sources are resolved
against the article URL (falling back to the feed document URL), normalized to
absolute HTTP(S) URLs and stripped of all unapproved attributes.

Plain-text article summaries and content are converted from GitHub-Flavored
Markdown before sanitization and storage. Raw HTML in Markdown is escaped,
unsafe links are disabled, and the converted markup passes through the same
allowlist sanitizer as feed-provided HTML. Existing HTML from a feed is not
reinterpreted as Markdown.

Original article URL remains available.

## Images

Choose an image candidate from standard feed/media/content sources when
available.

When an article has no image candidate in the feed, the synchronizer may fetch
the article page and inspect only standard image metadata (`og:image` and
`twitter:image`). It does not use that page to reconstruct the article text.
Candidate page and image URLs pass through the same centralized safe HTTP
client and validation pipeline as feed-provided media.

Download through the centralized safe HTTP client.

Validate:

-   URL/security;
-   HTTP status;
-   MIME/content;
-   supported image type;
-   file size;
-   optional dimension limits.

Store locally with application-generated filenames/keys.

Primary images selected for article cards are not hotlinked. Images embedded
inside article content are an explicit exception: their normalized absolute
HTTP(S) URL is retained for browser loading with `loading=lazy`, asynchronous
decoding and `referrerpolicy=no-referrer`. URL credentials, non-HTTP(S)
schemes, event handlers and source sets are rejected.

V1 should reject SVG as downloaded article media.

## Favicons

Favicon discovery may use feed/site metadata and conventional
candidates.

Favicons follow the same remote HTTP and media validation rules.

Failure to obtain a favicon is non-fatal.

## Synchronization algorithm

For each active feed:

``` text
validate feed ownership/state
→ fetch with conditional headers
→ handle 304 or parse response
→ normalize feed/articles
→ identify/deduplicate articles
→ persist new/allowed updated data
→ download selected media safely
→ update fetch status/timestamps
```

Do not hold SQLite write transactions during remote HTTP.

One malformed article should not necessarily invalidate an otherwise
usable feed.

Current implementation details:

-   RSS 2.0 and Atom 1.0 are normalized by the same parser;
-   dates before 1970 or more than seven days in the future are treated as
    unusable and fall back to `discovered_at`;
-   initial creation fetches/parses before opening the insertion transaction;
-   refresh records the attempt before networking, then uses a short import
    transaction;
-   existing rows are left unchanged, preserving original remote fields,
    `discovered_at`, read state and favorite state;
-   `ETag` and `Last-Modified` drive conditional requests and `304` is recorded
    as a successful refresh.
-   RSS enclosures, Media RSS content/thumbnails, Atom enclosures and the first
    safe HTTP(S) image found in feed-provided content are image candidates;
-   declared RSS channel images and Atom icon/logo elements are favicon
    candidates;
-   candidates are fetched only after the article/feed transaction has
    completed, so remote I/O never holds an SQLite write transaction;
-   when the feed has no article image, standard Open Graph/Twitter image
    metadata from the article page is used as a bounded fallback;
-   JPEG, PNG, GIF, WebP and structurally valid ICO files are accepted after
    content inspection; SVG and MIME/content mismatches are rejected;
-   media failure is non-fatal and missing media is retried on a later
    synchronization when the candidate remains available;
-   when the article has no feed image candidate, the article-page metadata
    fallback is attempted once and its checked state is persisted; explicit
    feed image candidates remain eligible for retry when download fails.

## Status

Track at least:

-   last attempt;
-   last successful fetch;
-   last article date;
-   last status;
-   concise last error.

Do not expose internal stack traces in user-facing status.

## Manual and cron refresh

Manual refresh and cron use the same synchronization service.

-   individual refresh: one active owned feed;
-   refresh all: active feeds for the user;
-   cron: active feeds for enabled users.

A failure on one feed must not stop unrelated feeds.

The automatic path is exposed as `php bin/console feeds:refresh`. It bypasses
the interactive per-user abuse limiter because it is a trusted local command,
but it uses an exclusive non-blocking process lock. Failures are logged with
user/feed identifiers and controlled error codes; URLs, response bodies and
credentials are not logged.

## Retention interaction

Retention uses valid `published_at`, otherwise `discovered_at`.

Favorites are exempt.

Cleanup is separate from feed synchronization and removes unused local
article media.

## Parser/HTTP dependencies

A maintained Composer dependency may be used for parsing or HTTP, but
the application remains responsible for normalization, authorization and
security boundaries.

Exact dependencies and versions belong in `composer.json`.

## Failure rule

Remote failures must be safe:

-   keep previously imported data;
-   record useful status;
-   do not weaken TLS/SSRF/XML protections to make a feed work.
