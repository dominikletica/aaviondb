# Authentication & API Keys (DRAFT)

> **Status:** Draft  
> **Last updated:** 0.1.0-dev

## Admin Secret
- Configured via `config.php` (`admin_secret`). Must start with `_` and be at least 8 characters. Empty string disables it.  
- Grants full access regardless of API state or existing tokens; bypass intended for setup/recovery. Usage should be logged and key stored securely.

## Bootstrap Key
- Static key `admin` is seeded on first boot and remains valid until a user-supplied token is created.  
- CLI/PHP entry points may use the bootstrap key implicitly, but REST access is always blocked while it is the only active key.

## Token Storage & Format
- Authentication state lives in `system.brain` under two sections:  
  - `auth`: `{ bootstrap_key, bootstrap_active, keys[], last_rotation_at }`  
  - `api`: `{ enabled, last_enabled_at, last_disabled_at, last_request_at }`
- Tokens are stored as SHA-256 hashes. Metadata tracks `status` (`active|revoked`), creation timestamps, previews, and last usage.
- `BrainRepository::registerAuthToken()`, `revokeAuthToken()`, `listAuthTokens()`, `setApiEnabled()`, and `updateBootstrapKey()` encapsulate all mutations (emit events, maintain telemetry).  
- `BrainRepository::touchAuthKey()` updates usage metadata atomically after each successful REST call.

## REST Control
- `api.enabled` must be toggled (via upcoming `api serve` / `api stop` commands) before REST accepts requests.  
- Without an active non-bootstrap token or while the API flag is disabled, `api.php` returns structured error responses (HTTP 401/403/503).  
- Revoking the last user token automatically disables the API and reactivates the bootstrap key for recovery.
- Clients must supply `Authorization: Bearer <token>`; fallbacks (`X-API-Key`, `token`/`api_key` query/body parameters) exist for tooling convenience.

## Lifecycle Commands
- `auth grant` (planned implementation) will mint a new token (default 16 chars; configurable via `api_key_length`), persist its hash, and return handling guidance.  
- `auth list` will expose masked tokens, status flags, and audit timestamps.  
- `auth revoke {key}` will deactivate a token; if no active keys remain, `api.enabled` is forced off and `bootstrap_active` resets.  
- `auth reset` will reset all tokens, disable REST, and re-enable the bootstrap key for recovery.

## Logging & Recovery
- Auth events will be forwarded to the upcoming logging module (view/rotate/cleanup commands).  
- If a token is lost, operators can use CLI (`auth grant`, `auth reset`) or inspect the system log once implemented.  
- UI should prompt operators to replace the bootstrap key immediately after the first login.
