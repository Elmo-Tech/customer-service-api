# Final Local Readiness Report

## Outcome

The multi-tenant implementation is complete against local SQLite fixtures. It is not approved for production deployment. The production shared MySQL database remains the only production database; SQLite was used only for development tests and migration rehearsal.

## Verified locally

- Backend: 89 tests and 407 assertions pass.
- Frontend: 9 focused Node tests pass; ESLint and the Vite production build pass.
- Phase PHP files pass Pint. Repository-wide Pint reports 74 historical style violations in unrelated legacy files and migrations; no bulk reformat was performed.
- Fresh SQLite migration through `2026_07_12_001000_create_tenant_audit_events` passes. Seven-step rollback removes the two onboarding tables and the five earlier additive tenancy migrations without touching legacy migrations.
- `git diff --check` passes in both repositories.
- All authored frontend JS/JSX files are at most 215 physical lines.
- Production dependency audit reports zero vulnerabilities. Full audit reports three Vite/esbuild development-server findings that require a breaking Vite upgrade.
- Production build remains route-split. The largest shared bundle is approximately 715 KB (238 KB gzip); Vite retains its greater-than-500-KB warning.
- `xlsx`, `file-saver`, readable credential-cookie authority, client fetch-all ticket export, and unsafe ticket-description HTML rendering are removed.

## Security-boundary review

All authenticated endpoints use the existing JWT authentication middleware, which now rechecks active user, company, and assigned branch state on every authenticated request. Permissions determine actions. `TenantContext`, authorized resource services, parent-ticket attachment checks, or internal-only middleware determine rows and administration scope.

Route review covers 50 API routes. Admin company, branch, customer, user, role, ticket, attachment, log, selector, export, dashboard, onboarding, resend, and revoke routes require authentication and their documented permissions. Public login, refresh, invitation setup, ticket review, review attachment, and review submission are purpose-limited and rate limited where applicable. Public PIN ticket submission remains disabled.

Onboarding and invitation tests cover branch-enabled and branchless success, transaction rollback, duplicate identity, platform-role denial, tenant and permission denial, expiry, replay, revocation, resend predecessor revocation, cross-tenant resend denial, Team creation, inactive company, inactive branch, response/audit redaction, and queued after-commit mail. Resource export tests cover tenant scope, permission denial, formula neutralization, and cross-tenant exclusion.

## Compatibility and data preservation

The latest additive migration creates only the `legacy_ticket_imports` ledger. Existing company, branch, customer, ticket, attachment, log, user, role, permission, email, filter, review, and export data is not rewritten or deleted by migration. Legacy ticket execution is a separate explicit command guarded by mapping, dry-run, `--execute`, and `--confirm`. The frontend onboarding, companies/branches, team, setup-password, reporting, and employee routes use the existing architecture.

Intentionally deferred contractions:

- legacy public attachment copies remain until the observation and backup gate;
- legacy plaintext ticket review-token column and compatibility verifier remain until authoritative expiry/purpose migration is possible;
- historical customer/PIN columns remain available for data import, but public PIN ticket submission is disabled;
- canonical reporting permissions must be assigned before deployment; `all_tickets` does not grant dashboard or export access;
- old invitation/audit retention cleanup has no destructive migration and requires a retention decision;
- repository-wide legacy formatting cleanup and the breaking Vite upgrade are separate maintenance work.

## Unresolved local limitations

No HTTPS browser session was available for end-to-end cookie, refresh, review, wizard, download, and responsive visual verification. SQLite cannot prove MySQL DDL, locking, collation, query-plan behavior, or the connected legacy import. Queue transport failure/retry was covered by persistent invitation design and Mail fakes, not a real worker/SMTP staging exercise. These remain production gates.
