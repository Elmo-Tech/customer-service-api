# Legacy ticket import

The importer reads a separately configured legacy database and writes into the existing application database. It does not create another application database or tenant schema. The legacy database user must be read-only.

## Data contract

The source `tickets` table must contain the columns from the original project migrations: identity, ticket number, status, importance, description, customer/company/branch references, audit users, soft deletion, and timestamps. `closed_at`, `real_closed_at`, `token`, `tag_id`, `opened_by_user_id`, and `assigned_to_user_id` are imported when present. Source `ticket_attachments` and `ticket_logs` are optional, but when present their original migration columns are required.

The importer preserves ticket numbers, timestamps, closure fields, soft deletion, legacy review tokens, attachment paths, and logs. Attachment files must already exist on the current public disk. After import, use `attachments:migrate-private` to verify and move their metadata to private delivery without deleting the originals.

## Configuration

Configure these only in the deployed backend environment; do not send credentials through chat or commit them:

```dotenv
LEGACY_DB_CONNECTION=mysql
LEGACY_DATABASE_URL=
LEGACY_DB_HOST=127.0.0.1
LEGACY_DB_PORT=3306
LEGACY_DB_DATABASE=
LEGACY_DB_USERNAME=
LEGACY_DB_PASSWORD=
LEGACY_DB_SOCKET=
LEGACY_MYSQL_ATTR_SSL_CA=
LEGACY_TICKET_SOURCE_KEY=legacy-customer-service
```

`LEGACY_TICKET_SOURCE_KEY` identifies one immutable source snapshot. Do not reuse it for a different database or for source rows changed after import.

## Audit and mapping

Run the read-only inventory first:

```bash
php artisan tickets:audit-legacy \
  --output=tenancy/legacy-ticket-audit.json
```

The file is written under `storage/app`. It contains source IDs, target candidates, counts, and a mapping skeleton. It excludes passwords, PINs, review tokens, attachment contents, and ticket descriptions. Because names and emails are mapping clues, keep the report in the approved secure project channel.

Copy [legacy-ticket-mapping.example.json](legacy-ticket-mapping.example.json) outside the repository or use `mappingSkeleton` from the audit. Every source company, branch, customer, audit user, tag, status, and importance used by a ticket must map explicitly to a valid target value. Replace every placeholder `0` before validation.

## Dry-run and execution

Dry-run is the default and does not write target rows:

```bash
php artisan tickets:import-legacy \
  storage/app/tenancy/legacy-ticket-mapping.json
```

It blocks on missing mappings, unsupported enums or schemas, duplicate ticket numbers, changed prior imports, cross-company relationships, invalid account scope, unsafe attachment paths, or missing attachment files.

Execution requires an explicit backup/restore and source-freeze confirmation:

```bash
php artisan tickets:import-legacy \
  storage/app/tenancy/legacy-ticket-mapping.json \
  --execute --confirm
```

All new tickets, attachment metadata, logs, and import-ledger rows are written in one target transaction. A failure rolls back that execution. `legacy_ticket_imports` records the source key, source ticket ID, target ticket ID, and a SHA-256 source hash. Repeating the same import skips verified rows; changed source data or a missing target ticket fails closed.

The command never changes or deletes source rows or legacy files. Do not run `--execute` until the dry-run is clean, the source database is frozen or snapshotted, the target database is backed up, and restore has been rehearsed.
