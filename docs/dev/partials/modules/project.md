# ProjectAgent Module

> Status: Implemented – baseline lifecycle and metadata management.

## Responsibilities
- Manage project lifecycle within the active brain (list/create/archive/delete/info).
- Coordinate metadata changes that affect entity indexing and counts.
- Provide foundation for future cascade operations with `EntityAgent`.

- `list projects` ↔ `project list` – List projects with status, timestamps, entity/versions counts.
- `project create <slug> [title="My Project"] [description="Context for LLMs"]` – Create a new project (fails if slug exists).
- `project update <slug> [title="New Title"] [description="Updated guidance"]` – Revise project metadata without touching entities.
- `project remove <slug>` – Archive project (soft delete) while keeping entities in place.
- `project delete <slug> [purge_commits=1]` – Hard delete project and optionally purge related commits.
- `project info <slug>` – Show detailed project snapshot (summary + entity listing).
- `list commits <project> [entity] [limit=50]` ↔ `project commits …` – List commit metadata for a project (optional entity scope, default 50 entries).

## Call Flow
- `system/modules/project/module.php` instantiates `AavionDB\Modules\Project\ProjectAgent` and calls `register()`.  
- `ProjectAgent::registerParser()` normalises verbs (`project create`, `project delete`, aliases like `project list`) and injects slug/title/description parameters prior to dispatch.  
- Command handlers (`projectCreateCommand()`, `projectUpdateCommand()`, `projectRemoveCommand()`, `projectDeleteCommand()`, `projectInfoCommand()`, `projectCommitsCommand()`) each return `CommandResponse` objects.  
- Under the hood the handlers delegate to `BrainRepository` methods (`createProject`, `updateProjectMetadata`, `archiveProject`, `deleteProject`, `projectReport`, `listProjectCommits`). Commits use the `commits` map stored alongside project metadata.

## Key Classes & Collaborators
- `AavionDB\Modules\Project\ProjectAgent` – parser + command registrar.  
- `AavionDB\Storage\BrainRepository` – project persistence and statistics.  
- `AavionDB\Core\Modules\ModuleContext` – provides access to command registry, logger, and debug helper.  
- `AavionDB\Core\CommandResponse` – ensures a consistent response schema across CLI/REST/PHP callers.

## Implementation Notes
- Resides in `system/modules/project` and leverages `BrainRepository` helpers (`createProject`, `archiveProject`, `deleteProject`, `projectReport`, `listProjectCommits`).
- Parser rewrites `project ...` commands into structured actions and extracts `slug`, `title`, `description`, `purge_commits` flags.
- Soft delete updates status to `archived`; hard delete removes project entry and (optionally) associated commits.
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

## Error Handling
- Attempting to create a duplicate slug returns `status=error` with message `Project "<slug>" already exists.` (REST → 400).
- Missing `slug` parameter for `project update/remove/delete/info` yields `status=error` with the corresponding “Parameter "slug" is required.” message.
- Hard delete (`project delete`) propagates storage exceptions (e.g. filesystem failures) via `meta.exception`.

## Outstanding Tasks
- [ ] Coordinate with `EntityAgent` for optional cascade operations (e.g. archiving entities on project removal).
- [ ] Provide optional cascade behaviour (e.g. clean up archived entities/projects automatically once business rules are defined).
- [ ] Add PHPUnit coverage for lifecycle and failure scenarios.
