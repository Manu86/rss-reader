# RSS Reader --- Security Specification

## Principles

All external input is untrusted.

Authentication is not authorization. User ownership is enforced
server-side and as close to database access as practical.

Security controls should be centralized and fail safely.

## Authentication and passwords

Use server-side PHP sessions.

Do not use JWT, OAuth or general-purpose API tokens in V1.

The single documented exception is the opt-in "remember me" cookie, which is
not an API token: it is a revocable, hashed-at-rest credential that only ever
reopens a server-side session, described under [Sessions](#sessions). It is
never accepted by the API on its own, and it carries no authority beyond the
session it restores.

Passwords:

-   use `password_hash()` / `password_verify()`;
-   prefer `PASSWORD_DEFAULT`;
-   minimum 12 characters;
-   support long passphrases;
-   never log, echo or store plaintext passwords;
-   never silently truncate.

Login failures use generic messages.

Apply simple rate limiting/progressive protection to repeated login
attempts without exposing account existence.

Failed logins are counted in two independent buckets over a 15 minute
window: per account, all addresses combined, and per address, all accounts
combined. Either bucket reaching its threshold refuses the attempt with the
same error, so the limit cannot be bypassed by rotating addresses, and a
known account cannot be locked out from a handful of addresses. The account
threshold (5 failures) is lower than the address threshold (20), because
several people legitimately share one address (family, company NAT). A
successful login clears the account bucket, whatever the addresses used;
it never clears the address bucket, so a single valid account cannot rearm
the spray protection. Attempts are recorded whether or not the account
exists, so a threshold never reveals that an account is real.

CLI password reset should use non-echoed interactive input where
practical.

A CLI password reset is an explicit re-secure, exactly like a password change
from the interface: it revokes every remembered device of the account, so a
stolen cookie cannot outlive the reset.

## Sessions

Session cookies:

-   opaque session ID only;
-   `HttpOnly`;
-   `SameSite=Lax` or stricter when compatible;
-   `Secure` in production;
-   application-specific cookie name.

Regenerate the session ID after login and password change.

Logout invalidates server-side authenticated state.

Use a reasonable session lifetime; no effectively permanent login.

Read-only GET requests release the session write lock after the
remember-me restore and the CSRF check have been persisted, so their
parallel fetches run concurrently instead of queuing. Mutating requests
and the CSRF token bootstrap (`GET /api/auth/csrf`) keep the lock. A
write arriving after the release reopens the same storage rather than
being silently dropped, so no security state is lost.

### Remember me

The login form offers an opt-in "remember me" checkbox, unchecked by default.
When checked, the server issues a second, dedicated cookie so an expired PHP
session does not force a new password entry.

The mechanism is a selector/validator pair:

-   the cookie value is `selector.validator`, both lowercase hexadecimal;
-   `selector` (16 random bytes) is the indexed lookup key;
-   `validator` (32 random bytes) is the secret;
-   only `sha256(validator)` is stored, so a database leak never yields a
    usable cookie;
-   resolution compares the stored hash with `hash_equals()`.

Rules:

-   the cookie is `HttpOnly`, `SameSite=Lax`, `Secure` in production, `Path=/`
    and application-specific, like the session cookie;
-   it only ever reopens a **server-side session**; the session identifier is
    regenerated on restore, so a pre-login identifier is never reused;
-   the account must still be active, otherwise the token is deleted;
-   `POST /api/auth/login` and `POST /api/auth/logout` never restore from the
    cookie: explicit credentials always win, and logout must not resurrect a
    session it is closing;
-   logout deletes the stored row and clears the cookie;
-   unchecking the box on a later login also deletes the stored row, not only
    the browser copy, so a stale cookie cannot silently sign the user back in;
-   changing the password revokes every remembered device, on the grounds that a
    password change is an explicit re-secure;
-   tokens expire after a fixed lifetime (30 days by default) and are never
    extended on use;
-   expired rows are pruned when a new token is issued, so no scheduled job is
    required and the table stays bounded;
-   rows are removed with their user through `ON DELETE CASCADE`.

Because the cookie is a bearer credential, it must never be logged, returned by
the API, or exposed in error messages.

Production requires HTTPS.
`APP_SESSION_SECURE` defaults to enabled and cannot be disabled when
`APP_ENV=production`; production always forces the cookie's `Secure` flag on.
Invalid values are rejected at startup. Disable it only for local HTTP
development with `APP_ENV` set to a non-production value.

## Authorization and user isolation

Never accept the authenticated user ID from request input.

Every operation on categories, feeds, articles, settings, search, OPML
and media must be scoped to the session user.

Profile email updates never accept a user identifier: they apply only to the
authenticated account, require CSRF protection and validate the address on the
server. Email addresses are not authentication identifiers in V1. Enabling a
digest requires a non-empty address, and the address plus frequency are updated
atomically.

Recommendation emails contain only the authenticated user's own recommended
article titles, source names, dates, categories and application links. All
feed-provided values are escaped. Locally stored thumbnails may be embedded as
inline MIME parts after bounded resizing, with a 100 KB per-image limit and an
approximately 2 MB aggregate MIME limit; messages contain no remote image
references, tracking content or active HTML. Email transport credentials are environment-only
secrets and must never appear in API responses, logs or the database. Delivery
errors log only the internal user identifier.

Media files are not served directly by the web server. The PHP-FPM and CLI
runtimes need shared filesystem access to the media store, while API ownership
checks remain the authorization boundary. On deployments using POSIX ACLs,
grant both runtime accounts access without making the media tree
world-readable.

Prefer user-scoped repository queries such as:

``` sql
SELECT * FROM articles
WHERE id = :id AND user_id = :user_id;
```

Cross-user resource access should generally return `404`.

Category/feed and feed/article ownership relationships must be
validated.

The frontend applies the same rule to what it displays: user-scoped panels are
cleared when the session ends, and a panel is only made visible once its
content has been replaced, so a new account can never see the previous
account's rendered data.

## CSRF

Because authentication uses cookies, protect every state-changing
request (`POST`, `PUT`, `PATCH`, `DELETE`) with a server-generated CSRF
mechanism.

Tokens must be unpredictable and tied to the authenticated session.

Login protection should follow the chosen consistent session/CSRF
design.

## SQL injection

Use PDO prepared statements with bound values.

Never concatenate untrusted values into SQL.

For dynamic ordering/filter names, use explicit server-side allowlists.

## XSS and article HTML

RSS/Atom HTML is untrusted.

Sanitize article HTML using a robust allowlist-based sanitizer.

Allow only necessary reading markup. Remove dangerous
elements/attributes including scripts, event handlers, embedded active
content and `javascript:` URLs.

Do not attempt to secure HTML with regex.

Frontend code should prefer `textContent` for plain text.

A strict Content Security Policy should provide defense in depth, not
replace sanitization.

## SSRF

Every server-side remote URL fetch must use the centralized safe HTTP
client.

Only `http` and `https` are allowed.

Block requests resolving to loopback, private, link-local,
multicast/reserved/internal destinations as appropriate for IPv4 and
IPv6.

Requirements:

-   resolve/validate destination before connection;
-   reject mixed unsafe DNS answers conservatively;
-   validate every redirect target;
-   limit redirects;
-   reject URL credentials;
-   keep TLS verification enabled;
-   use connection and total timeouts;
-   enforce response size limits.

The same rules apply to feed discovery, feeds, images and favicons.
Article-page metadata fetched as a fallback for a missing feed image follows
these rules as well; only a bounded HTML response is parsed and all discovered
image URLs are validated again before download.

The same bounded article-page response may provide public article text when the
feed content is absent or very short. Parsing never executes JavaScript or
submits forms, never sends publisher credentials and never attempts to bypass a
paywall. Extracted markup passes through the normal article HTML allowlist;
relative links and images are resolved and revalidated as HTTP(S) URLs.

The metadata fallback may override the HTTP User-Agent with the configured
browser-compatible article-page identity. This override is validated against
control characters, follows redirects unchanged and is never reused for the
discovered image download or for other remote requests.

Do not create alternate fetch paths that bypass these checks.

Implementation rules for the centralized client:

- reject any DNS result set containing an unsafe address, even when other
  answers are public;
- pin one validated address for the actual connection while retaining the
  original hostname for TLS verification;
- disable inherited HTTP proxy settings;
- handle redirects in application code and repeat the full validation;
- stream response bodies through a hard byte limit;
- allow only controlled outbound request headers;
- leave format/MIME/content validation to the calling feed, discovery or
  media service, which knows the expected response type.

## XML

RSS, Atom and OPML XML parsing must not allow unsafe external
entity/network/file resolution.

Protect against XXE and abusive entity expansion.

Use parser settings/APIs appropriate to the selected maintained PHP/XML
libraries.

## Remote media

Downloaded images/favicons must be treated as hostile.

Validate actual content, supported MIME/type and size.

Use application-generated filenames/storage keys.

Never trust remote filenames as local paths.

Reject path traversal.

V1 should reject SVG for downloaded article media because of its
active-content complexity.

Media storage remains outside the public document root and is served
through controlled application access.

Images embedded in sanitized article HTML are not downloaded. They may retain
only an absolute HTTP(S) `src` without URL credentials. All other attributes
are removed except bounded alternative text and application-controlled lazy
loading, asynchronous decoding and no-referrer attributes. This exception can
disclose the reader's IP address to the image host; active video/iframe embeds
remain forbidden.

The current implementation accepts validated JPEG, PNG, GIF, WebP and ICO
content within configurable byte, dimension and pixel limits. The declared
HTTP content type must match the detected content when present. Storage keys
are random application-generated values under a user-specific directory.
Media routes authorize the owning feed/article and never accept a storage key
from request input. SVG is rejected.

## OPML uploads

OPML import must:

-   limit upload size;
-   parse XML safely;
-   ignore untrusted filenames;
-   reject malformed/unsafe files;
-   clean temporary files.

Imported feed URLs still pass normal SSRF/feed validation.

The implementation accepts only the multipart `file` field, reads at most
1 MiB, rejects DOCTYPE/entity declarations before DOM parsing, uses
`LIBXML_NONET` without entity expansion and limits one document to 100
subscriptions. PHP owns and removes the upload temporary file; the original
filename and declared MIME type are never used. Every imported subscription
then runs through the centralized safe feed synchronization pipeline.

## Filesystem

SQLite, configuration, logs, temporary files and media must not be
directly downloadable.

Resolve storage paths from trusted application identifiers, not
request-supplied paths.

File permissions should follow least privilege.

## API

-   Validate all input server-side.
-   Reject invalid types/ranges.
-   Prefer rejecting unknown write fields.
-   Do not expose stack traces, SQL, paths or secrets.
-   Do not use permissive authenticated CORS.
-   Apply reasonable abuse limits to expensive remote-fetch endpoints.
-   Return consistent errors from `API.md`.

Feed discovery currently consumes one persisted attempt per authenticated
request and is limited to 10 attempts per user over 5 minutes. Invalid and
failed remote requests also count, preventing cheap bypasses of the limit.
Feed creation and manual refresh use the same persisted limiter with separate
action buckets, also limited to 10 requests per user over 5 minutes.

The cron synchronizer is a local CLI command, not an HTTP endpoint. It only
selects active feeds owned by enabled users and uses an exclusive filesystem
lock to prevent overlapping batches. Operational failure logs contain numeric
user/feed identifiers and controlled codes, never remote bodies, session data
or credentials.

## Security headers

Production should configure appropriate headers, including:

``` text
Content-Security-Policy
X-Content-Type-Options: nosniff
Referrer-Policy
Permissions-Policy
```

Frame embedding should be restricted through CSP unless explicitly
required.

The application shell permits images from its own origin and from HTTP(S)
origins because sanitized article content can reference remote images. Other
resource types remain restricted to the application origin. Browsers can still
block an HTTP image as mixed content when the application itself uses HTTPS.

## PWA/browser storage

Do not store authentication tokens or user RSS data in localStorage.

V1 service-worker cache contains static application assets only.

Authenticated API responses, article data and settings are not persisted
in Cache Storage/IndexedDB for offline use.

Logout clears sensitive in-memory frontend state.

## Logging

Never log:

-   plaintext passwords;
-   session IDs;
-   CSRF tokens;
-   secrets;
-   unnecessary full sensitive payloads.

Log enough context for diagnosis without exposing private content.

## Dependencies and production

Use maintained dependencies and supported runtime versions.

Run `composer audit` as an online maintenance check.

Production:

-   HTTPS;
-   debug disabled;
-   secure cookie settings;
-   non-public database/media/config;
-   least-privilege writable directories.

## Mandatory security tests

At minimum test:

-   authentication/session behavior;
-   cross-user access;
-   CSRF;
-   SQL-safe input paths;
-   SSRF including redirect to private address;
-   malicious XML/XXE fixtures;
-   XSS sanitization;
-   invalid/oversized media;
-   path traversal;
-   account switching/PWA cache privacy.

Detailed test organization belongs in `TESTING.md`.
