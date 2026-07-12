# Ticket Reporting API Contracts

## Shared authorization and filters

Ticket list, export, and dashboard call `AuthorizedTicketQuery::filtered()` before reading rows. Account scope is applied first: internal permitted users can see all tickets, company owners/managers remain in their company, branch managers remain in their assigned branch, and employees remain limited to tickets they opened. Request filters only narrow that base query.

All three APIs accept the same `filter[...]` query keys:

| Filter | Definition |
|---|---|
| `search` | Database `LIKE` match against `ticket_number`, matching existing list behavior. |
| `status` | Exact status except `0` (`OPENED`), which preserves the existing rule and includes `OPENED` plus `REOPENED`. |
| `importance` | Exact persisted importance enum value. |
| `company` | Exact `company_id` after account scope. Tenant callers cannot widen their company. |
| `branch` | Exact `branch_id` after account scope. Branch managers cannot widen their branch. |
| `customer` | Exact `customer_id`. |
| `tag` | Exact `tag_id`. |
| `assignee` | Exact `assigned_to_user_id`. |
| `fromDate` | Inclusive `Y-m-d` lower boundary on `real_closed_at`, preserving existing list date meaning. |
| `toDate` | Inclusive `Y-m-d` upper boundary on `real_closed_at`; it cannot precede `fromDate`. |

Unknown filters and invalid enum, ID, or date values return `422`. Missing authentication returns `401`; missing action permission returns `403`.

## Server-side CSV export

`GET /api/v1/admin/tickets/export`

Required permission: canonical `export_tickets` or legacy-compatible `all_tickets`. The response is a streamed UTF-8 CSV attachment named `tickets-YYYY-MM-DD.csv`.

Columns are limited to Ticket Number, Customer, Company, Branch, Status, Importance, Description, Created At, and Closed At. Internal IDs, tenant foreign keys, PINs, review tokens, attachment paths, user identity fields, and soft-deleted tickets are not exported. Rows are iterated in bounded chunks with customer, company, and branch eager loading.

HTML is stripped from descriptions. A text cell whose first non-space character is `=`, `+`, `-`, or `@` is prefixed with an apostrophe to prevent spreadsheet formula execution.

## Dashboard aggregates

`GET /api/v1/admin/tickets/dashboard`

Required permission: canonical `view_ticket_dashboard` or legacy-compatible `all_tickets`. The stable response envelope is:

```json
{
  "data": {
    "kpis": {},
    "series": {
      "createdVsClosed": [],
      "statusDistribution": [],
      "importanceDistribution": [],
      "branchVolume": []
    },
    "oldestOpen": [],
    "recentActivity": []
  }
}
```

Metric definitions:

- `total`: count of the authorized, filtered query.
- `open`: persisted `OPENED` (`0`) rows. This card does not combine reopened rows even though input status `0` intentionally includes both.
- `inProgress`: persisted `IN_PROGRESS` (`2`) rows.
- `closed`: persisted `DONE` (`1`) rows.
- `reopened`: persisted `REOPENED` (`3`) rows.
- `averageResolutionHours`: average elapsed hours from `created_at` to non-null `real_closed_at`, rounded to two decimals; `null` when no filtered row has a resolution timestamp.
- `createdVsClosed`: entries for dates present in the filtered population. `created` groups `created_at`; `closed` groups non-null `real_closed_at`.
- `statusDistribution` and `importanceDistribution`: one entry per current enum case, including zero counts. Entries contain `key`, numeric `value`, and `count`.
- `branchVolume`: counts non-null `branch_id` values and returns branch ID/name. Branchless companies receive an empty series; no synthetic branch is created.
- `oldestOpen`: up to ten oldest `OPENED` or `REOPENED` rows.
- `recentActivity`: up to ten rows ordered by most recent `updated_at`.

No overdue KPI is returned because the ticket model has no due date or service-level deadline. Deriving one from age would invent product meaning.

## Deployment and rollback

This phase adds routes and seed catalog entries but no database migration. Before enabling the new frontend, add and assign canonical `export_tickets` and `view_ticket_dashboard` permissions through the approved role-mapping process. Existing users with `all_tickets` retain compatibility during rollout. Do not infer new grants from role names.

Rollback is application-only: stop using the two new routes and continue using the unchanged ticket list. Leaving canonical permission records in place avoids destructive cleanup. Compare dashboard totals and export row counts with the identically filtered list before promotion.
