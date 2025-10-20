# EntityAgent Module

> Status: Implemented – entity CRUD, hierarchy management, and cascade handling.

## Responsibilities
- Manage entity CRUD and version lifecycle inside the active project.
- Provide helpers for listing entities, inspecting versions, and restoring history.
- Coordinate with ProjectAgent and ExportAgent for downstream workflows.
- Maintain a project-scoped hierarchy map for parent/child relationships and cascade behaviour.

## Commands
- `list entities <project> [parent/path]` ↔ `entity list <project> [parent/path] [with_versions=1]` – List entities with summary stats and optionally scope the result set to a hierarchy path.
- `entity move <project> <source-path> <target-path> [--mode=merge|replace]` – Move an entity (and its subtree) to a new hierarchy path without mutating payloads.
- `list versions <project> <entity>` ↔ `entity versions …` – List all versions for a given entity.
- `show <project> <entity[@version|#commit]>` ↔ `entity show …` – Show the active (default) or selector-driven version.
- `save <project> <entity-path[@version|#commit][:fieldset[@version|#commit]]> {json} [fieldset=<id[@version|#commit]|"" ] [merge=1|0|replace] [--parent=path]` ↔ `entity save …` – Persist a new version. Payload merges into the selected source version (default active) and can bind a schema via inline selector or `fieldset` flag; `fieldset=""` detaches. Slash-separated paths (`parent/child`) assign hierarchy; missing parents produce warnings and fall back to the deepest valid level. Supplying `--parent` without a payload reparent the entity while preserving the existing payload (merge forced).
- `remove <project> <entity-path[,entity2]> [--recursive=0|1]` ↔ `entity remove …` – Deactivate the active version of one or more entities (history retained). Without `--recursive=1`, direct children are promoted to the root level; `--recursive=1` archives the entire subtree in one command.
- `delete <project> <entity-path[,entity2]> [--recursive=0|1]` ↔ `entity delete …` – Purge entire entities (all versions + commits). Default behaviour promotes children before deleting the parent; `--recursive=1` removes every descendant and their commits.
- `delete <project> <entity@version[,entity2#commit]>` – Targeted deletion of specific versions/commits without removing the full entity.
- `restore <project> <entity@version|entity#commit>` ↔ `entity restore …` – Reactivate a specific revision and mark it as active.

## Call Flow
- `system/modules/entity/module.php` instantiates `AavionDB\Modules\Entity\EntityAgent` and calls `register()`.  
- `EntityAgent::registerParser()` plus shortcut handlers in CoreAgent convert top-level verbs (`save`, `show`, `list versions`, `remove`, `delete`) into canonical actions while extracting `project`, `entity`, selectors, parent-path segments, and schema references.  
- Command handlers map directly to repository operations:  
  - `entityListCommand()` → `BrainRepository::listEntities()` / `listEntityVersions()` (passing the optional hierarchy path when supplied)  
  - `entityMoveCommand()` → `BrainRepository::moveEntity()`
  - `entityShowCommand()` → `BrainRepository::getEntityVersion()`  
  - `entitySaveCommand()` → orchestrates merge selection, parent resolution, and persists through `saveEntity()` with schema validation.  
  - `entityRemoveCommand()` / `entityDeleteCommand()` / `entityDeleteVersionCommand()` → `deactivateEntity()` (returns cascade metadata), `deleteEntity()` (now returns cascade metadata), `deleteEntityVersion()`  
  - `entityRestoreCommand()` → `restoreEntityVersion()`  
- Schema hooks call `SchemaValidator::applySchema()` after merging payloads; debug detail is emitted through `ModuleContext::debug()` when the `--debug` flag is set.

## Key Classes & Collaborators
- `AavionDB\Modules\Entity\EntityAgent` – parser + command registrar.  
- `AavionDB\Storage\BrainRepository` – entity persistence, merge resolution, version metadata.  
- `AavionDB\Schema\SchemaValidator` – validates and enriches payloads based on attached fieldsets.  
- `AavionDB\Core\Modules\ModuleContext` – exposes command registry, logger, cache/security helpers as needed.  
- `AavionDB\Core\CommandResponse` – shared response envelope.

