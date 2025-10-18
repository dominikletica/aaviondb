# EntityAgent Module (DRAFT)

> Status: Draft – implementation in progress.

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
- REST responses mirror CLI behaviour via `CommandResponse` objects.

## Outstanding Tasks
- [ ] Coordinate with ExportAgent for schema-aware exports / entity selection.
- [ ] Add cascade hooks so ProjectAgent can trigger entity archives automatically when needed.
- [ ] Add PHPUnit coverage (list/save/delete/restore scenarios, invalid references).
