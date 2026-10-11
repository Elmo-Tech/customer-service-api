# Customer Tickets API

Base URL: `https://customerservicebe.testingelmo.com/api/v1`

Send `Accept: application/json` on every request. No admin Bearer token is needed.

## 1. Identify

`POST /public/tickets/identify` with the existing request:

```json
{ "username": "laborazioni", "pin": "1595" }
```

Response `200` (existing fields plus `data.token`):

```json
{
  "data": {
    "name": "Mario Rossi",
    "ticketToken": "CREATE_TICKET_TOKEN",
    "token": "CUSTOMER_TOKEN",
    "tags": [{ "value": 1, "label": "Support" }]
  }
}
```

Keep the PIN as a string. Save `data.token` for the dashboard; `ticketToken` remains for ticket creation. The dashboard token lasts 24 hours; identify again when it expires. Changing the PIN or disabling the customer/company also invalidates access.

## 2. List Tickets

`GET /customer/tickets?token=CUSTOMER_TOKEN&page=1&pageSize=10`

Only the identified customer's tickets are returned, newest first. No filters. `page` defaults to `1`; `pageSize` defaults to `10` (allowed: `1` to `100`).

Response `200`:

```json
{
  "result": {
    "tickets": [
      {
        "ticketId": 1106,
        "ticketNumber": "T123434_10/2026",
        "customerName": "Mario Rossi",
        "companyName": "Company",
        "branchName": "Main",
        "status": 0,
        "importance": 0,
        "description": "Test ticket",
        "createdAt": "11/10/2026",
        "closedAt": ""
      }
    ]
  },
  "pagination": { "total": 1, "count": 1, "perPage": 10, "currentPage": 1, "totalPages": 1 }
}
```

No tickets: `result.tickets` is `[]` and `pagination.total` is `0`.

## 3. Ticket Details

`GET /customer/tickets/show?ticketId=1106&token=CUSTOMER_TOKEN`

Response `200` (no `data` wrapper):

```json
{
  "ticketId": 1106,
  "ticketNumber": "T123434_10/2026",
  "customerId": 34,
  "companyId": 7,
  "branchId": 6,
  "status": 0,
  "importance": 0,
  "description": "Test ticket",
  "attachments": [
    { "id": 1, "fileName": "screenshot.png", "url": "https://customerservicebe.testingelmo.com/storage/tickets/1106/screenshot.png" }
  ],
  "tagId": "",
  "closedAt": "",
  "timelineToken": "TICKET_TIMELINE_TOKEN"
}
```

`attachments` is `[]` when empty; `fileName` is the stored basename. List dates use `DD/MM/YYYY`; details `closedAt` uses the existing stored date format (normally `YYYY-MM-DD HH:mm:ss`), or `""` when open.

`timelineToken` is separate from `token` and `ticketToken`. Use it with the existing [timeline/message/status APIs](ticket-timeline-api.md); older tickets may return `null`.

## Frontend Handling

- Use your HTTP client's query parameters (or `URLSearchParams`) to encode the token correctly. Do not send `customerId`; identity comes from `token`.
- Status: `0` opened, `1` closed, `2` in progress, `3` reopened. Importance: `0` green, `1` yellow, `2` red.
- `422` with `errors.token`: clear the dashboard token and return to identification. Other `422` responses contain field validation errors.
- `404`: the ticket is missing, deleted, or belongs to another customer.
- `429`: too many requests; wait before retrying.
