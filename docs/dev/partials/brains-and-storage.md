# Brains & Storage Engine (DRAFT)

> **Status:** Draft  
> **Last updated:** 0.1.0-dev

## System Brain
- File: `system/storage/system.brain` (JSON).  
- Always mounted; stores framework metadata, configuration (`config` map), authentication (`auth` section with hashed tokens) and REST controls (`api` section), audit markers, and global commit lookup (`commits`).  
- Never exposed directly to user space; all interactions go through validated commands.

## User Brains
- Located under `user/storage/<slug>.brain`.  
- Provide isolated environments for project/entity data and optional per-brain configuration via `config`.  
- Auto-created on first access (e.g., `init demo`).

## Data Characteristics
- JSON-based with deterministic canonical encoding (sorted associative keys, preserved indexed order).  
- Entity versions include SHA-256 content hash, commit hash, timestamps, status, merge flag, optional `fieldset` reference, and optional `source_reference` / `fieldset_reference` metadata for diagnostics.  
- Incremental saves merge payload diffs by default (null removes keys, nested objects merge recursively, indexed arrays replace wholesale).  
- Global `commits` map accelerates lookup by hash.

## Repository Helpers
- `listProjects()`, `listEntities()`, `saveEntity()`, `getEntityVersion()` manage deterministic storage (including incremental merges + schema hooks).  
- `resolveSchemaPayload()` (internal) loads fieldset definitions from project `fieldsets`, honours `@version`/`#commit` selectors, and validates entity payloads before persistence.  
- Config API: `setConfigValue()`, `getConfigValue()`, `deleteConfigValue()`, `listConfig()` (supports system/user brains, performs key normalization).  
- Auth/API helpers: `registerAuthToken()`, `revokeAuthToken()`, `listAuthTokens()`, `setApiEnabled()`, `isApiEnabled()`, `updateBootstrapKey()` provide canonical mutations for security state (emit events, update telemetry).  
- Atomic writes with integrity verification (write temp file → rename → re-read + hash validation); retries once on mismatch, logs failures, records telemetry (`integrityReport()`).

## Integrity Report
`BrainRepository::integrityReport()` returns:
- Paths and metadata for system/active brains (size, timestamps, slug).  
- Last successful write (hash, attempts, timestamp).  
- Last failure reason (if any).  
- Security snapshot: API enabled flag, bootstrap status, token counters, last REST access timestamps.  
This feeds `AavionDB::diagnose()` and future diagnostics dashboards.
