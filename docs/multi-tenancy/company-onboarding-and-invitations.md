# Company Onboarding and Account Invitations

## Contracts

`POST /api/v1/admin/companies/onboard` requires JWT authentication, an internal account, and `onboard_company`. It creates a company, request-defined branches, a company owner, optional tenant accounts, role assignments, audit events, and password-setup invitations in one transaction. The response contains the company ID and invitation count, never invitation credentials.

`GET /api/v1/admin/companies/onboarding-options` has the same access requirements and returns only the four tenant role IDs and names. Platform roles are excluded. The backend independently verifies every submitted role ID.

Branch-enabled account assignments use request-local branch keys. A key must resolve to a branch created for the new company. Branch managers and employees require a branch. Branchless requests reject branches and branch assignments. The owner must use `company_owner`; optional accounts cannot use that role or any platform role.

## Invitation lifecycle

Invited users are tenant-classified and inactive. Their initial password value is unique random material and is never returned or emailed. `account_invitations` stores a selector, SHA-256 secret hash, purpose, expiry, consumption, revocation, delivery-attempt, and creator metadata. Only the raw one-time `selector.secret` token appears in the queued setup email URL.

`POST /api/v1/account-invitations/setup` accepts the token plus a confirmed strong password. Consumption locks the invitation row, verifies the hash, purpose lifecycle, active company, and active assigned branch, then activates the user and consumes the invitation in one transaction. Expired, revoked, consumed, malformed, altered, deleted-user, inactive-company, and inactive-branch attempts fail closed.

Internal callers with `onboard_company` can call:

- `POST /api/v1/admin/account-invitations/{invitationId}/resend`
- `POST /api/v1/admin/account-invitations/{invitationId}/revoke`

Resend revokes pending predecessors before issuing a new secret. Setup and resend are rate limited. `AccountInvitationMail` is queued with Laravel's verified after-commit support. A queue/transport failure leaves the committed pending invitation available for resend.

## Audit events

`tenant_audit_events` records `company.created`, `branch.created`, `role.assigned`, `account.invited`, `account.invitation_resent`, and `account.invitation_revoked`. Metadata contains IDs only. Invitation selectors, raw secrets, hashes, passwords, PINs, and environment values are excluded.

## Resource exports

The following streamed CSV routes use their existing authorized list query and filters:

- `GET /api/v1/admin/users/export`: `export_users` or `all_users`
- `GET /api/v1/admin/customers/export`: `export_customers` or `all_customers`
- `GET /api/v1/admin/companies/export`: `export_companies` or `all_companies`
- `GET /api/v1/admin/tickets/export`: `export_tickets` or `all_tickets`

Every text cell beginning with optional whitespace followed by `=`, `+`, `-`, or `@` receives an apostrophe prefix. Hidden IDs, passwords, PINs, tokens, hashes, and soft-deleted rows are not exported. Frontend `xlsx` and `file-saver` dependencies and client-side workbook generation were removed.

## Configuration and rollback

- `ACCOUNT_INVITATION_TTL` defaults to 1440 minutes.
- `ACCOUNT_INVITATION_FRONTEND_URL` defaults to `https://tickets-sys.testingelmo.com/setup-password`.

Both new tables are additive and reversible. Application rollback may stop onboarding, setup, resend, revoke, and resource export traffic while retaining rows for investigation. Do not drop invitation or audit tables after invitations have been issued without an approved retention/export decision. Individual company, branch, customer, and user endpoints remain unchanged.
