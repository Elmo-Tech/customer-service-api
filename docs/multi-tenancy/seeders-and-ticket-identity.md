# Seeders and Ticket Identity

## Seeder behavior

`RolesAndPermissionsSeeder` is idempotent. It creates or updates the global permission catalogue, keeps the internal `مدير` role fully privileged, and adds safe tenant defaults for `company_owner`, `company_manager`, `branch_manager`, and `employee`. Re-running the seeder adds required defaults without deleting permissions explicitly assigned later through role management.

`UserSeeder` contains no real person, email, phone, address, or hard-coded password. It reads the optional `SEED_ADMIN_*` configuration. When any required value is missing, it creates no user and reports that bootstrap configuration is incomplete. Re-running it does not reset an existing configured administrator's password.

Production user classification still uses the authoritative mapping commands. The bootstrap user seeder is intended for a new installation, not as a replacement for mapping imported users.

## Ticket requester identity

New ticket submission requires authentication. The API derives:

- `company_id` from the tenant account;
- `opened_by_user_id` from the authenticated user;
- fixed branch from branch-scoped accounts, or a validated selected branch for company-wide accounts;
- open status server-side.

The browser cannot choose a customer, company, opener, or status. The former public PIN endpoint is removed.

Historical tickets may retain nullable `customer_id`. Ticket resources, notifications, review pages, dashboards, and exports display the historical customer when present and otherwise use the authenticated opener. Existing PIN values remain stored only for data preservation and import reconciliation; new APIs do not return them or require them for customer contacts.
