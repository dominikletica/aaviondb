# Modules & Autoloading (DRAFT)

> **Status:** Draft  
> **Last updated:** 0.1.0-dev

## Discovery
- `ModuleLoader` scans `system/modules/*` (system scope) and `user/modules/*` (user scope).  
- Requires `module.php` returning an array definition; optional `manifest.json` supplies metadata (`name`, `version`, `author`, `autoload`, `requires`, …).  
- Produces `ModuleDescriptor` objects (slug, scope, version, autoload flag, dependencies, initializer callable, issues).

## Lifecycle
1. Discover descriptors (merge manifest + module definition).  
2. Validate mandatory fields; collect issues (missing `init`, invalid metadata).  
3. Register parser/command handlers automatically when `autoload = true`.  
4. Execute `init()` to allow modules to interact with `CommandRegistry`, `EventBus`, `Logger`, etc.  
5. Emit diagnostics; descriptors are available via `ModuleLoader::diagnostics()` and `AavionDB::diagnose()`.

## System Modules (Foundation Layer)
| Module | Responsibility (draft) |
|--------|------------------------|
| `CoreAgent` | Setup/status/info commands, bootstrap orchestration |
| `BrainAgent` | Brain management (`init`, `brains`, backups) |
| `ProjectAgent` | CRUD for projects |
| `EntityAgent` | Entity lifecycle, versioning |
| `ExportAgent` | Export/backup to JSON |
| `AuthAgent` | API key lifecycle (`auth grant/list/revoke/reset`, `api serve/stop`) |
| `ApiAgent` | REST-facing glue, request validation |
| `UiAgent` | Web UI integration hooks |
| `LogAgent` *(planned)* | Log inspection/rotation commands (`log view`, `log rotate`, `log cleanup`) |

System modules initialise before user modules to guarantee availability of core commands and diagnostics.

## Future Work
- Dependency resolution (topological ordering) and optional capabilities (e.g., module provides `events`, `scheduler`).  
- Hot-reload support by re-running `discover()` and module-specific teardown hooks.  
- Sandboxing for untrusted modules and capability-based permissions.
