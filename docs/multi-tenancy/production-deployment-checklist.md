# Production Deployment Checklist

Production is not approved until every required item below has evidence attached.

## Data and database

- [ ] Complete and validate the authoritative production user classification mapping.
- [ ] Capture a verified backup and perform a restore rehearsal.
- [ ] Rehearse all additive migrations and rollbacks against a MySQL-connected production-like copy.
- [ ] Inventory row counts and relationships before and after rehearsal: companies, branches, customers, tickets, attachments, logs, users, roles, permissions, refresh sessions, review capabilities, invitations, and audit events.
- [ ] Confirm no database, schema-per-tenant, destructive migration, legacy file deletion, or data rewrite is introduced.

## Permissions and application rollout

- [ ] Create and explicitly assign canonical `onboard_company`, `export_users`, `export_customers`, `export_companies`, `export_tickets`, and `view_ticket_dashboard` permissions.
- [ ] Confirm platform roles are never assigned to tenant accounts and tenant roles are never assigned to internal accounts through onboarding.
- [ ] Verify internal, company owner/manager, branch manager, employee, and branchless accounts against the access matrix.
- [ ] Compare list, export, and dashboard totals for the same authorized filters.
- [ ] Verify direct-ID, altered filter, soft-delete, cross-company customer/branch/user, attachment-parent, and export attacks fail closed.

## Sessions, cookies, invitations, and email

- [ ] Validate HTTPS cookie domain/path/Secure/HttpOnly/SameSite behavior for refresh and review cookies behind the production proxy.
- [ ] Confirm proxy and application logs redact refresh, review, and invitation credentials and request bodies containing setup tokens or passwords.
- [ ] Exercise refresh rotation/replay, logout, disabled user/company/branch, and concurrent 401 behavior in staging.
- [ ] Run a real queue worker and SMTP test proving invitation mail is dispatched after commit.
- [ ] Simulate mail failure, then resend and consume only the replacement invitation.
- [ ] Verify invitation expiry, revocation, replay, active-state revalidation, and concurrent consumption on MySQL.

## Frontend and operations

- [ ] Complete the browser checklist in `frontend-session-and-reporting.md`.
- [ ] Verify onboarding step validation, back/forward state preservation, hidden branch stripping, review, success recovery, and field-level server errors.
- [ ] Verify company, branch, team, employee, dashboard, export, setup-password, public review, and legacy PIN pages across supported viewport sizes.
- [ ] Confirm `npm audit --omit=dev` remains clean and the production bundle matches the reviewed artifact.
- [ ] Keep the Vite development server private until the separately approved breaking upgrade resolves its development-only advisories.

## Observation and later cleanup

- [ ] Define the compatibility observation period and rollback owner.
- [ ] Do not delete legacy public attachment copies until backup, migration audit, and observation gates pass.
- [ ] Do not remove legacy ticket review tokens or the public PIN route until migrated replacements are verified and product owners approve contraction.
- [ ] Define invitation and tenant-audit retention before any cleanup migration.
- [ ] Commit, deploy, migrate, and clean up only through separately authorized production change control.
