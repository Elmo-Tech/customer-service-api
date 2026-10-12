# Companies, Branches and Contracts: Frontend API Specification

**Status: proposed API specification, not an implementation report.** Company and branch CRUD routes already exist, but the changes below, company `show`, and all contract APIs still need backend implementation. This file describes the intended requests and responses after implementation. Do not assume these examples work on the deployed API yet.

Base URL: `https://customerservicebe.testingelmo.com/api/v1`

## 1. Request Conventions

All endpoints below require an admin access token and the appropriate permissions:

```http
Authorization: Bearer ADMIN_ACCESS_TOKEN
Accept: application/json
Content-Type: application/json
```

- GET and DELETE parameters go in the query string; POST and PUT parameters go in a JSON body.
- Use camelCase keys exactly as shown: `companyId`, `branchId`, `contractId`, `startDate`, `endDate`.
- The admin token is different from the customer dashboard token and ticket tokens.
- Dates are date-only strings in `YYYY-MM-DD` format. Preserve them as strings in date inputs; do not convert them to UTC timestamps.
- The backend's configured application timezone determines today. It must be configured consistently on the deployment; browser time does not determine renewal dates.
- Company and branch `status`: `1` active, `0` inactive.
- Proposed contract `status`: `1` active, `0` expired. This is computed by the backend, never submitted by the frontend.
- List responses use `result` and `pagination`; detail responses are bare objects, without a `data` wrapper. Successful mutations return `message` and, where shown, `data`.
- `page` defaults to `1`, `pageSize` to `10`; proposed accepted page sizes are `1` through `100`.

### Compatibility With Existing Code

The route naming follows the existing `/admin/<module>/create`, `/update`, `/delete`, `/edit` style. Existing company/branch routes will be extended, not replaced.

The proposed changes that frontend integration must account for are:

- Company `show` is new. Existing company `edit` remains available as a compatible details endpoint.
- Company list pagination currently uses `per_page`, `current_page`, `total_pages`. This specification targets `perPage`, `currentPage`, `totalPages`; backend and frontend must coordinate that change.
- Company `edit` currently omits branches. Target details responses include `branches` and `currentContract`.
- Company/branch mutations currently return only a message. The `data` examples below are proposed additive response fields, including newly created IDs.
- Branch deletion currently reads `BranchId`. The target documented key is `branchId`; the backend should temporarily accept the old spelling for existing callers.
- Existing company/branch validation errors currently use HTTP `401` and a `message` error map. Target validation uses `422` and `errors`, as documented below. This change must be deployed together with compatible frontend handling.

## 2. Contract Rules

### Confirmed Requirements

- A contract contains a start date and an end date. No amount, currency, contract number, notes, or uploaded contract file is required.
- A company with an active contract cannot create another contract or renew one. The available operations are updating or deleting its current active contract.
- Expired contracts remain in the company's history.
- Renewal creates an independent contract with a new `contractId`, starting today and having the same duration as the previous contract.
- Renewal does not modify the old record and does not store a `previousContractId`, parent link, or renewal chain.
- The companies table must show the current contract's status, start date, and end date without an extra request per company.

### Proposed Boundary Rules for Implementation

These choices make the examples unambiguous; they are design assumptions, not existing backend behavior:

