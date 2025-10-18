# ProjectAgent Module (DRAFT)

> Status: Draft – implementation in progress.

## Responsibilities
- Manage project lifecycle within the active brain (list/create/archive/delete/info).
- Coordinate metadata changes that affect entity indexing and counts.
- Provide foundation for future cascade operations with `EntityAgent`.

## Commands
- `project list` – List projects with status, timestamps, entity counts, version counts.
- `project create <slug> [title="My Project"]` – Create a new project (fails if slug exists).
- `project remove <slug>` – Archive project (soft delete) while keeping entities in place.
- `project delete <slug> [purge_commits=1]` – Hard delete project and optionally purge related commits.
- `project info <slug>` – Show detailed project snapshot (summary + entity listing).

## Implementation Notes
- Resides in `system/modules/project` and leverages `BrainRepository` helpers (`createProject`, `archiveProject`, `deleteProject`, `projectReport`).
- Parser rewrites `project ...` commands into structured actions and extracts `slug`, `title`, `purge_commits` flags.
- Soft delete updates status to `archived`; hard delete removes project entry and (optionally) associated commits.
- Reports expose `entity_count` & `version_count` to assist diagnostics/UI layers.

## Outstanding Tasks
- [ ] Coordinate with `EntityAgent` for optional cascade operations (e.g. archiving entities on project removal).
- [ ] Implement `project cleanup` if we later add automatic purging for archived entities.
- [ ] Add PHPUnit coverage for lifecycle and failure scenarios.
