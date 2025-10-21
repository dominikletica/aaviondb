# Security & Permissions

> **Status:** Maintained – advanced policies pending.  
> **Last updated:** 2025-10-20

## REST Hardening
- `AuthManager` protects `api.php` using hashed tokens stored in `system.brain`. The bootstrap token (`admin`) is explicitly rejected for REST, and the endpoint remains disabled until `api.enabled = true`.
- Successful calls update usage metadata (`last_used_at`, `last_request_at`) for upcoming analytics and audit trails.

## Rate Limiting & Lockdown
- `SecurityManager` enforces three buckets:
  1. **Per-client window** (`security.rate_limit`) – Requests per minute per client (keyed by IP). Exceeding the limit triggers a `429` response, sets a client block (`security.block_duration` seconds), and includes a `Retry-After` header.
  2. **Global window** (`security.global_limit`) – Aggregated requests across all clients. Crossing the threshold activates a temporary lockdown for `security.ddos_lockdown` seconds and responds with `503` + `Retry-After`.
  3. **Failed authentication window** (`security.failed_limit`) – Tracks invalid or missing tokens. Surpassing the limit blocks the client for `security.failed_block` seconds.
- All counters, blocks, and lockdown artefacts are stored via `CacheManager` under the `security:*` tag and persist even when general caching is disabled.
- `SecurityAgent` exposes CLI/REST controls:
  - `security config` / `security status` – view configuration + lockdown state.
  - `security enable|disable` – toggle enforcement (`security.active`).
  - `security lockdown [seconds]` – trigger manual lockdowns (defaults to `security.ddos_lockdown`).
  - `security purge` – remove cached counters and blocks.
- REST responses automatically forward applicable headers (`Retry-After`) and surface structured error payloads (`reason`, `blocked_until`, `locked_until`).

## Module Capabilities
- `ModuleLoader` enforces capability-scoped contexts (`commands.register`, `storage.write`, `security.manage`, `cache.manage`, …). Missing capabilities raise runtime exceptions, preventing accidental privilege escalation.
- System modules receive a comprehensive default capability set; user modules must explicitly opt into elevated privileges.
