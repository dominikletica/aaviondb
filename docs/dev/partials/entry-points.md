# Entry Points (DRAFT)

> **Status:** Draft  
> **Last updated:** 0.1.0-dev

| Interface | File | Behaviour |
|-----------|------|-----------|
| REST / PHP | `api.php` | Bootstraps framework, validates `action`, decodes JSON payloads, enforces auth and `api_enabled` flag. Returns unified CommandResponse JSON, logs all failures. |
| CLI | `cli.php` | Accepts human-readable command statements (`php cli.php "save demo entity {\"title\":\"Hi\"}"`). Outputs JSON, sets exit code `0` on success, `1` on error. |
| Embedded PHP | `system/core.php` | Include and use `AavionDB::run()` / `AavionDB::command()`. `setup()` is idempotent; methods auto-run it if needed. |

### Notes
- Both entry points wrap calls in `try/catch` and never expose raw PHP stack traces.  
- REST rejects requests while bootstrap key `admin` is active or `api_enabled = false`.  
- CLI/embedded modes bypass API keys but still log operations.  
- Future consolidation: single entry script deciding mode by SAPI (`cli` vs web) to reduce duplication.