1. Only an **active, non-deleted** contract blocks create/renew. Existing expired records do not block either operation.
2. Count calendar days inclusively: `durationDays = daysBetween(startDate, endDate) + 1`. A same-day contract lasts one day. Renewal sets `endDate = today + (durationDays - 1)` calendar days. This preserves days, not calendar months or years.
3. The end date is inclusive. A contract remains active throughout its end date and expires the following day. Proposed validation is `startDate <= today` and `endDate >= startDate`; future starts are not accepted in this two-state model. Supporting scheduled contracts would require a separate agreed rule.
4. `currentContract` means the active contract if one exists; otherwise the most recently created non-deleted expired contract. It is `null` if neither exists. This allows the companies table to display an expired contract's dates too.
5. Renewal takes the most recently created non-deleted expired contract for the company. The backend selects it, with `contractId` as the tie-breaker. If none exists, use create instead.
6. Only the active contract can be updated or deleted. Expired history is read-only. Updating dates can cause the active contract to become expired; it then becomes read-only.
7. Proposed deletion is soft deletion: set `deletedAt` and remove the contract from `currentContract` and renewal source selection. Preserve it in history. `status` continues to describe its dates; `deletedAt` separately identifies deletion, not a third contract status.
8. Create, renew and update must enforce the one-active-contract rule on the backend, including simultaneous requests. Disabling buttons alone is insufficient.

Renewal example: an expired contract from `2026-09-01` through `2026-09-30` lasts 30 days. If renewed on `2026-10-12`, the new contract runs from `2026-10-12` through `2026-11-10`. The old September record is unchanged. The examples below assume today is `2026-10-12`.

## 3. Endpoint Summary

All paths below are relative to the base URL.

| Method | Path | Purpose | Availability now |
| --- | --- | --- | --- |
| GET | `/admin/companies` | Paginated companies and current contract summary | Existing, response to be extended |
| GET | `/admin/companies/show` | Company, branches and current contract | New |
| GET | `/admin/companies/edit` | Compatible company details route | Existing, response to be extended |
| POST | `/admin/companies/create` | Create company and branches together | Existing, validation/response to be extended |
| PUT | `/admin/companies/update` | Update company only | Existing, behavior to be adjusted |
| DELETE | `/admin/companies/delete` | Delete company | Existing |
| GET | `/admin/branches/edit` | One branch | Existing |
| POST | `/admin/branches/create` | Add one branch to a company | Existing |
| PUT | `/admin/branches/update` | Update one branch | Existing |
| DELETE | `/admin/branches/delete` | Delete one branch | Existing, key to be normalized |
| GET | `/admin/company-contracts` | Paginated contract history | New |
| GET | `/admin/company-contracts/show` | One contract | New |
| POST | `/admin/company-contracts/create` | Create dated contract | New |
| PUT | `/admin/company-contracts/update` | Change active contract dates | New |
| POST | `/admin/company-contracts/renew` | Create independent contract from previous duration | New |
| DELETE | `/admin/company-contracts/delete` | Soft-delete active contract | New |

## 4. Companies

### 4.1 Index

```http
GET /admin/companies?page=1&pageSize=10
```

Existing optional filters remain: `filter[search]` searches the company name and `filter[status]` accepts `0` or `1`. Company status is separate from contract status. No new contract-status filter is specified.

Response `200`:

```json
{
  "result": {
    "companies": [
      {
        "companyId": 4,
        "name": "Arca Pro",
        "status": 1,
        "legacyTicketEnabled": false,
        "branches": [
          { "branchId": 3, "name": "Main Branch", "status": 1 },
          { "branchId": 8, "name": "Second Branch", "status": 1 }
        ],
        "currentContract": {
          "contractId": 21,
          "companyId": 4,
          "startDate": "2026-10-12",
          "endDate": "2026-11-10",
          "status": 1,
          "deletedAt": null
        }
      }
    ]
  },
  "pagination": { "total": 1, "count": 1, "perPage": 10, "currentPage": 1, "totalPages": 1 }
}
```

Table columns should read `currentContract.status`, `currentContract.startDate`, and `currentContract.endDate`. When `currentContract` is `null`, show no contract and blank dates. An expired contract is returned with `status: 0`, not replaced by a fake active status.

### 4.2 Show / Edit

```http
GET /admin/companies/show?companyId=4
GET /admin/companies/edit?companyId=4
```

`companyId` is required. Target response for either route, `200`:

