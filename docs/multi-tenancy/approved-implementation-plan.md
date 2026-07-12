# Approved Multi-Tenant Implementation Plan

## Scope

- Backend: `/Users/mahmoudsaber/Code/Elmo-Tech/customer-service-api`
- Frontend: `/Users/mahmoudsaber/Code/Elmo-Tech/Untitled/ticket-system`
- Architecture: one existing database with additive row-level tenancy.

Tenant isolation is invariant: permissions determine actions; authenticated tenant context determines rows. Client fields, routes, cookies, filters, and hidden controls are never authorization inputs.

## Target model

- `users.account_type` explicitly distinguishes internal and tenant accounts. Tenant accounts without a valid active company fail closed.
- `users.company_id` identifies a tenant account's company. `users.branch_id` is nullable while the product supports one optional assigned branch.
- `companies.uses_branches` controls whether branches are required.
- Existing `tickets.company_id` remains the authoritative tenant key after integrity verification.
- `tickets.opened_by_user_id` records authenticated submitters; legacy `customer_id` remains during migration.
- Roles and permissions remain global templates in Phase 1. Policies and tenant queries independently restrict rows.

## Delivery order

1. Characterize isolation with two-company tests, including direct-ID and altered-filter attacks.
2. Add nullable columns, foreign keys, relationships, indexes, and fail-closed tenant context.
3. Add policies and authorized base queries for every tenant-sensitive resource.
4. Secure routes, writes, selects, logs, attachments, exports, authentication, and review links.
5. Backfill verified mappings without inferring unknown tenant ownership.
6. Move the frontend from cookie claims to `/me` capabilities and authenticated submission.
7. Add onboarding and dashboard queries using the same authorized ticket scope.
8. Remove legacy paths only after migration, verification, and an observation window.

## Compatibility rules

- Expand, migrate, then contract. New columns begin nullable and legacy columns remain readable.
- Existing API keys stay available until the frontend replacement is verified.
- Existing ticket numbers, emails, review records, filters, and file references are preserved.
- Unknown account or row mappings are reported and denied tenant elevation; they are never guessed.
- No destructive migration, table drop, data deletion, or irreversible rewrite is permitted.

## Release blockers

- Any cross-tenant response, mutation, option, aggregate, export, log, or attachment access.
- Any authenticated tenant account without an explicit valid tenant context.
- Any unexplained row-count change or orphan introduced by migration.
- Any touched frontend JS/JSX file above 250 physical lines.
- Any failed phase test, build, lint, clean-code guard, or rollback check.
