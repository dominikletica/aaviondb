# BrainAgent Module

> **Status:** Implemented – brain discovery, activation, and validation commands.  
> **Last updated:** 2025-10-20

## Responsibilities
- Manage brain discovery, activation, initialisation, and backups.
- Provide backup lifecycle management (snapshot, listing, pruning, restore).
- Expose configuration helpers (system vs user brains) for other modules.
- Publish integrity snapshots and maintenance hooks (validation, cleanup, compaction, repair).

## Commands
- `brains` – List system and user brains with metadata (type, size, entity versions, active flag).
- `brain init <slug> [switch=1]` – Create a new user brain and optionally activate it.
- `brain switch <slug>` – Set the specified brain as active.
- `brain backup [slug] [label=name]` – Create a timestamped backup copy in `user/backups/`.
- `brain info [slug]` – Return detailed information for the requested brain (defaults to active brain).
- `brain validate [slug]` – Run integrity diagnostics (checksum, last write/failure metadata).
- `brain delete <slug>` – Permanently delete a non-active user brain.
- `brain cleanup <project> [entity] [keep=0] [--dry-run=1]` – Purge inactive versions for the given project (optional entity scope, preview mode, preserve the most recent `keep` versions).
- `brain compact [project] [--dry-run=1]` – Rebuild commit indexes and reorder entity version arrays for the selected project(s).
- `brain repair [project] [--dry-run=1]` – Repair entity metadata inconsistencies (active versions, statuses, timestamps).
- `brain backups [slug]` – List stored backup files (optional slug filter).
- `brain backup prune <slug|*> [--keep=10] [--older-than=30] [--dry-run=1]` – Delete backups using retention/age policies.
- `brain restore <backup> [target] [--overwrite=0] [--activate=0]` – Restore a brain from a backup file, optionally activating it afterwards.

## Call Flow
- `system/modules/brain/module.php` constructs `AavionDB\Modules\Brain\BrainAgent` and calls `BrainAgent::register()`.
- `BrainAgent::registerParser()` rewrites statements like `brain init foo` or bare `brains` into canonical command names and merges parameters before dispatch.
- Each command maps to a dedicated method: `brainInitCommand()`, `brainSwitchCommand()`, `brainBackupCommand()`, `brainBackupsCommand()`, `brainBackupPruneCommand()`, `brainInfoCommand()`, `brainValidateCommand()`, `brainDeleteCommand()`, `brainCleanupCommand()`, `brainCompactCommand()`, `brainRepairCommand()`, and `brainRestoreCommand()`. All return `CommandResponse` objects.
- The handlers delegate to `BrainRepository` operations (`createBrain`, `setActiveBrain`, `backupBrain`, `listBackups`, `pruneBackups`, `brainReport`, `integrityReportFor`, `deleteBrain`, `purgeInactiveEntityVersions`, `compactBrain`, `repairBrain`, `restoreBrain`) and emit debug logs via `ModuleContext::debug()` for troubleshooting.

## Key Classes & Collaborators
- `AavionDB\Modules\Brain\BrainAgent` – parser + command registrar.
- `AavionDB\Core\Storage\BrainRepository` – performs actual filesystem mutations and reporting.
- `AavionDB\Core\Modules\ModuleContext` – exposes command registry, logger, diagnostics hooks.  
- `AavionDB\Core\CommandResponse` – wraps every handler result in the unified schema.  
- `PathLocator` – ensures backup directories exist (invoked inside repository helpers).

## Implementation Notes
- Module lives in `system/modules/brain` and leverages helpers in `BrainRepository` (`listBrains`, `createBrain`, `setActiveBrain`, `backupBrain`, `deleteBrain`, `brainReport`, `integrityReportFor`, `purgeInactiveEntityVersions`, `compactBrain`, `repairBrain`). Cleanup supports keep-threshold previews (`--dry-run`), compaction rebuilds commit maps, and repair realigns entity metadata.

## Examples

### CLI
```bash
php cli.php "brains"
```
```json
{
  "status": "ok",
  "action": "brains",
  "data": {
    "count": 2,
    "brains": [
      {"slug": "system", "type": "system", "active": false},
      {"slug": "default", "type": "user", "active": true}
    ]
  }
}
```

### REST
```bash
curl -H "Authorization: Bearer <token>" \
  "https://example.test/api.php?action=brain%20info&slug=default"
```
Returns `status=ok` with the project/entity summary for the active brain.

### PHP
```php
$response = AavionDB::run('brain validate', ['slug' => 'default']);
```

## Error Handling
- Missing slug for commands that require one (`brain switch`, `brain backup`, `brain info`) triggers `status=error` with message `Parameter "slug" is required.`
- Referencing a non-existent brain raises `status=error` with an explanatory message from `BrainRepository`.
- Backups surface filesystem issues via `meta.exception` while logging the underlying error.
- Parser handler rewrites human-friendly statements (`brain init foo`) to structured commands + parameters.
- Backups are stored under `user/backups/`; PathLocator now ensures the directory exists.
- Diagnostics include footprint metrics (bytes + entity-version count) consumed by CoreAgent `status`.

## Outstanding Tasks
- [ ] Add additional retention policies (e.g., per-entity thresholds) and compaction metrics.
- [ ] Add PHPUnit coverage for brain lifecycle, deletion, and maintenance edge cases.
