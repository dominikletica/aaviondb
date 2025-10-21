# ProjectAgent Module

> **Status:** Implemented – lifecycle, metadata, and archive cascades in place.  
> **Last updated:** 2025-10-20

## Responsibilities
- Manage project lifecycle within the active brain (list/create/archive/delete/restore/info).
- Coordinate metadata changes that affect entity indexing and counts.
- Trigger entity cascades when archiving so active entities are deactivated alongside the project.
- Restore archived projects and optionally reactivate entities in one step.
- Provide foundation for future cascade refinements with `EntityAgent`.

- `list projects` ↔ `project list` – List projects with status, timestamps, entity/versions counts.
- `project create <slug> [title="My Project"] [description="Context for LLMs"]` – Create a new project (fails if slug exists).
- `project update <slug> [title="New Title"] [description="Updated guidance"]` – Revise project metadata without touching entities.
- `project remove <slug>` – Archive project (soft delete) while keeping entities in place.
- `project restore <slug> [--reactivate=0|1]` – Reactivate archived projects and (optionally) bring entity versions back online.
- `project delete <slug> [purge_commits=1]` – Hard delete project and optionally purge related commits.
- `project info <slug>` – Show detailed project snapshot (summary + entity listing).
- `list commits <project> [entity] [limit=50]` ↔ `project commits …` – List commit metadata for a project (optional entity scope, default 50 entries).

## Call Flow
- `system/modules/project/module.php` instantiates `AavionDB\Modules\Project\ProjectAgent` and calls `register()`.  
- `ProjectAgent::registerParser()` normalises verbs (`project create`, `project delete`, aliases like `project list`) and injects slug/title/description parameters prior to dispatch.  
- Command handlers (`projectCreateCommand()`, `projectUpdateCommand()`, `projectRemoveCommand()`, `projectRestoreCommand()`, `projectDeleteCommand()`, `projectInfoCommand()`, `projectCommitsCommand()`) each return `CommandResponse` objects.  
- Under the hood the handlers delegate to `BrainRepository` methods (`createProject`, `updateProjectMetadata`, `archiveProject`, `restoreProject`, `deleteProject`, `projectReport`, `listProjectCommits`). Commits use the `commits` map stored alongside project metadata.

## Key Classes & Collaborators
- `AavionDB\Modules\Project\ProjectAgent` – parser + command registrar.  
- `AavionDB\Core\Storage\BrainRepository` – project persistence and statistics.  
- `AavionDB\Core\Modules\ModuleContext` – provides access to command registry, logger, and debug helper.  
- `AavionDB\Core\CommandResponse` – ensures a consistent response schema across CLI/REST/PHP callers.

## Implementation Notes
- Resides in `system/modules/project` and leverages `BrainRepository` helpers (`createProject`, `archiveProject`, `restoreProject`, `deleteProject`, `projectReport`, `listProjectCommits`).
- Parser rewrites `project ...` commands into structured actions and extracts `slug`, `title`, `description`, `purge_commits` flags.
- Soft delete updates project status to `archived`, stamps metadata, and asks `BrainRepository` to mark every entity inactive (including active versions) so downstream exports no longer surface stale data. Restore flips the status back to `active`, clears `archived_at`, and (when requested) calls `restoreProject()` to reactivate entities by promoting their most recent version. Hard delete removes the project entry and (optionally) associated commits.
- `BrainRepository` persists title/description metadata (`createProject`, `updateProjectMetadata`) so exports and LLM guides can surface author-provided context.
- Reports expose `description`, `entity_count`, and `version_count` to assist diagnostics/UI layers.

## Examples

### CLI
```bash
php cli.php "project create demo title=\"Storyworld\" description=\"Narrative workspace\""
```
```json
{
  "status": "ok",
  "action": "project create",
  "message": "Project \"demo\" created.",
  "data": {
    "project": {
      "slug": "demo",
      "title": "Storyworld",
      "description": "Narrative workspace",
      "entity_count": 0,
      "version_count": 0
    }
  }
}
```

### REST
```bash
curl -H "Authorization: Bearer <token>" \
  "https://example.test/api.php?action=project%20info&slug=demo"
```
Returns the same payload as the CLI with HTTP 200.

### PHP
```php
$response = AavionDB::run('project list');
```

### Restore Example
```bash
php cli.php "project restore demo --reactivate=1"
```
Returns the refreshed project summary plus a list of entities that were reactivated. Warnings surface when entities have no versions to re-enable.

## Error Handling
- Attempting to create a duplicate slug returns `status=error` with message `Project "<slug>" already exists.` (REST → 400).
- Missing `slug` parameter for `project update/remove/restore/delete/info` yields `status=error` with the corresponding “Parameter "slug" is required.” message.
- Attempting to restore an already-active project returns `status=error` with `Project "<slug>" is already active.`
- Hard delete (`project delete`) propagates storage exceptions (e.g. filesystem failures) via `meta.exception`.

## Outstanding Tasks
- [x] Coordinate with `EntityAgent` for cascade operations (archiving entities on project removal).
- [x] Provide a `project restore` command for reactivating archived projects (with optional entity reactivation).
- [ ] Provide optional advanced cascade behaviour (e.g. auto-cleanup or notifications once business rules are defined).
- [ ] Add PHPUnit coverage for lifecycle and failure scenarios (including restore flows).
