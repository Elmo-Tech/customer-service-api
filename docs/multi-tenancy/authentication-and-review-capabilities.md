# Authentication Sessions and Review Capabilities

## Access and refresh contract

- Access tokens remain JWTs issued by the installed `auth:api` guard. The default `JWT_TTL` is 15 minutes.
- Login and refresh return the access JWT as `token` for the controlled frontend compatibility period. They never return a refresh secret or `refreshToken` JSON value.
- Refresh sessions are independent opaque secrets. The database stores an indexed non-secret selector and SHA-256 hash of the high-entropy secret; the raw `selector.secret` value exists only in the browser cookie.
- `POST /api/v1/admin/auth/refresh` accepts only the refresh cookie. A bearer JWT or request-body token cannot refresh a session.
- Successful refresh locks the current row, creates a replacement session, and atomically revokes/links the predecessor. Expired, revoked, replayed, malformed, or hash-mismatched sessions return `401` and clear the cookie.
- Refresh and `/me` re-check user classification/status and active company/branch context. An invalid context fails closed. Logout is idempotent and can revoke/clear the refresh cookie even after access expiry; account disable and account deletion revoke applicable sessions.

The deployed frontend still stores the access JWT in a JavaScript-readable cookie. That is a temporary compatibility path only. The frontend cutover must keep access tokens in memory and must not copy access or refresh credentials to cookies or local storage.

## Refresh cookie deployment configuration

Current deployment uses `https://tickets-sys.testingelmo.com` for the frontend and `https://tickets-sys-api.testingelmo.com` for the API. Credentialed CORS must allow the frontend origin exactly, including the `https` scheme. The refresh cookie defaults are:

| Setting | Default | Reason |
|---|---|---|
| Name | `refresh_session` | Dedicated credential, separate from the JWT. |
| `HttpOnly` | `true` | JavaScript cannot read it. |
| `Secure` | `true` | The browser sends it only to HTTPS API requests. |
| `SameSite` | `None` | Required for the current cross-scheme frontend/API topology. |
| Path | `/api/v1/admin/auth` | Limits it to login-session endpoints. |
| Domain | unset | Host-only API cookie; it is not shared with the frontend host. |
| Lifetime | 20,160 minutes | Two-week refresh-session maximum; rotation does not extend a predecessor. |

Configuration keys are `AUTH_REFRESH_COOKIE`, `AUTH_REFRESH_TTL`, `AUTH_REFRESH_PATH`, `AUTH_REFRESH_DOMAIN`, `AUTH_REFRESH_SECURE`, and `AUTH_REFRESH_SAME_SITE`. `FRONTEND_URL` and `FRONTEND_DEV_URL` define credentialed CORS origins. Wildcard credentialed CORS is not used. Axios now enables `withCredentials` so browsers can accept and send protected cookies. Browser refresh requests with an `Origin` header must match that allowlist; non-browser clients without `Origin` remain supported.

Production must use HTTPS. `SameSite=None` without `Secure` is invalid in modern browsers. If frontend and API later become same-scheme same-site, reassess `SameSite=Lax`; do not change it without browser verification.

Rate limits are five login attempts per minute per username/IP hash, ten refreshes per minute per IP, and thirty logouts per minute per user/IP.

## Ticket review capability lifecycle

New closure emails use a `ticket_review_capabilities` row rather than `tickets.token`:

1. Issuance revokes any prior unconsumed capability for the same ticket and purpose.
2. A 64-character random secret is generated once and only its SHA-256 hash is stored.
3. The email URL receives the raw secret. Default expiry is 10,080 minutes (seven days).
4. Public view verifies ticket ID, hash, purpose `ticket_review`, expiry, revocation, and consumption.
5. View exchanges the URL secret for an HttpOnly, Secure, `SameSite=None` cookie scoped to that ticket's public attachment path. Attachment JSON URLs contain no token.
6. Review submission locks the capability and ticket in one transaction, applies the existing status/log behavior, and marks the capability consumed. Replay fails with a non-enumerating `404`.

The email link necessarily carries the one-time raw capability in its query string. Production reverse-proxy, CDN, analytics, and application access logging must redact the `token` query parameter, and review pages must not load third-party resources that can receive the referring URL.

Public view, attachment, and submission routes are limited to twenty requests per minute per IP. Wrong ticket, purpose, secret, expiry, revocation, consumption, or attachment-parent pairing returns `404`. New capability and legacy submissions do not write raw secrets or hashes to ticket logs. Historical legacy log tokens remain readable only by the compatibility replay check and are not mutated in this phase.

The status contract is unchanged: the endpoint accepts only existing values `DONE` (`1`) and `REOPENED` (`3`); existing closure/reopen emails and ticket date transitions remain unchanged.

## Legacy token audit and compatibility

Existing non-null `tickets.token` values remain supported for view, attachment, and submission. New closure emails do not populate that column.

Run the read-only audit in a connected environment:

```bash
php artisan review-capabilities:audit-legacy
```

The command prints counts only and never prints tokens. It reports zero authoritatively migratable rows because legacy records have no trustworthy purpose or expiry. Hashing the plaintext value would preserve secrecy at rest but would invent those missing authorization facts, so this phase intentionally performs no migration or production mutation. Existing links must expire through an approved product decision or remain on the isolated compatibility verifier.

## Deployment and rollback gates

- Rehearse both additive tables and rollback on MySQL after the local SQLite gate.
- Verify HTTPS, credentialed CORS origins, cookie domain/path, proxy headers, and browser acceptance in staging.
- Verify proxy/CDN/analytics logging redacts review `token` query parameters.
- Do not deploy new capability issuance until the capability-aware backend is guaranteed to remain available. An old application release cannot validate newly issued capability rows because no plaintext fallback is stored.
- Before any application rollback, stop new closure issuance and preserve both tables. Prefer roll-forward once refresh sessions or capabilities exist.
- Schema rollback is safe only before real sessions/capabilities are issued. Dropping populated tables would revoke sessions and break new review links and is not an approved production rollback.
