# REST API Layer

> **Status:** Maintained  
> **Last updated:** 2025-10-20

## Enablement & Authentication
- REST is disabled by default (`system.brain.state.api_enabled = false`).  
- Activate via CLI/PHP: `auth grant` (generates non-bootstrap key) + `api serve`.  
- Requests must include `Authorization: Bearer <token>`; bootstrap key `admin` is rejected for REST.
- Tokens carry scope metadata (`ALL`, `RW`, `RO`, `WO`) and optional project filters.  
  - `WO` currently falls back to `RW` behaviour until dedicated write-only flows ship.
- `AuthManager` validates tokens, then executes the request inside the granted scope via `AavionDB::withScope()`.
- Manage availability with ApiAgent commands:  
  - `api serve [reason=text]` – enable REST (requires ≥1 active token).  
  - `api stop [reason=text]` – disable REST, optionally recording a reason.  
  - `api status` – report telemetry (`enabled`, active token count, bootstrap state, last request/enabled/disabled timestamps, last actor).  
  - `api reset` – revoke all API tokens and keep REST disabled (wrapper around `BrainRepository::resetAuthTokens()`).
- SecurityManager enforces rate limiting for REST requests (per-client, global, failed-attempt buckets). Blocks emit `Retry-After` headers.  
- `cron` action bypasses auth and rate limiting, allowing scheduler execution via HTTP.

## Endpoint
`api.php?action=<command>[&param=value]`

Examples:
- `GET api.php?action=list&scope=projects`  
- `POST api.php?action=save&project=demo&entity=test` (JSON body)

## Payload Handling
- Query parameters mapped directly to command parameters.  
- JSON request body decoded into `payload`.  
- Form submissions are supported as a fallback (URL-encoded body).  
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
- `401` – authentication failure  
- `403` – scope not allowed  
- `503` – REST disabled (bootstrap mode)  
- `500` – unexpected server error (exception logged + sanitized response)

## UI Integration
- The planned **AavionStudio** front-end will communicate via REST; it must inject the authenticated user's API token automatically.  
- When trusted integrations execute commands through embedded PHP, they should still return the standard CommandResponse payload.
- Rate-limiter telemetry is exposed via `security status` for UI dashboards.
