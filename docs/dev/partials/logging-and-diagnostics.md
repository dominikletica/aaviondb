# Logging & Diagnostics (DRAFT)

> **Status:** Draft  
> **Last updated:** 0.1.0-dev

## Logging
- Framework logger: `AavionDB::logger()` (Monolog, PSR-3).  
- Default handler writes to `system/storage/logs/aaviondb.log`.  
- Log level defaults to `DEBUG`; override via `AavionDB::setup(['log_level' => 'info'])`.  
- Additional domain logs (e.g., `auth.log`) can be introduced by modules using the shared logger.

## Command Telemetry
- `CommandRegistry` normalises responses and emits events:
  - `command.executed`: includes action, status, duration, and meta.  
  - `command.failed`: includes action, exception, duration.  
- Unexpected exceptions are logged and converted into structured error responses (`status = error`, `meta.exception` payload).

## Module Diagnostics
- `ModuleLoader::diagnostics()` surfaces discovered modules, scopes, autoload flags, dependencies, and issues.  
- Data appears in `AavionDB::diagnose()` and should feed the future diagnostics dashboard.

## Brain Integrity
- `BrainRepository::integrityReport()` reports:
  - File metadata for system/active brains (path, size, modified timestamp).  
  - Last successful write (hash, attempts, timestamp).  
  - Last failure reason (hash mismatch, canonical mismatch, JSON error, read failure).  
- Integrity failures trigger retry + logging; unrecoverable cases raise `StorageException` which must be caught by callers.

## Planned Enhancements
- System log module with commands `log view [level|domain]`, `log rotate`, `log cleanup`, `log view auth`.  
- Integration of diagnostics dashboard (UI) with live event stream and log viewer.  
- Telemetry export (metrics/health endpoints) for automation and monitoring.
