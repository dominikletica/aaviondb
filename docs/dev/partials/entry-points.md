# Entry Points

> **Status:** Maintained  
> **Last updated:** 2025-10-20

| Interface | File | Behaviour |
|-----------|------|-----------|
| REST / PHP | `api.php` | Bootstraps framework, validates `action`, decodes JSON payloads, enforces auth and `api_enabled` flag. Returns unified CommandResponse JSON, logs all failures. |
| CLI | `cli.php` | Accepts human-readable command statements (`php cli.php "save demo entity {\"title\":\"Hi\"}"`). Outputs JSON, sets exit code `0` on success, `1` on error. |
| Embedded PHP | `aaviondb.php` | Composer bootstrap + framework setup in one include. Provides `AavionDB::run()` / `AavionDB::command()` for host applications. |

### Notes
- All entry points wrap calls in `try/catch` and never expose raw PHP stack traces.  
- REST rejects requests while `system.brain.api.enabled = false` or when only the bootstrap key exists.  
- Clients must pass a token via `Authorization: Bearer <token>` (fallback: `X-API-Key`, `token`/`api_key` query parameters). Bootstrap token `admin` is blocked for REST.  
- CLI/embedded modes bypass API keys but still log operations.  
- `AavionDB::isBooted()` lets long-running PHP processes avoid redundant bootstrap calls.  
- Future consolidation: single entry script deciding mode by SAPI (`cli` vs web) to reduce duplication.
