# CoreAgent Module

> **Status:** Implemented – runtime metadata commands are available across CLI/REST/PHP.  
> **Last updated:** 2025-10-20

## Responsibilities
- Register runtime meta commands (`status`, `diagnose`, `help`) available across CLI/REST/PHP.
- Provide command metadata for auto-generated listings (consumed by `help`).
- Surface high-level diagnostics for other modules (module count, brain state, API flags).

## Commands
- `status`  
  Returns a concise snapshot: framework version, boot timestamp, active brain info, API enablement, module list, initialization errors, and brain footprint (file size + entity-version count).
- `diagnose`  
  Full diagnostic payload (`AavionDB::diagnose()`), exposing paths, modules, parser stats, brain integrity, etc.
- `help [command=name]`  
  Lists all registered commands (sorted) or shows detailed metadata for a specific command.

## Call Flow
- `system/modules/core/module.php` instantiates `AavionDB\Modules\Core\CoreAgent` and calls `CoreAgent::register()`.  
- `CoreAgent::registerGlobalParsers()` injects the global `--debug` flag, while `registerShortcutParsers()` rewrites top-level verbs (`list`, `save`, `show`, `remove`, `delete`) to the responsible agents before the command parser runs.  
- Command handlers live in `CoreAgent::registerStatusCommand()`, `registerDiagnoseCommand()`, and `registerHelpCommand()`, each returning `CommandResponse` instances.  
- Diagnostics are gathered via `Bootstrap::runtimeState()` and `AavionDB::diagnose()`, then handed back to the caller unchanged to keep REST/CLI/PHP behaviour aligned.

## Key Classes & Collaborators
- `AavionDB\Modules\Core\CoreAgent` – central registrar for parsers and commands.  
- `AavionDB\Core\Modules\ModuleContext` – supplies command registry, logger, diagnostics.  
- `AavionDB\AavionDB` facade – queried for runtime/diagnostics data.  
- `AavionDB\Core\CommandResponse` – shared response envelope returned by every handler.

## Examples

### CLI
```bash
php cli.php "status"
```
```json
{
  "status": "ok",
  "action": "status",
  "message": "AavionDB status snapshot",
  "data": {
    "framework": {"version": "0.1.0-dev"},
    "brain": {"active": {"slug": "default"}},
    "modules": {"count": 6, "items": [{"slug": "core", "issues": []}]}
  },
  "meta": {}
}
```

### REST
```bash
curl -H "Authorization: Bearer <token>" \
  "https://example.test/api.php?action=status"
```
HTTP `200` with the same payload as above.

### PHP
```php
$response = AavionDB::run('help', ['command' => 'project create']);
```

## Error Handling
- Unknown command (`help foo`) returns `status=error`, message `Unknown command "foo".` (REST → 400).
- Diagnostics retrieval errors are captured in `data.modules.errors` while keeping the overall response `status=ok`.
- All responses follow the shared envelope described in `docs/dev/partials/error-handling.md`.

## Implementation Notes
- Delivered via `system/modules/core`; `CoreAgent` registers handlers in `module.php`.
- System module capabilities: `commands.register`, `parser.extend`, `events.dispatch`, `paths.read`, `logger.use`.
- Responses leverage `CommandResponse` for consistent schema.