```json
{
  "companyId": 4,
  "name": "Arca Pro",
  "status": 1,
  "legacyTicketEnabled": false,
  "branches": [
    { "branchId": 3, "name": "Main Branch", "status": 1 },
    { "branchId": 8, "name": "Second Branch", "status": 1 }
  ],
  "currentContract": {
    "contractId": 21,
    "companyId": 4,
    "startDate": "2026-10-12",
    "endDate": "2026-11-10",
    "status": 1,
    "deletedAt": null
  }
}
```

Load full contract history separately through `/admin/company-contracts?companyId=4`; company details do not embed an unbounded history array.

### 4.3 Create Company With Branches

```http
POST /admin/companies/create
```

Request:

```json
{
  "name": "Arca Pro",
  "status": 1,
  "branches": [
    { "name": "Main Branch", "status": 1 },
    { "name": "Second Branch", "status": 1 }
  ]
}
```

Proposed validation: unique non-empty company `name`, valid `status`, and a non-empty `branches` array with valid `name` and `status` for every branch. The backend sets each branch's company; do not send `companyId` or `branchId` inside this array. Company and branch creation succeed or fail together. A contract is created through its separate API after obtaining the new `companyId`.

Response `200`, keeping the existing success status:

```json
{
  "message": "Company created successfully.",
  "data": {
    "companyId": 4,
    "name": "Arca Pro",
    "status": 1,
    "legacyTicketEnabled": false,
    "branches": [
      { "branchId": 3, "name": "Main Branch", "status": 1 },
      { "branchId": 8, "name": "Second Branch", "status": 1 }
    ],
    "currentContract": null
  }
}
```

### 4.4 Update Company

```http
PUT /admin/companies/update
```

Request:

```json
{ "companyId": 4, "name": "Arca Pro Updated", "status": 1, "legacyTicketEnabled": false }
```

`companyId`, `name` and `status` are required. `legacyTicketEnabled` is optional; omission preserves its existing value. Do not submit `branches` or contract fields. Updating the company must no longer rename or change the status of the first branch automatically.

Response `200`:

```json
{ "message": "Company updated successfully." }
```

Refresh company details and the companies table after success.

### 4.5 Delete Company

```http
DELETE /admin/companies/delete?companyId=4
```

No body. Response `200`:

```json
{ "message": "Company deleted successfully." }
```

Retain the existing company soft-delete approach. Deleted companies are excluded from normal index/show, and branch/contract mutations must reject a deleted company. This is not a request to physically delete historical tickets or contract records.

## 5. Branches

### 5.1 Branch Details

```http
GET /admin/branches/edit?branchId=3
```

Response `200`, retaining the current branch resource shape:

```json
{ "branchId": 3, "name": "Main Branch", "status": 1 }
```

### 5.2 Add Branch

```http
POST /admin/branches/create
```

Request, all fields required:

```json
{ "companyId": 4, "name": "Third Branch", "status": 1 }
```

Response `200`:

```json
{
  "message": "Branch created successfully.",
  "data": { "branchId": 9, "name": "Third Branch", "status": 1 }
}
```

### 5.3 Update Branch

```http
PUT /admin/branches/update
```

Request, all fields required:

```json
{ "companyId": 4, "branchId": 9, "name": "Third Branch Updated", "status": 0 }
```

The backend must check that `branchId` belongs to `companyId`; this endpoint is not a branch transfer between companies.

Response `200`:

```json
{ "message": "Branch updated successfully." }
```

### 5.4 Delete Branch

```http
DELETE /admin/branches/delete?companyId=4&branchId=9
```

No body. Target validation checks both IDs and company ownership. Response `200`:

```json
{ "message": "Branch deleted successfully." }
```

Refresh company details after add/update/delete to reload `branches`. Branch deletion should preserve existing ticket records and their ability to render. The existing ticket identification flow requires an active branch; removing or disabling the last active branch currently prevents new identifications, so the UI should explain that consequence. No automatic replacement branch is specified here.

## 6. Contracts

Every contract operation is scoped by `companyId`. For show/update/delete, the contract must belong to that company. The only editable contract fields are `startDate` and `endDate`.

