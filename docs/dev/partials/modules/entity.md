# EntityAgent Module

> Status: Implemented – entity CRUD and version lifecycle management.

## Responsibilities
- Manage entity CRUD and version lifecycle inside the active project.
- Provide helpers for listing entities, inspecting versions, and restoring history.
- Coordinate with ProjectAgent and ExportAgent for downstream workflows.

## Commands
- `entity list <project> [with_versions=1]` – List entities with summary stats (optionally include version metadata).
- `entity show <project> <entity> [@version|#commit]` – Show the current or specific version (by `@version` or commit hash `#...`).
- `entity save <project> <entity> {json}` – Persist a payload as a new version (previous active version becomes inactive).
- `entity delete <project> <entity> [purge=0]` – Archive an entity (soft delete) or purge it entirely (when `purge=1`).
- `entity restore <project> <entity> <@version|#commit>` – Reactivate a previous version and mark the entity as active again.

## Implementation Notes
- Module lives in `system/modules/entity` and depends on BrainRepository helpers (`listEntities`, `listEntityVersions`, `saveEntity`, `archiveEntity`, `deleteEntity`, `restoreEntityVersion`, `entityReport`).
- Parser supports natural CLI syntax (`entity save demo user {"name":"Alice"}`) and extracts flags/refs.
- Soft delete retains historical versions; purge removes entity and (optionally) associated commit map entries.

## Examples

### CLI
```bash
php cli.php "entity list demo"
```
```json
{
  "status": "ok",
  "action": "entity list",
  "data": {
    "project": "demo",
    "count": 3,
    "entities": [
      {"slug": "hero", "status": "active", "version_count": 7},
      {"slug": "outline", "status": "active", "version_count": 14}
    ]
  }
}
```

### REST
```bash
curl -H "Authorization: Bearer <token>" \
  "https://example.test/api.php?action=entity%20show&project=demo&entity=hero"
```
Returns the active payload (default) or a selector-driven revision when `ref=@7`/`ref=#commit` is supplied.

### PHP
```php
$payload = ['name' => 'Aerin Tal', 'role' => 'Navigator'];
$response = AavionDB::run('entity save', [
    'project' => 'demo',
    'entity' => 'hero',
    'payload' => $payload,
]);
```

## Error Handling
- Missing `project`/`entity` parameters yield `status=error` with helpful messages (REST → 400).
- Unknown entities or versions raise `StorageException` messages such as `Entity "hero" not found in project "demo".` (surfaced via `status=error`).
- Payload parsing errors (invalid JSON in CLI/REST) propagate as `status=error` with the JSON parser message in `meta.exception`.
- REST responses mirror CLI behaviour via `CommandResponse` objects.

## Outstanding Tasks
- [ ] Coordinate with ExportAgent for schema-aware exports / entity selection.
- [ ] Add cascade hooks so ProjectAgent can trigger entity archives automatically when needed.
- [ ] Add PHPUnit coverage (list/save/delete/restore scenarios, invalid references).
