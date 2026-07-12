# Access and Tenant Permission Matrix

Every allowed cell requires both the named action permission and the row scope. A permission never widens tenant scope.

| Account scope | Companies | Users/customers | Tickets | Branches | Roles |
|---|---|---|---|---|---|
| Platform admin | All rows when permitted | All rows when permitted | All rows when permitted | All rows when permitted | Global templates when permitted |
| Internal support | Read only as permitted | Internal or explicitly permitted tenant rows | All or assigned rows per approved permission | Read for permitted workflow | No management unless permitted |
| Company owner/manager | Own company | Own company | Own company | Own company | Assign approved tenant templates only |
| Branch manager | Own company summary | Own branch | Own branch | Assigned branch | Assign approved branch roles only |
| Employee | Own company summary only | Self | Own opened tickets | Assigned branch summary only | None |

## Resource rules

- Companies: tenant accounts cannot select, show, update, or infer another company.
- Branches: a branch must belong to the authenticated tenant company. Branch managers are forced to their assigned branch.
- Users: tenant-created or modified users remain in the caller's authorized company/branch. Internal classification cannot be assigned by tenant users.
- Company onboarding: internal accounts require `onboard_company`; tenant accounts cannot invoke onboarding even if a permission is injected.
- Invitations: internal `onboard_company` callers may resend or revoke; public setup is limited to the one-time purpose-bound capability.
- Customers: legacy contacts remain company-scoped. PIN is never returned by new responses.
- Tickets: `company_id` and opener derive from authenticated context. Referenced branch, customer, opener, and assignee must belong to that company.
- Logs and attachments: inherit scope exclusively through the parent ticket.
- Selects: return only active, authorized options needed for the specific action.
- Exports and dashboard: reuse the exact authorized ticket base query and permitted filters.
- Public review: access is limited to one ticket by a valid, unexpired, single-purpose capability.

## Response behavior

- Missing authentication: 401.
- Authenticated but action forbidden: 403.
- Tenant record outside authorized row scope: 404 to limit identifier disclosure.
- Tenant account with missing/inactive company context: 403 and no resource query.
- Branch-enabled workflow missing a required authorized branch: 422.

## Reporting permission compatibility

- Export requires canonical `export_tickets`.
- Dashboard requires canonical `view_ticket_dashboard`.
- An employee with `all_tickets` and `create_ticket` can submit tickets and list only tickets they opened. It cannot access dashboard aggregates, exports, contacts, team administration, companies, branches, or roles without separately assigned canonical permissions and authority.
- Canonical assignments must be explicit. Role names never imply a grant.
- Action checks remain independent from `AuthorizedTicketQuery` row scope.
