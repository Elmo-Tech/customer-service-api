# Ticket Timeline API

This document describes the backend APIs needed by the frontend to display and update a ticket timeline.

The frontend must keep status and priority labels/translations on its side. The backend returns numeric values only.

## Numeric Values

### Log Types

| Value | Meaning |
| --- | --- |
| `1` | Message |
| `2` | Status change |

### Actor Types

| Value | Meaning |
| --- | --- |
| `1` | Admin |
| `2` | Customer |

### Ticket Status

| Value | Meaning |
| --- | --- |
| `0` | Opened |
| `1` | Done / Closed |
| `2` | In progress |
| `3` | Reopened |

### Ticket Priority

The ticket priority is returned from the existing `importance` field as `priority`.

| Value | Existing backend enum |
| --- | --- |
| `0` | Green |
| `1` | Red |
| `2` | Yellow |

## Customer Timeline Token

When a ticket is created, the backend stores a permanent timeline token on the ticket.

The first ticket email sent to the customer includes a link like:

```text
http://tickets.testingelmo.com/tickets/timeline?ticketId=123&timelineToken=TIMELINE_TOKEN
```

The frontend should read `ticketId` and `timelineToken` from the URL and use them when calling customer timeline APIs.

The customer does not type the token manually.

This token is separate from the old close/review token. The old token is still used only by the existing review flow.

## Initial Ticket Message

When the customer creates a ticket, the backend automatically creates the first timeline message.

The first message uses:

```text
type=1
actorType=2
message=ticket description
attachments=ticket creation attachments
```

The frontend should display this initial message inside `ticketMessages` like any other customer message.

## Admin: Get Ticket Timeline

```http
GET /api/v1/admin/tickets/timeline?ticketId=123&page=1&pageSize=10
Authorization: Bearer ADMIN_JWT
```

### Purpose

Returns the ticket summary and paginated timeline logs for an admin user.

### Auth

Requires:

```text
auth:api
permission:edit_ticket
```

### Response

```json
{
  "result": {
    "ticket": {
      "ticketNumber": "T493540_08/2026",
      "createdAt": "2026-08-28 09:15:00",
      "closedAt": null,
      "customerName": "Mario Rossi",
      "company": "Acme",
      "priority": 0,
      "status": 2
    },
    "ticketMessages": [
      {
        "id": 10,
        "type": 1,
        "actorType": 2,
        "userId": 55,
        "userName": "Mario Rossi",
        "createdAt": "2026-08-28 09:20:00",
        "message": "Buongiorno, il problema persiste.",
        "attachments": []
      },
      {
        "id": 11,
        "type": 2,
        "actorType": 1,
        "userId": 3,
        "userName": "Admin",
        "createdAt": "2026-08-28 09:35:00",
        "oldStatus": 0,
        "newStatus": 2
      }
    ]
  },
  "pagination": {
    "total": 25,
    "count": 10,
    "perPage": 10,
    "currentPage": 1,
    "totalPages": 3
  }
}
```

## Customer: Get Ticket Timeline

```http
GET /api/v1/tickets/timeline?ticketId=123&timelineToken=TIMELINE_TOKEN&page=1&pageSize=10
```

### Purpose

Returns the ticket summary and paginated timeline logs for the customer using the token from the email link.

### Auth

No JWT is required for this endpoint.

The backend validates:

```text
ticketId
timelineToken
```

The token must match the ticket timeline token.

### Response

Same response shape as the admin timeline endpoint.

## Timeline Pagination

Both timeline GET endpoints support the same pagination query params:

```text
page=1
pageSize=10
```

If `pageSize` is not sent, the backend uses `10`.

The response includes:

```json
{
  "pagination": {
    "total": 25,
    "count": 10,
    "perPage": 10,
    "currentPage": 1,
    "totalPages": 3
  }
}
```

## Exact API Keys

Use these keys exactly.

### Timeline GET Query Params

```text
ticketId
timelineToken
page
pageSize
```

`timelineToken` is required only for the customer endpoint.

### Message Form-Data Fields

```text
ticketId
timelineToken
message
attachments[]
```

