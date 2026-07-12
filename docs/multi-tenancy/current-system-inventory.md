# Current System Behavior and Data-Flow Inventory

## Repository and runtime baseline

- Backend is Laravel 10.50 with JWT Auth, Spatie Permission, Spatie Query Builder, and Sanctum installed but unused for the current login flow.
- Frontend is React 18 with Vite, React Router, TanStack Query, Axios, Zustand, Ant Design, and `react-cookie`.
- Backend `.env` targets one MySQL database on localhost. The database was unavailable during preparation, so production row counts and mappings must be captured by the migration audit before backfill.
- The approved production baseline states one existing company, four branches, customers, tickets, attachments, logs, users, roles, permissions, emails, filters, exports, and public review links. None may be removed by Phase 1.

## Backend data model

| Resource | Current ownership and behavior |
|---|---|
| Users | No company or branch columns; global Spatie roles; soft deleted; username/email globally unique. |
| Companies | Status and soft deletion; has many branches. |
| Branches | Nullable `company_id`; cascade delete from company. |
| Customers | Nullable `company_id`; plaintext PIN; email added by later migration; soft deleted. |
| Tickets | Nullable company/customer/branch foreign keys; status, importance, description, close dates, review token, tag, soft deletion. |
| Attachments | Ticket child stored on the public disk and exposed by direct URL. |
| Ticket logs | Ticket child with status, text, and review token; public write and currently public list. |

## Backend request flows

- Login returns one JWT as both access and refresh token plus profile, first role, and permissions.
- Admin resource controllers use authentication and action middleware except roles, whose middleware is commented out.
- Services start list queries from global models and use unrestricted `find()` for records.
- Public ticket creation validates customer ID plus plaintext PIN, then accepts company, customer, branch, status, importance, tag, and files from the browser.
- Selects are public and dynamically expose users, roles, permissions, companies, branches, customers, and parameters.
- Public review reads a ticket by ID plus plaintext UUID token; token has no expiry. Review submission consumes it and changes ticket status.
- Ticket log listing accepts any existing ticket ID without authentication.
- Ticket creation, status changes, reopening, and closure send existing email notifications to hard-coded recipients. These side effects must remain covered during migration.

## Frontend data flow

- Axios uses the testing API base URL and reads `token` from `document.cookie` before each request.
- Login stores token, profile, role, permissions, and logout time in JavaScript-readable cookies.
- Dashboard guards trust cookie profile data; navigation trusts cookie role and permission data.
- `/` is a session-aware redirect: unauthenticated visitors go to login, tenant users go to authenticated submission, and internal users go to the dashboard.
- `/dashboard` exposes ticket, customer, user, and role screens. Dashboard content is a placeholder.
- Ticket export fetches all pages client-side; backend scope therefore governs export safety.
- `/review` consumes the public ticket review endpoints.

## Compatibility hazards

- Branchless tickets are allowed by the database but rejected by request validation and public UI logic; ticket list serialization dereferences a branch unconditionally.
- Ticket number generation uses the historical `customer_id` when present and otherwise the authenticated `opened_by_user_id`; imported customer identity remains supported.
- Ticket and public review resources assume customer/company relationships exist.
- Public disk URLs bypass future controller policies unless file delivery changes.
- Existing permission names such as `all_tickets` must remain mapped while canonical capabilities are introduced.
- Two existing frontend files exceed the 250-line authored-file limit: `ReviewContent.jsx` (275) and `TicketModalForm.jsx` (270). Each must be split before or when touched.

## Attachment compatibility state after private-delivery phase

- New ticket files are stored on the private `ticket_attachments` disk with original name, byte size, and SHA-256 metadata.
- Existing rows default to the legacy public location and remain readable only through authorized application delivery.
- Authenticated downloads resolve the scoped parent ticket, apply its policy, and then resolve the attachment from that ticket relation.
- Public review downloads require the current unused token for the same parent ticket and return an application stream, never a storage URL.
- The migration command is dry-run by default, preserves originals, and changes metadata only after copy verification.

## Reporting compatibility state

- The existing frontend still exports by fetching every ticket page. It remains unchanged until the approved frontend phase moves it to the streamed endpoint.
- The backend exposes streamed CSV export and chart-neutral dashboard JSON from the same authorized filtered ticket query as the list.
- The dashboard page remains a frontend placeholder in this backend phase.

## Session and review compatibility state

- Login still returns the access JWT under `token` for the deployed frontend, but refresh is now an independently hashed and rotated server-side session delivered only by HttpOnly cookie.
- The frontend enables credentialed Axios requests; it still persists the access JWT until the later session cutover.
- New review emails use expiring hashed capability rows. Existing plaintext ticket tokens remain isolated behind the legacy verifier and are not modified.
- Public attachment resources no longer echo review secrets; a ticket-scoped HttpOnly cookie preserves image delivery.
