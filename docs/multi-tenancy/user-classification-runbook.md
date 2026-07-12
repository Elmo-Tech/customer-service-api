# User Classification Audit and Mapping Runbook

These commands query the existing single database. They do not update account rows, roles, companies, branches, customers, or tickets. No new database, schema, or database-per-tenant setup is required.

## Option A: provide task-environment connectivity

Provide network access and valid application database configuration to the task environment. Do not paste database passwords, JWT secrets, or other credentials into chat. Once connectivity works, the commands below can be run here and only the sanitized artifacts need review.

Both commands require connectivity to the existing database. The audit must run on staging, the deployed backend, or another securely connected environment using that database.

## Option B: run the read-only export

From the backend repository root in a securely connected environment, prepare the working directory and mapping copy:

```bash
mkdir -p storage/app/tenancy
cp docs/multi-tenancy/user-classification-mapping.csv storage/app/tenancy/user-classification-mapping.csv
```

Run the read-only audit on staging first:

```bash
php artisan tenancy:audit --output=storage/app/tenancy/tenant-audit.json
```

Inspect the JSON before transfer. It contains only:

- user IDs, usernames, emails, display names, statuses, current nullable tenant fields, role names, and deletion timestamps;
- role IDs, names, and guards;
- company and branch IDs, names, statuses, branch mode, and deletion timestamps;
- customer IDs, names, emails, company IDs, optional linked user IDs, statuses, and deletion timestamps;
- ticket counts grouped by company, branch, and customer IDs.

It does not query or export password hashes, PINs, review tokens, access tokens, JWT secrets, environment values, or attachment paths.

## Complete the authoritative mapping

Complete `storage/app/tenancy/user-classification-mapping.csv` and add exactly one row for every user in the audit:

```text
user_id,username,email,account_type,company_id,branch_id,intended_role,mapping_authority_notes
```

Rules:

- `account_type` is exactly `internal` or `tenant`.
- Internal rows have empty `company_id` and `branch_id`.
- Tenant rows have an active `company_id`; `branch_id` may be empty only when the authoritative assignment is company-wide or branchless.
- `intended_role` is an existing API role name from the audit.
- Tenant accounts use one of `company_owner`, `company_manager`, `branch_manager`, or `employee`; internal accounts cannot use those tenant roles.
- `mapping_authority_notes` names the person or business source that approved the classification. Do not use role names, email domains, `created_by`, or other inferred clues as authority.

## Validate without writes

Validation also queries current user identities, roles, companies, and branches, so it must run in an environment securely connected to the same existing database. Run against staging, then production using the completed CSV path:

```bash
php artisan tenancy:validate-mapping storage/app/tenancy/user-classification-mapping.csv
```

Successful output is:

```text
Mapping is valid. Dry-run completed without database writes.
```

Any failure exits non-zero and identifies the rejected rows or missing users. Correct the CSV and rerun; do not edit account rows manually.

## Apply the validated mapping

Application is a separate, explicit production change. Run the command without `--execute` first; it validates again and performs no writes:

```bash
php artisan tenancy:apply-mapping storage/app/tenancy/user-classification-mapping.csv
```

After backup/restore evidence and review of the completed CSV, apply every row atomically:

```bash
php artisan tenancy:apply-mapping \
  storage/app/tenancy/user-classification-mapping.csv \
  --execute
```

The command asks for confirmation, locks the mapped users, assigns account scope and the authoritative role inside one transaction, and writes redacted tenant audit events. Validation, identity drift, role, or database errors roll back the entire operation.

## Sanitized artifacts needed to unblock enforcement

Return both files through the approved secure project channel:

1. `tenant-audit.json` from the read-only export.
2. The completed and successfully validated `user-classification-mapping.csv`.

Do not return `.env`, database dumps, logs containing authorization headers, passwords, hashes, PINs, JWT secrets, or review tokens.
