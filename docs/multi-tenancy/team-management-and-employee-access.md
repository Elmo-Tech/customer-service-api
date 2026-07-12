# Team management and employee access

## Product distinction

`/dashboard/admins` is the Team page. Its records are login accounts and expose the assigned role, company, branch, and status. A tenant owner or manager sees only accounts authorized by `TenantContext`. An internal administrator can create a tenant account after onboarding by selecting a tenant role, company, and—when required—an active branch in that company.

`/dashboard/users` is the Contacts page. Its records use the customer endpoints and are retained for legacy contact data; they are not application login accounts.

## Account creation after onboarding

`POST /api/v1/admin/users/create` accepts optional `companyId` and `branchId` fields. For a tenant caller, the backend ignores company selection and derives the caller's company. For an internal caller assigning a tenant role, `companyId` is required and must identify an active company. `branch_manager` and `employee` require an active branch when that company uses branches. Company associations remain immutable on update.

The Team form follows those rules. Tenant callers do not receive a company selector. Branchless companies do not receive a branch selector. Changing a role clears only an incompatible branch selection; it does not reset the other account fields.

New Team accounts use the existing one-time invitation flow. The create request sends `invite=true`, stores a random unusable password, forces the account inactive, hashes the expiring invitation secret, and queues the setup email only after the account transaction completes. The API response never returns the raw token, password, or hash. Direct password creation remains available to compatible API callers only when `invite` is false and a strong password and status are supplied. Pending invitations appear in the Team table with a resend action. Resend and revoke resolve the invited user through `TenantContext`, so tenant administrators cannot address another company's invitation.

## Employee application surface

The seeded `employee` role has `create_ticket` and `all_tickets`. Ticket scope still applies before filters, so the employee sees only tickets they opened. The visible application routes are:

- `/dashboard/submit` for a new authenticated ticket.
- `/dashboard/tickets` for the employee's own tickets.
- The existing profile and logout controls.

`all_tickets` does not grant `GET /api/v1/admin/tickets/dashboard` or `GET /api/v1/admin/tickets/export`. Those endpoints require `view_ticket_dashboard` and `export_tickets`, respectively. Employees do not receive company, contacts, team, branch, role, onboarding, dashboard, or export permissions from the default seeder.

## Local verification

- Backend suite: 101 tests and 483 assertions pass.
- Frontend suite: 9 tests pass; lint and the production build pass.
- Internal post-onboarding creation, immutable company ownership, cross-company branch rejection, employee ticket scope, and reporting denial are covered by feature tests.
- Targeted Pint, both repository diff checks, and the 250-line frontend file gate pass.
