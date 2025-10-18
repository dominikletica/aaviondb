# Modules & Autoloading (DRAFT)

> **Status:** Draft  
> **Last updated:** 0.1.0-dev

## Discovery
- `ModuleLoader` scans `system/modules/*` (system scope) and `user/modules/*` (user scope).  
- Requires `module.php` returning an array definition; optional `manifest.json` supplies metadata (`name`, `version`, `author`, `autoload`, `requires`, `capabilities`, …).  
- Produces `ModuleDescriptor` objects (slug, scope, version, autoload flag, dependencies, capabilities, initializer callable, issues).

## Lifecycle
1. Discover descriptors (merge manifest + module definition).  
2. Validate mandatory fields; collect issues (missing `init`, invalid metadata).  
3. Resolve dependency graph: required modules must exist, initialise successfully, and (when pinned) match the exact version declared via `requires`. Cycles or missing modules block initialisation and surface in diagnostics.  
4. Construct a capability-scoped `ModuleContext`; modules only receive access to services they requested (and that are permitted for their scope).  
5. Execute `init()` for autoload modules, logging failures and emitting `module.initialization_failed` diagnostics. Successful modules trigger `module.initialized`.  
6. Emit diagnostics; descriptors are available via `ModuleLoader::diagnostics()` and `AavionDB::diagnose()`.

## Capabilities (Sandbox – Minimal Pass)
- System scope defaults: full access (`container.access`, `storage.read`, `storage.write`, `commands.register`, `parser.extend`, `events.dispatch`, `paths.read`, `logger.use`, `security.manage`).  
- User scope defaults: `logger.use`, `paths.read`, `events.dispatch`. Additional capabilities must be declared explicitly and are whitelisted (`commands.register`, `parser.extend`, `storage.read`, `storage.write`).  
- `ModuleContext` enforces capabilities when modules request services: lacking a capability raises a runtime exception (e.g. `storage.read` required for `brains()`, `commands.register` for `commands()`).

## Dependencies
- `requires` accepts module slugs (optionally `slug@1.2.0` for exact version pins).  
- Dependencies are initialised recursively before the requesting module runs. Missing modules, failed dependency initialisation, or version mismatches block the dependent and surface in diagnostics/events.  
- Circular relationships are detected; affected modules are quarantined with an explanatory error.

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
- Expand capability matrix (granular read/write separation, filesystem/network access) and introduce policy configuration per deployment.  
- Hot-reload support by re-running `discover()` and module-specific teardown hooks.  
- Optional “provides”/“conflicts” metadata for richer dependency graphs and feature negotiation.
