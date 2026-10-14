# Companies, Customers and Contracts: Frontend API

Implemented on this branch; deploy the code, migration and permissions before using these endpoints on the server.
The [Arabic guide](companies-branches-contracts-api.ar.md) contains a complete request/response example for every operation.

## Requests

Base URL: `https://customerservicebe.testingelmo.com/api/v1/admin`

```http
Authorization: Bearer ADMIN_ACCESS_TOKEN
Accept: application/json
Content-Type: application/json
```

GET/DELETE parameters use the query string. POST/PUT parameters use a JSON body.
Company/customer status: `1` active, `0` inactive.
Contract status: `1` active, `0` expired, calculated by the backend.
Dates use `YYYY-MM-DD`; application timezone determines today.
All successful operations below return HTTP 200.

## Company Create

`POST /companies/create`

```json
{
  "name": "Arca Pro",
  "status": 1,
  "customer": {
    "firstname": "Mario",
    "lastname": "Rossi",
    "username": "mario.rossi",
    "pin": "0705"
  },
  "contract": {
    "startDate": "2026-10-12",
    "endDate": "2026-11-10"
  }
}
```

- `name`, `status` and `customer` are required. Company name and customer username must be unique.
- Customer `firstname`, `lastname`, `username` and `pin` are required strings, at most 255 characters. PIN must stay a string to preserve leading zeros.
- Customer `email` is optional: omit it or send `null`; a supplied value must be a valid email, at most 255 characters.
- `contract` is optional. Omit it to create without a contract; do not send an empty object or null. Both dates are required when provided.
- Do not send customer IDs/status or contract IDs/status. The backend derives them.
- No branch keys are required or returned. The backend creates a branch named exactly after the company.
- Company, branch, active customer and optional contract are saved in one transaction.
- Legacy ticket support is disabled for new companies; updates do not change existing legacy settings.

Response 200:

```json
{
  "message": "Company created successfully.",
  "data": {
    "companyId": 4,
    "name": "Arca Pro",
    "status": 1,
    "currentContract": {
      "contractId": 21,
      "companyId": 4,
      "startDate": "2026-10-12",
      "endDate": "2026-11-10",
      "status": 1
    },
    "customer": {
      "customerId": 34,
      "companyId": 4,
      "firstname": "Mario",
      "lastname": "Rossi",
      "username": "mario.rossi",
      "email": null,
      "status": 1
    }
  }
}
```

Without a contract, `data.currentContract` is null. PIN is never returned by this endpoint.

## Company Index and Details

`GET /companies?page=1&pageSize=10&filter[search]=Arca&filter[status]=1`

All query parameters are optional. Page starts at 1; pageSize defaults to 10 and accepts 1 through 100.
Search uses the existing name filter. Status accepts 0 or 1.

Response 200:

```json
{
  "result": {
    "companies": [
      {
        "companyId": 4,
        "name": "Arca Pro",
        "status": 1,
        "currentContract": {
          "contractId": 21,
          "companyId": 4,
          "startDate": "2026-10-12",
          "endDate": "2026-11-10",
          "status": 1
        }
      }
    ]
  },
  "pagination": {
    "total": 1,
    "count": 1,
    "perPage": 10,
    "currentPage": 1,
    "totalPages": 1
  }
}
```

`GET /companies/show?companyId=4` and the retained `GET /companies/edit?companyId=4` require companyId.
Both return the single company object shown inside the array above, with no wrapper.
Neither includes branches or customer credentials.

## Company Update and Delete

`PUT /companies/update`

```json
{ "companyId": 4, "name": "Arca Pro Updated", "status": 1 }
```

Response: `{"message":"Company updated successfully."}`

Only companyId, name and status are used. The default branch name/status are synchronized internally.
This endpoint does not change the customer, contracts or legacy settings.

`DELETE /companies/delete?companyId=4`

Response: `{"message":"Company deleted successfully."}`

Deletion is soft. Existing related records are retained.

## Contract Endpoints

| Endpoint | Required input | Optional input |
| --- | --- | --- |
| `GET /company-contracts` | companyId | page, pageSize |
| `GET /company-contracts/show` | companyId, contractId | None |
| `POST /company-contracts/create` | companyId, startDate, endDate | None |
| `PUT /company-contracts/update` | companyId, contractId, startDate, endDate | None |
| `POST /company-contracts/renew` | companyId | None |
| `DELETE /company-contracts/delete` | companyId, contractId | None |

Contract detail response 200, without a wrapper:

```json
{
  "contractId": 21,
  "companyId": 4,
  "startDate": "2026-10-12",
  "endDate": "2026-11-10",
  "status": 1
}
```

Create example:

```json
{ "companyId": 4, "startDate": "2026-10-12", "endDate": "2026-11-10" }
```

Create/update/renew return `{"message":"...","data":{...}}`, where data is the complete contract object above.
Messages are `Contract created successfully.`, `Contract updated successfully.`, and `Contract renewed successfully.`.
Delete returns `{"message":"Contract deleted successfully."}`.

History response 200:

```json
{
  "result": {
    "contracts": [
      {
        "contractId": 21,
        "companyId": 4,
        "startDate": "2026-10-12",
        "endDate": "2026-11-10",
        "status": 1
      }
    ]
  },
  "pagination": {
    "total": 1,
    "count": 1,
    "perPage": 10,
    "currentPage": 1,
    "totalPages": 1
  }
}
```

History is ordered by creation time descending, then ID descending. Empty lists return [] with total/count 0 and totalPages 1.
History includes soft-deleted records without exposing deletion metadata.
Only offer update/delete for the active currentContract, not every history row with status 1.

## Contract Rules

- startDate must not be in the future; endDate must be on or after startDate.
- End day is inclusive. A contract expires the following day.
- An active non-deleted contract blocks create and renew with 409. Update or delete it first.
- Only active, non-deleted contracts can be updated/deleted. Expired/deleted history is read-only.
- Updating dates into the past makes the contract expired and read-only.
- Renewal selects the most recently created non-deleted expired contract and creates an independent new record, starting today.
- Inclusive duration is preserved in calendar days, not calendar months: a 30-day contract renewed October 12 ends November 10.
- A previous-contract ID or renewal-chain relationship is not stored.
- No eligible previous contract means renewal returns 409; use create.
- currentContract selects the active contract, otherwise the most recently created non-deleted expired contract, otherwise null.
- Soft-deleted contracts neither block creation nor supply renewal dates.
- No deletedAt, branches or legacyTicketEnabled keys appear in these company/contract responses.
- Refresh company index/details and contract history after a mutation.

## Errors and Compatibility

401 unauthenticated; 403 missing permissions; 404 missing company/contract or ownership mismatch;
409 contract business-rule conflict; 422 validation; 429 throttled; 500 unexpected failure.

Validation responses use `message` and `errors`. Nested create errors use keys such as
`customer.username`, `customer.email`, `contract.startDate`, and `contract.endDate`.
Use error keys and HTTP status, not localized message text.

Company pagination now uses perPage/currentPage/totalPages. Company/branch validation uses 422 instead of the former 401.
Coordinate frontend and backend deployment. Branch APIs remain backend-only and are not part of the frontend integration.
