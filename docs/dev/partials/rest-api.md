# REST API Layer (DRAFT)

> **Status:** Draft  
> **Last updated:** 0.1.0-dev

## Enablement & Authentication
- REST is disabled by default (`system.brain.state.api_enabled = false`).  
- Activate via CLI/PHP: `auth grant` (generates non-bootstrap key) + `api serve`.  
- Requests must include `Authorization: Bearer <token>`; bootstrap key `admin` is rejected for REST.

## Endpoint
`api.php?action=<command>[&param=value]`

Examples:
- `GET api.php?action=list&scope=projects`  
- `POST api.php?action=save&project=demo&entity=test` (JSON body)

## Payload Handling
- Query parameters mapped directly to command parameters.  
- JSON request body decoded into `payload`.  
- If decoding fails, API returns `status=error`, `message="Invalid JSON payload"` (HTTP 400).  
- `OPTIONS` request returns CORS headers (for UI integration).

## Response Schema
```json
{
  "status": "ok",
  "action": "save",
  "message": "Entity successfully saved",
  "data": {
    "project": "demo",
    "entity": "test",
    "version": 103,
    "commit": "b8f7a3d29e..."
  },
  "meta": {}
}
```

Errors use the same structure (`status="error"`, `data=null`). HTTP status codes:
- `200` – success  
- `400` – validation/usage error  
- `403` – authentication failure  
- `503` – REST disabled (bootstrap mode)  
- `500` – unexpected server error (exception logged + sanitized response)

## UI Integration
- Future UI (index.php) may execute commands via REST for debugging; it must include the logged-in user's API key automatically.  
- When UI uses internal PHP execution instead, responses remain identical (same CommandResponse structure).
