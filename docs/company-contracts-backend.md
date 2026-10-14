# Company Contracts: Deployment and Internal APIs

## Deployment

Deploy this branch's code before running the following commands on the application server.
The new migration only creates `company_contracts`; it does not change existing company legacy settings.
Back up the database using the usual deployment procedure first.

```bash
php artisan migrate --path=database/migrations/2026_10_14_000000_create_company_contracts_table.php --force
php artisan db:seed --class="Database\Seeders\Roles\CompanyContractPermissionsSeeder" --force
php artisan route:clear
php artisan config:clear
php artisan route:list --path=company-contracts
```

Do not rerun the main roles seeder on an existing database: it creates the manager role again.
The dedicated contract permissions seeder above is repeatable and preserves existing roles and permissions.
Rebuild route/config caches afterwards if that is part of your normal deployment.
Deploy the matching frontend because company pagination and validation responses have changed.

## Permissions

All routes use `auth:api` and Spatie permissions with guard `api`.

| Permission | Operations |
| --- | --- |
| `all_company_contracts` | Contract index and show |
| `create_company_contract` | Standalone create and renew |
| `update_company_contract` | Update |
| `delete_company_contract` | Delete |

The seeder grants these permissions only to the existing manager role named `مدير`, if present.
Assign them explicitly to any other roles that need contract management through the existing role module.
Existing company permissions remain unchanged. `create_company` authorizes the entire bundled company creation,
including its customer, internal branch and optional contract, without requiring separate module permissions.

## Internal Branch APIs

These endpoints remain in the backend; the company frontend must not call them or send branch keys.
Base prefix: `/api/v1/admin`. Existing branch permissions apply.

| Method and route | Required input |
| --- | --- |
| `POST /branches/create` | `companyId`, `name`, `status` |
| `GET /branches/edit` | `branchId` |
| `PUT /branches/update` | `branchId`, `name`, `status` |
| `DELETE /branches/delete` | `branchId` |

POST/PUT use JSON; GET/DELETE use query parameters. Status is 0 or 1.
Update cannot move a branch to another company, even if a caller supplies companyId.
All mutations retain their existing message-only responses with HTTP 200.
Edit returns `{ "branchId": 3, "name": "Arca Pro", "status": 1 }`.
Invalid fields return 422; missing/deleted branches or parent companies return 404.
Deletion now reads camelCase `branchId`, not the former `BranchId` spelling.

Company create makes one branch named exactly after the company, with its status.
Company update synchronizes the oldest surviving branch's name/status, preserving other branches.
If no branch survives, update creates one. Historical tickets can still resolve soft-deleted companies/branches.

## Integrity and Verification

- Company, branch, customer and optional contract creation are one database transaction.
- Contract writes lock the company row first and check active contracts under that lock.
- Company deletion and branch writes follow the same parent-first locking order.
- Use MySQL/InnoDB in deployment for row-level locking. SQLite tests do not verify production concurrency.
- Company index paginates in SQL and eager-loads active/latest contracts without per-company queries.
- Contract history includes soft-deleted records but never exposes deletion metadata.
- No server deployment, production migration, commit or push was performed as part of implementation.

Tests: `tests/Feature/CompanyContractsApiTest.php`, plus the existing full suite.
The legacy test setup supplies a test-only SQLite CONCAT equivalent for the unchanged MySQL query.
The PHPUnit XML deprecation warning is pre-existing and unrelated to this feature.

Frontend contracts: [Arabic](companies-branches-contracts-api.ar.md), [English](companies-branches-contracts-api.md).
