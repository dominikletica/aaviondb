# EntityAgent Module

> Status: Implemented – entity CRUD and version lifecycle management.

## Responsibilities
- Manage entity CRUD and version lifecycle inside the active project.
- Provide helpers for listing entities, inspecting versions, and restoring history.
- Coordinate with ProjectAgent and ExportAgent for downstream workflows.

## Commands
- `entity list <project> [with_versions=1]` – List entities with summary stats (optionally include version metadata).
- `entity show <project> <entity> [@version|#commit]` – Show the current or specific version (by `@version` or commit hash `#...`).
- `entity save <project> <entity[@version|#commit][:fieldset[@version|#commit]]> {json} [fieldset=<id[@version|#commit]|"] [merge=1|0|replace]` – Persist a payload as a new version. Payloads merge into the selected source version (default active) and allow schema binding via inline selector (`entity slug:schema`) or `fieldset` flag; use `fieldset=""` to detach.
- `entity delete <project> <entity> [purge=0]` – Archive an entity (soft delete) or purge it entirely (when `purge=1`).
- `entity restore <project> <entity> <@version|#commit>` – Reactivate a previous version and mark the entity as active again.

## Implementation Notes
- Module lives in `system/modules/entity` and depends on BrainRepository helpers (`listEntities`, `listEntityVersions`, `saveEntity`, `archiveEntity`, `deleteEntity`, `restoreEntityVersion`, `entityReport`).
- Parser supports natural CLI syntax (`entity save demo user {"name":"Alice"}`) and extracts flags/refs. Inline schema selectors (`entity save demo user:profile`) automatically populate the `fieldset` option.
- Incremental saves merge the new payload into the selected source version (default: active). Properties set to `null` are removed; associative sub-objects merge recursively; indexed arrays are replaced wholesale. Merge sources can be pinned via `entity@13`/`entity#commit` selectors.
- JSON schemas reside in project `fieldsets`. Schema entities are linted before storage, and entity saves with a `fieldset` apply validation plus default/placeholder expansion to the merged payload.
- Schema handling lives in `system/Schema/SchemaValidator.php` (`applySchema()` / `assertValidSchema()`); adjust placeholder/default logic there if schema semantics evolve.
- Commit metadata now includes the effective `merge` flag and `fieldset`, enabling downstream tooling to reason about incremental updates.
- Schema selectors support version pins (`:fieldset@14`, `:fieldset#hash`) and are resolved before validation; both schema and merge source references are stored alongside commit metadata for diagnostics.
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
      {"slug": "hero", "status": "active", "version_count": 7, "fieldset": "character"},
      {"slug": "outline", "status": "active", "version_count": 14, "fieldset": null}
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

Partial update + schema binding + version pin:
```bash
php cli.php 'entity save demo hero@5:character@12 {"name": "Aerin", "stats": {"agility": 12}, "notes": null}'
```
Merges new data into version `@5`, drops `notes`, validates against schema `character@12`, and auto-fills optional fields through schema defaults/placeholders.

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
- Schema issues return `status=error` with details (e.g. `Schema "character" not found in project "fieldsets".` or `Payload for entity "hero" violates schema "character": Missing required property "name".`).
- Invalid merge references raise deterministic errors (`Merge source "@13" not found for entity "hero" in project "demo".`).
- REST responses mirror CLI behaviour via `CommandResponse` objects.

## Outstanding Tasks
- [ ] Add cascade hooks so ProjectAgent can trigger entity archives automatically when needed.
- [ ] Add PHPUnit coverage (list/save/delete/restore scenarios, invalid references, schema failures).