### 6.1 Create Contract

```http
POST /admin/company-contracts/create
```

Request:

```json
{ "companyId": 4, "startDate": "2026-10-12", "endDate": "2026-11-10" }
```

All fields are required. Do not send `status`. An existing active contract produces `409`. Expired history is preserved.

Response `200`, following the current module's mutation success status convention:

```json
{
  "message": "Contract created successfully.",
  "data": {
    "contractId": 21,
    "companyId": 4,
    "startDate": "2026-10-12",
    "endDate": "2026-11-10",
    "status": 1,
    "deletedAt": null
  }
}
```

### 6.2 Update Active Contract

```http
PUT /admin/company-contracts/update
```

Request, all fields required:

```json
{ "companyId": 4, "contractId": 21, "startDate": "2026-10-12", "endDate": "2026-12-10" }
```

The contract must be active and non-deleted before the update. Keep the same `contractId`; this operation edits the current contract rather than creating history. No separate edit-revision history is part of this feature. Future renewal uses this contract's updated duration.

Response `200`:

```json
{
  "message": "Contract updated successfully.",
  "data": {
    "contractId": 21,
    "companyId": 4,
    "startDate": "2026-10-12",
    "endDate": "2026-12-10",
    "status": 1,
    "deletedAt": null
  }
}
```

### 6.3 Renew Contract

```http
POST /admin/company-contracts/renew
```

Request:

```json
{ "companyId": 4 }
```

Do not send dates, `status`, or a previous contract ID. The backend rejects renewal while an active contract exists, selects the previous non-deleted expired contract, calculates its inclusive calendar-day duration, then creates a standalone record starting today. Missing previous contract produces `409`; the frontend should offer create instead.

For the 30-day September example in section 2, response `200`:

```json
{
  "message": "Contract renewed successfully.",
  "data": {
    "contractId": 21,
    "companyId": 4,
    "startDate": "2026-10-12",
    "endDate": "2026-11-10",
    "status": 1,
    "deletedAt": null
  }
}
```

The new ID is distinct from the old contract's ID. Repeated renewal requests must not create overlapping active records; once the first succeeds, subsequent requests return `409`.

### 6.4 Delete Active Contract

```http
DELETE /admin/company-contracts/delete?companyId=4&contractId=21
```

No body. Response `200`:

```json
{ "message": "Contract deleted successfully." }
```

The deleted contract remains in history with a non-null `deletedAt`. It no longer blocks creation and cannot be used as the previous contract for renewal. Refresh company details/index: `currentContract` now becomes the latest non-deleted expired contract, or `null` if none remains.

### 6.5 Contract History

```http
GET /admin/company-contracts?companyId=4&page=1&pageSize=10
```

`companyId` is required. Return the company's contracts newest-created first, including current, expired and soft-deleted records. Contract history means separate dated contracts, not a field-change audit log. No history filters are specified.

Response `200`:

```json
{
  "result": {
    "contracts": [
      {
        "contractId": 21,
        "companyId": 4,
        "startDate": "2026-10-12",
        "endDate": "2026-11-10",
        "status": 1,
        "deletedAt": null
      },
      {
        "contractId": 20,
        "companyId": 4,
        "startDate": "2026-09-01",
        "endDate": "2026-09-30",
        "status": 0,
        "deletedAt": null
      }
    ]
  },
  "pagination": { "total": 2, "count": 2, "perPage": 10, "currentPage": 1, "totalPages": 1 }
}
```

A deleted record has a timestamp such as `"deletedAt": "2026-10-12 14:30:00"`. Show a deletion marker independently of its date-based status. Never enable update/delete/renew actions for that record. With no history, return `result.contracts: []`, `total: 0`, `count: 0`, and `totalPages: 1`.

### 6.6 Contract Details

```http
GET /admin/company-contracts/show?companyId=4&contractId=20
```

Response `200`:

