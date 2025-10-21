# Logging & Diagnostics

> **Status:** Maintained  
> **Last updated:** 2025-10-20

## Logging
- Framework logger: `AavionDB::logger()` (Monolog, PSR-3).  
- Default handler writes JSON contexts to `system/storage/logs/aaviondb.log`; rotation archives live next to it.  
- Module loggers attach `source=<module>` and optional `debug` flags to ease filtering.  
- Log level defaults to `DEBUG`; override via `AavionDB::setup(['log_level' => 'info'])`.  
- LogAgent provides CLI/REST access: `log [level] [limit]`, `log rotate [keep=N]`, `log cleanup [keep=N]`.

## Command Telemetry
- `CommandRegistry` normalises responses and emits events:
  - `command.executed`: includes action, status, duration, and meta.  
  - `command.failed`: includes action, exception, duration.  
- Unexpected exceptions are logged and converted into structured error responses (`status = error`, `meta.exception` payload).

## Module Diagnostics
- `ModuleLoader::diagnostics()` surfaces discovered modules, scopes, autoload flags, dependencies, and issues.  
- Data appears in `AavionDB::diagnose()` and feeds scheduler/cache/security telemetry dashboards.

## Brain Integrity
- `BrainRepository::integrityReport()` reports:
  - File metadata for system/active brains (path, size, modified timestamp).  
  - Last successful write (hash, attempts, timestamp).  
  - Last failure reason (hash mismatch, canonical mismatch, JSON error, read failure).  
  - Cache metrics (active entries, bytes, expiration backlog).  
- Integrity failures trigger retry + logging; unrecoverable cases raise `StorageException` which must be caught by callers.
