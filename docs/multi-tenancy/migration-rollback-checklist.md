# Migration and Rollback Checklist

## Before each migration

- [ ] Confirm one-database connection and engine/version.
- [ ] Take and restore-test a database backup.
- [ ] Record schema version and counts for all existing tables.
- [ ] Record company, branch, user, customer, ticket, attachment, log, role, and permission mappings.
- [ ] Report orphan and cross-company ticket relationships.
- [ ] Inventory active JWTs and unconsumed review links.
- [ ] Rehearse against a production-sized copy.

## Additive deployment

- [ ] Add only nullable/defaulted columns and non-destructive indexes.
- [ ] Preserve existing foreign keys, columns, records, soft-deleted rows, and file paths.
- [ ] Deploy code that reads both legacy and new identity fields.
- [ ] Classify every user explicitly; do not infer tenant ownership from role names alone.
- [ ] Backfill only verified company, branch, opener, and customer/user links.
- [ ] Quarantine unresolved mappings from tenant access and report them.
- [ ] Compare counts and integrity reports before and after every backfill batch.

## Rollout gates

- [ ] Old frontend works against additive backend responses.
- [ ] New tenant policy tests pass for two companies and branchless behavior.
- [ ] Frontend refresh/bootstrap, route capability, dashboard, streamed export, and authenticated submission browser checks pass on staging.
- [ ] Onboarding and invitation tables migrate and roll back on a MySQL-connected rehearsal without affecting existing rows.
- [ ] Queue worker and mail transport deliver after-commit invitation mail; resend is verified after a simulated delivery failure.
- [ ] `onboard_company` and resource export permissions are assigned only through the approved role mapping.
- [ ] No public storage URL bypasses attachment authorization before tenant rollout.
- [ ] `/me` and authenticated submission work; confirm public PIN submission remains disabled.
- [ ] New review capabilities work before revoking legacy links.
- [ ] Dashboard and export totals match authorized ticket lists.

## Rollback

- Prefer roll-forward fixes after data is written; do not drop populated additive columns.
- Application rollback may return to the previous release while compatibility reads remain.
- If tenant context is invalid, fail closed and disable the affected endpoint rather than restoring global visibility.
- Restore from backup only after recording the failed deployment state and reconciling external side effects such as emails and files.
- Contract migrations are separate releases after the observation window and require a fresh backup and zero-violation integrity report.

## Legacy attachment migration runbook

New uploads use the private `ticket_attachments` disk. Existing attachment rows retain `storage_disk=public` until their files are copied and verified. Both delivery routes pass through the application; neither response contains a storage URL.

Run the read-only audit first in a securely connected deployed/staging backend using the existing shared database and storage volumes:

```bash
php artisan attachments:migrate-private
```

Review only the aggregate `would_migrate`, `already_migrated`, `missing`, `mismatched`, and `failed` counts. The command intentionally omits paths and tenant data. It exits non-zero when missing, mismatched, or failed files exist. Resolve them before execution.

After backup/restore evidence and a MySQL-connected rehearsal, copy and switch verified rows with:

```bash
php artisan attachments:migrate-private --execute
```

Execution is idempotent. For each active attachment, it copies from the legacy public disk only when needed, compares byte size and SHA-256 checksum, and transactionally switches that row to private metadata only after verification. Existing private files with a different checksum are reported as mismatched and are not overwritten. Missing and failed files remain public in metadata. Legacy public originals are never deleted by this command.

Rollback the application release without reverting populated attachment metadata: compatibility delivery and email code read both `public` and `private` locations. Do not roll back the additive columns after any row is switched. Physical deletion of public originals requires a later separately approved cleanup after production mapping, backup/restore proof, MySQL rehearsal, successful repeat audits, and an observation period.

## Reporting rollout gate

- [ ] Explicitly map canonical `export_tickets` and `view_ticket_dashboard`; preserve `all_tickets` compatibility during rollout.
- [ ] Compare list, export, and dashboard totals with identical filters for internal, company, branch, and employee fixtures.
- [ ] Confirm CSV filename, formula neutralization, hidden-field exclusions, and bounded query behavior.
- [ ] Confirm branchless dashboards return an empty branch series.
- [ ] Roll back only application use of the additive routes if required; do not delete permission records or change ticket data.

## Session and review rollout gate

- [ ] Verify production/staging HTTPS and exact credentialed CORS origins.
- [ ] Verify reverse-proxy, CDN, and analytics logs redact review `token` query parameters.
- [ ] Verify refresh and review cookies are Secure, HttpOnly, `SameSite=None`, host-only, and use their documented paths.
- [ ] Rehearse rotation/replay, account disable, logout, capability expiry/revocation/consumption, and rate limits.
- [ ] Run `php artisan review-capabilities:audit-legacy` and record sanitized counts.
- [ ] Do not hash/migrate legacy review tokens without authoritative purpose and expiry decisions.
- [ ] Do not roll back populated refresh/capability tables; stop issuance and roll forward.

## Immediate stop conditions

- Cross-tenant access or mutation.
- Unexplained record-count changes.
- Unclassified users treated as internal.
- Orphans or mismatched company relationships introduced by migration.
- Failed restoration rehearsal, migration test, build, or required verification.