```json
{
  "contractId": 20,
  "companyId": 4,
  "startDate": "2026-09-01",
  "endDate": "2026-09-30",
  "status": 0,
  "deletedAt": null
}
```

Authorized admins can also view a soft-deleted contract through show for historical reference. A missing contract or a contract belonging to another company returns `404`.

## 7. Error Responses

These are target responses for the feature. Do not branch frontend logic on the human-readable `message`; use the HTTP status and field keys.

### Invalid Fields: 422

Example invalid contract dates:

```json
{
  "message": "The given data was invalid.",
  "errors": { "endDate": ["The end date must be on or after the start date."] }
}
```

Example invalid branch inside company creation:

```json
{
  "message": "The given data was invalid.",
  "errors": { "branches.0.name": ["The branch name is required."] }
}
```

Use dot-separated field paths to attach errors to array rows. Other applicable field keys include `companyId`, `branchId`, `contractId`, `startDate`, `status`, `page`, and `pageSize`.

### Existing Active Contract: 409

Returned by create and renew:

```json
{
  "message": "The company already has an active contract.",
  "errors": { "companyId": ["Update or delete the active contract before creating or renewing a contract."] }
}
```

### No Previous Contract to Renew: 409

```json
{
  "message": "There is no previous contract to renew.",
  "errors": { "companyId": ["Create a contract with a start date and an end date first."] }
}
```

### Attempt to Edit/Delete Historical Contract: 409

```json
{
  "message": "Only the active contract can be changed.",
  "errors": { "contractId": ["This contract is expired or deleted."] }
}
```

### Missing Resource or Wrong Company: 404

```json
{ "message": "Resource not found." }
```

Other errors:

| Status | Response shape | Frontend action |
| --- | --- | --- |
| 401 | `{ "message": "Unauthenticated." }` | Return to admin login/refresh flow |
| 403 | `{ "message": "Forbidden.", "status": 403 }` | Explain missing permission; messages may be localized |
| 429 | `{ "message": "Too Many Attempts." }` | Respect retry delay; do not immediately loop |
| 500 | `{ "message": "Server Error" }` | Show a retryable failure; do not assume the mutation succeeded |

## 8. Frontend Workflow

1. Load companies through index. Render the returned current contract and dates directly in the table; do not fetch each company's contracts separately.
2. Open company show for its full company fields, branches, and current contract. Load the paginated history endpoint when opening the contracts section.
3. Create a company with its branches. Use `data.companyId` from the response when creating its first contract.
4. Submit company changes separately from branch operations. Refresh company details after branch changes.
5. With an active contract, offer edit/delete only. With an expired current contract, offer create/renew. With `currentContract: null`, offer create; renewal has no valid source.
6. Renewal sends only `companyId`. Display the returned dates; do not calculate authoritative renewal dates in the browser.
7. After every contract mutation, refresh the companies table, company details, and any open contract history page. Reset history pagination to page 1 after creating or renewing.
8. A `409` can occur if another admin changed the company meanwhile. Refresh current contract data and update the available actions.
9. Disable duplicate submissions while a mutation is pending. If a network request times out, reload state before retrying a creation or renewal.

## 9. Backend Delivery Checklist

Before treating this document as implemented, verify the following against API tests and update this document if a decision changes:

- New contract table/model and permissions; company relations and `currentContract` eager loading for index/show.
- Validation of company branches, resource IDs, ownership, and deleted companies.
- Separate company and branch updates, preserving legacy callers where necessary.
- Agreement on section 2's date boundaries, future-start restriction, historical deletion visibility, and current-contract fallback.
- Concurrent create/renew requests cannot create two active contracts; renewal works correctly across month ends, leap days, and same-day durations.
- The last-active-branch consequence is handled consistently with the existing public ticket identification flow.
- Every documented JSON key, response status, pagination shape, and error shape matches the implemented responses.

This documentation task does not change application code, routes, migrations, or deployed behavior.
