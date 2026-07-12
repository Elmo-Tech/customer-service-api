# Legacy Ticket Import Boundary

Historical imports and new ticket creation use different identity paths:

- New tickets derive `company_id`, `branch_id`, and `opened_by_user_id` from the authenticated tenant account. They do not accept customer, company, opener, or status authority from the browser.
- Imported historical tickets may retain `customer_id`, original ticket number, timestamps, status, closure dates, branch, tags, attachments, and logs when the source data proves those relationships.

No import command should be implemented from guessed column names. Before import work starts, capture a sanitized source schema and representative rows without passwords, PINs, tokens, attachment bodies, or personal data not needed for mapping. The import design must then define:

1. company and branch mapping;
2. customer/contact mapping;
3. ticket status and importance mapping;
4. timestamp and ticket-number preservation;
5. attachment and log transfer;
6. orphan and cross-company quarantine rules;
7. dry-run counts, idempotency key, rollback, and reconciliation reports.

Production import remains blocked until the source schema is reviewed and the existing database backup/restore gate passes.