`timelineToken` is required only for the customer endpoint.

Use `attachments[]` as the Postman/form-data key for files. It is received by Laravel as the `attachments` array.

### Customer Status JSON Body

```text
ticketId
timelineToken
status
```

### Timeline Response Keys

```text
result
ticket
ticketNumber
createdAt
closedAt
customerName
company
priority
status
ticketMessages
id
type
actorType
userId
userName
message
attachments
fileName
url
oldStatus
newStatus
pagination
total
count
perPage
currentPage
totalPages
```

## Admin: Send Message

```http
POST /api/v1/admin/tickets/messages
Authorization: Bearer ADMIN_JWT
Content-Type: multipart/form-data
```

### Purpose

Creates a message log as an admin actor.

### Auth

Requires:

```text
auth:api
permission:update_ticket
```

### Multipart Request

Use `multipart/form-data`:

```text
ticketId=123
message=Abbiamo verificato il problema.
attachments[]=screenshot.png
attachments[]=report.pdf
```

### Response

```json
{
  "data": {
    "id": 12,
    "type": 1,
    "actorType": 1,
    "userId": 3,
    "userName": "Admin",
    "createdAt": "2026-08-28 10:03:00",
    "message": "Abbiamo verificato il problema.",
    "attachments": []
  }
}
```

## Customer: Send Message

```http
POST /api/v1/tickets/messages
Content-Type: multipart/form-data
```

### Purpose

Creates a message log as a customer actor.

### Auth

No JWT is required for this endpoint. The frontend must send the `timelineToken` from the timeline URL.

### Multipart Request

Use `multipart/form-data`:

```text
ticketId=123
timelineToken=TIMELINE_TOKEN
message=Il problema persiste.
attachments[]=screenshot.png
attachments[]=report.pdf
```

### Response

```json
{
  "data": {
    "id": 13,
    "type": 1,
    "actorType": 2,
    "userId": 55,
    "userName": "Mario Rossi",
    "createdAt": "2026-08-28 10:05:00",
    "message": "Il problema persiste.",
    "attachments": [
      {
        "id": 1,
        "fileName": "screenshot.png",
        "url": "/storage/tickets/123/messages/1724832300_screenshot.png"
      }
    ]
  }
}
```

## Customer: Update Ticket Status

```http
PUT /api/v1/tickets/status
Content-Type: application/json
```

### Purpose

Allows the customer to close or reopen the ticket using the timeline token from the email link.

### Auth

No JWT is required for this endpoint. The frontend must send the `timelineToken` from the timeline URL.

### JSON Request

```json
{
  "ticketId": 123,
  "timelineToken": "TIMELINE_TOKEN",
  "status": 3
}
```

Allowed customer status values:

| Value | Meaning |
| --- | --- |
| `1` | Close ticket |
| `3` | Reopen ticket |

### Response

```json
{
  "message": "ticket status has been updated!"
}
```

## Status Change Logs

The frontend does not call a separate endpoint to create status logs directly.

Status-change logs are created automatically when either endpoint changes the ticket status:

```http
PUT /api/v1/admin/tickets/update
Authorization: Bearer ADMIN_JWT
```

```http
PUT /api/v1/tickets/status
```

If the old status and new status are equal, no status-change log is created.

Status logs look like this in the timeline:

```json
{
  "id": 14,
  "type": 2,
  "actorType": 1,
  "userId": 3,
  "userName": "Admin",
  "createdAt": "2026-08-28 10:15:00",
  "oldStatus": 1,
  "newStatus": 2
}
```

## Frontend Display Rules

Render timeline logs ordered as returned by the API:

```text
createdAt ASC, id ASC
```

For `type = 1`, show:

```text
actor
createdAt
message
attachments
```

For `type = 2`, show:

```text
actor
createdAt
oldStatus -> newStatus
```

Do not expect translated status or priority labels from the API. Map numeric values in the frontend.

## Notes

- Sending a message does not send an email notification.
- Customer access uses the timeline token from the email link.
- The timeline token currently does not expire.
- The existing old `ticket_logs` review flow is separate and should not be used for this timeline UI.
