# Roles, Team Filters, and SLA

## Role templates

`GET /api/v1/admin/roles/matrix` requires an authenticated internal account with `all_roles`. It returns the server-owned templates for the internal administrator and four tenant roles. The Roles page presents the matrix as read-only.

Seeded system roles are fixed security templates. They cannot be changed or deleted through the role API. Re-running `RolesAndPermissionsSeeder` restores the exact tenant permission sets and removes injected permissions. Custom internal roles remain creatable and editable with validated permission names.

## Team filters

The Team list accepts `filter[company]` and `filter[branch]` in addition to search, status, and role. Filters apply after `TenantContext::scopeUsers`. An internal administrator can select an active company and one of its active branches. A tenant caller cannot widen its scope by altering either filter.

## SLA contract

New tickets receive a deadline from their importance:

| Importance | UI label | Target |
| --- | --- | ---: |
| `0` | Green | 72 hours |
| `1` | Yellow | 24 hours |
| `2` | Red | 8 hours |

The additive ticket fields are `due_at` and `escalated_at`. Existing tickets remain `null`; migration does not infer historical deadlines. A normal edit preserves both fields. Changing importance recalculates the deadline. Reopening a completed ticket starts a new SLA window. Both explicit actions clear the prior escalation state.

The authorized dashboard reports `overdue`, `dueSoon`, and `escalated` KPIs plus at most ten active `slaAlerts`. These values use the same authorized ticket query as the ticket list and existing dashboard metrics.

## Escalation operation

The scheduler runs this command every five minutes with overlap prevention:

```bash
php artisan tickets:escalate-overdue
```

The command locks each candidate, writes `escalated_at` once, and queues `TicketSlaEscalated`. Recipients are active company owners and company managers, the matching branch manager when applicable, and the active assigned user. Other-company and other-branch tenant users are excluded. Re-running the command does not send the same ticket again.

Production requires the Laravel scheduler and queue worker. Verify the real mail transport, worker retry policy, failed-job monitoring, application timezone, and one escalation per ticket in staging before enabling the schedule.

## Deployment and rollback

1. Back up the database and prove restore.
2. Rehearse the additive migration on a MySQL copy.
3. Deploy while the scheduler remains paused.
4. Run migrations and `RolesAndPermissionsSeeder` after reviewing production role assignments.
5. Verify Team filters, role matrix, dashboard scope, queue, and mail in HTTPS staging.
6. Start the scheduler only after those checks pass.

Rollback pauses the scheduler, rolls back only `2026_07_12_003000_add_sla_fields_to_tickets`, and deploys the prior application version. This removes only the two nullable SLA columns and indexes.
