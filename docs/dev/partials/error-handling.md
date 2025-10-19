# Error Handling

> Status: Implemented – consolidated overview of response envelopes and error semantics.

## Response Envelope
- Every command (CLI/PHP/REST) returns the `CommandResponse` structure:
  ```json
  {
    "status": "ok" | "error",
    "action": "<command>",
    "message": "Human readable text",
    "data": { ... },      // optional payload on success
    "meta": { ... }       // optional metadata (validation hints, exception info)
  }
  ```
- CLI (`php cli.php "<command>"`) and PHP (`AavionDB::run(...)`) receive the array directly.
- REST (`api.php?action=...`) wraps it in an HTTP response:
  - `200 OK` when `status == "ok"`.
  - `400 Bad Request` for `status == "error"` without an embedded exception.
  - `500 Internal Server Error` if `meta.exception` is present.
  - `401/403/503` are reserved for `AuthManager` results (missing/invalid token, bootstrap/admin enforcement).

## Common Failure Modes
| Scenario | Message | Notes |
|----------|---------|-------|
| Missing parameter (e.g. `project create` without `slug`) | `Parameter "slug" is required.` | CLI/PHP/REST all return `status=error`. |
| Unknown command (`help foo`) | `Unknown command "foo".` | REST → 400. |
| REST without token | `Missing API token.` | HTTP 401. |
| REST bootstrap token usage | `Bootstrap token cannot be used for REST access.` | HTTP 403. |
| REST while API disabled | `REST API is disabled. Use CLI command "api serve" to enable it.` | HTTP 503. |
| Export: wildcard + selectors | `Entity selectors are not supported when exporting all projects.` | CLI/PHP/REST → `status=error`. |
| Export: selectors with multi-project CSV | `Entity selectors are only supported when exporting a single project.` |  |
| Export: preset + selectors | `Entity selectors cannot be combined with preset-based exports.` |  |
| Export: preset missing | `Preset "<name>" not found (expected <path>).` | Ensure Studio writes JSON to `user/presets/export/`. |
| Export: preset JSON invalid | `Preset "<name>" contains invalid JSON: …` | Includes JSON parser error in message. |
| Auth: invalid token | `Invalid or unknown API token.` | HTTP 401. |
| Auth: inactive token | `API token is not active.` | HTTP 403. |

> Tip: When troubleshooting via REST, inspect the HTTP status and the `meta.exception` block (if present). CLI/PHP surfaces the same structure; logging is managed via `Monolog` under `system/storage/logs/`.
