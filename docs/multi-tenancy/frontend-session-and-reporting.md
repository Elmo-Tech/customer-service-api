# Frontend Session and Reporting Cutover

## Repository scope

- Backend: `/Users/mahmoudsaber/Code/Elmo-Tech/customer-service-api`
- Frontend: `/Users/mahmoudsaber/Code/Elmo-Tech/Untitled/ticket-system`

The frontend remains React/Vite with Zustand, React Query, Axios, Ant Design, and Tailwind. Tenant isolation remains enforced only by backend authenticated queries and policies.

## Session lifecycle

On application startup, `SessionBootstrap` clears the legacy `token`, `profile`, `permissions`, `role`, and `logoutTime` cookies, calls `POST /api/v1/admin/auth/refresh` with credentials, then calls `GET /api/v1/admin/auth/me` with the returned in-memory access token. Zustand represents `booting`, `authenticated`, `unauthenticated`, `forbidden`, `disabled-tenant`, and `session-expired` states. No bearer or refresh credential is read from or written to local storage, session storage, or a JavaScript-readable cookie.

Axios attaches only the in-memory access token. Concurrent eligible `401` responses share one refresh request, each original request is retried at most once, and refresh/login/logout/public-review calls opt out of retry where required. Logout clears the in-memory session and React Query cache after the backend clears and revokes the HttpOnly refresh cookie.

The frontend API base defaults to `https://customerservicebe.testingelmo.com/api/v1/` and may be set with `VITE_API_URL`. The backend cookie origin, Secure, SameSite, path, CORS, and trusted-origin requirements remain defined in `authentication-and-review-capabilities.md`.

## Route and capability presentation

| Route | Presentation requirement |
|---|---|
| `/dashboard` | `view_ticket_dashboard` or compatible `all_tickets` |
| `/dashboard/tickets` | `all_tickets` |
| `/dashboard/submit` | tenant account; create endpoint also requires `all_tickets` |
| `/dashboard/users` | `all_customers` |
| `/dashboard/admins` | internal account and `all_users` |
| `/dashboard/roles` | internal account and `all_roles` |

Direct disallowed navigation renders a 403 result. These checks only control presentation; altering Zustand, URLs, DOM, filters, or selectors does not grant backend access. Tenant ticket filters omit company selection. The authenticated submission page omits company, PIN, and status inputs; `POST /api/v1/admin/tickets/create` derives company, assigned branch, opened-by user, and open status, and validates the customer through the caller's tenant scope. Branchless tenant submission stores no synthetic branch. The public `/` PIN workflow remains as a compatibility route.

## Reporting UI contract

The dashboard calls `GET /api/v1/admin/tickets/dashboard` with the documented `filter[...]` keys and renders `kpis`, `series`, `oldestOpen`, and `recentActivity` without recomputing aggregates. Internal users can present company, branch, and assignee filters; tenant identity is not user-selectable. Branch volume is omitted when `tenant.usesBranches` is false or the series is empty.

Ticket export calls `GET /api/v1/admin/tickets/export` once with the active filter object and downloads the streamed CSV Blob using the sanitized response filename. The removed frontend path no longer fetches all ticket pages. Dashboard and major route pages are lazy-loaded; no chart dependency was added.

## Browser verification checklist

- [ ] On an HTTPS staging origin, confirm refresh and review cookies are HttpOnly, Secure, SameSite=None, and scoped to their documented paths.
- [ ] Reload each account type and confirm refresh then `/me` completes without readable credential cookies.
- [ ] Confirm simultaneous expired API calls cause one refresh and one retry per eligible request.
- [ ] Confirm rejected refresh, replay, logout, inactive user, inactive company, and inactive branch states fail closed.
- [ ] Confirm internal, company, branch, employee, and branchless menus and direct-route 403 behavior.
- [ ] Confirm tenant filters cannot present company widening controls and altered requests remain backend-scoped.
- [ ] Confirm authenticated employee submission and own-ticket listing; retain and separately verify the public PIN route.
- [ ] Confirm dashboard empty/error/loading states, all series, branchless behavior, and filter parity with ticket list totals.
- [ ] Confirm export makes one streamed request and preserves active filters.
- [ ] Confirm review view, attachment, and submission calls carry no access bearer and use the review capability flow.

## Rollback and deployment gates

The frontend may be rolled back to the prior build while backend compatibility remains enabled, but the old readable-cookie session must not be re-enabled after the cutover is promoted. The authenticated create route is additive; rollback can stop presenting `/dashboard/submit` without deleting tickets or schema. Production approval still requires authoritative user mapping, backup/restore evidence, connected MySQL migration rehearsal, staging browser verification, and assigned canonical permissions. No production data was changed in this phase.

The vulnerable `xlsx` dependency and the now-unused `file-saver` dependency were removed after users, customers, companies, and tickets switched to scoped server CSV downloads. `npm audit --omit=dev` reports zero production vulnerabilities. The remaining full-audit findings affect the local Vite development server and require a breaking Vite upgrade; do not expose that development server to untrusted networks.