## Implementation Notes
- Module lives in `system/modules/entity` and depends on BrainRepository helpers (`listEntities`, `listEntityVersions`, `saveEntity`, `moveEntity`, `deactivateEntity`, `deleteEntity`, `deleteEntityVersion`, `restoreEntityVersion`, `purgeInactiveEntityVersions`, `entityReport`).
- Parser supports natural CLI syntax as well as top-level shortcuts (`save`, `show`, `list versions`, `remove`, `delete`); inline schema selectors (`entity save demo user:profile`) automatically populate the `fieldset` option.
- Incremental saves merge the new payload into the selected source version (default: active). Properties set to `null` are removed; associative sub-objects merge recursively; indexed arrays are replaced wholesale. Merge sources can be pinned via `entity@13`/`entity#commit` selectors.
- If no payload is supplied but a `--parent` override is present, the module forces a merge so the existing payload is preserved while reparenting the entity (a new version is still recorded).
- JSON schemas reside in project `fieldsets`. Schema entities are linted before storage, and entity saves with a `fieldset` apply validation plus default/placeholder expansion to the merged payload.
- Schema handling lives in `system/Schema/SchemaValidator.php` (`applySchema()` / `assertValidSchema()`); adjust placeholder/default logic there if schema semantics evolve.
- Commit metadata now includes the effective `merge` flag and `fieldset`, enabling downstream tooling to reason about incremental updates.
- Schema selectors support version pins (`:fieldset@14`, `:fieldset#hash`) and are resolved before validation; both schema and merge source references are stored alongside commit metadata for diagnostics.
- Hierarchy data lives in `projects[slug].hierarchy` (`parents`/`children` maps). `saveEntity()` resolves parent chains, enforces the configurable `hierarchy.max_depth` (set via `set hierarchy.max_depth <n>`), prevents cycles, and surfaces warnings when segments are skipped.
- `listEntities()` / `entityReport()` expose `parent`, `path`, and `path_string` fields so UI layers can navigate hierarchies without additional lookups.
- `moveEntity()` reassigns hierarchy while preserving payloads/versions; the CLI surfaces warnings when unsupported modes are supplied (currently `replace` behaves like `merge`).
  Returned metadata includes `old_path`, `new_path`, and their string representations so callers can update navigation structures quickly.
- Soft delete via `remove` retains historical versions; `delete` purges entire entities or specific revisions and updates the commit map accordingly. Both operations emit cascade metadata (`warnings`, `promoted_children`, `affected_descendants`/`deleted_descendants`) for downstream visibility.
- `brain cleanup` and `delete <project> <entity@version>` reuse the same repository helpers to keep commit metadata in sync.

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

```bash
php cli.php "entity list demo factions/air-wing"
```
Returns only the entities that live under the `factions/air-wing` hierarchy. Add `with_versions=1` to include version metadata per child entity.

```bash
php cli.php "entity move demo factions/air-wing/alpha factions/veterans"
```
Moves the entire `alpha` subtree beneath `factions/veterans`.

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

Hierarchy-aware save + recursive removal:
```bash
php cli.php 'entity save demo factions/air-wing/alpha {"label":"Alpha Squadron"}'
php cli.php 'entity remove demo factions/air-wing --recursive=1'
```
The save call assigns `alpha` to the parent path `factions/air-wing`. The removal archives the entire subtree and returns cascade metadata listing the affected descendants.

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
- Hierarchy validation problems (cycle attempts, missing parents, depth truncation) and cascade results are surfaced as `warnings` inside both the response payload and `meta.warnings`; callers should display or log them.
- Unsupported move modes return `status=error` with the message `Unsupported mode "..." (use merge or replace).`.
- REST responses mirror CLI behaviour via `CommandResponse` objects.

## Outstanding Tasks
- [x] Add cascade hooks so ProjectAgent can trigger entity archives automatically when needed.
- [ ] Provide hierarchy introspection helpers (e.g. list subtree, move subtree) once requirements are finalised.
- [ ] Add PHPUnit coverage (list/save/delete/restore scenarios, invalid references, schema failures, hierarchy edge cases).
