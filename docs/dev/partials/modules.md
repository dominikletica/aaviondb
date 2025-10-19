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

## Directory Layout & Namespaces
- **System modules** live in `system/modules/<slug>/`; user modules in `user/modules/<slug>/`. Slugs are lowercase, kebab-case (`core`, `project-history`).  
- Recommended namespace pattern: `AavionDB\Modules\<StudlyName>\*` (e.g. slug `core` ⇒ namespace `AavionDB\Modules\Core`). PHP files inside `classes/` are auto-required during module initialisation (no Composer autoload required).  
- Each module exposes a bootstrap entry class (optional) and command handlers within the module namespace. Keep handler classes focused (`*CommandHandler`, `*Service`).

### Baseline Structure (system module)
```
system/modules/<slug>/
├── manifest.json          # metadata + capabilities + dependencies
├── module.php             # returns definition array (required)
├── classes/               # PSR-4 classes (autoloaded via module namespace)
│   └── ExampleHandler.php
└── assets/                # optional assets (templates, UI bundles, exports)
```

## Manifest Schema (`manifest.json`)
```jsonc
{
  "name": "Core Agent",
  "version": "0.1.0-dev",
  "description": "Runtime status and diagnostics commands.",
  "autoload": true,
  "requires": [
    "brain@0.1.0-dev"
  ],
  "capabilities": [
    "commands.register",
    "parser.extend"
  ],
  "author": "Codex",
  "license": "MIT"
}
```
- **name** *(string, required)* – Human-readable module label.  
- **version** *(string, required)* – Semantic or date-based version; used for exact pinning.  
- **description** *(string, optional)* – Short summary for diagnostics.  
- **autoload** *(bool, default: true)* – Auto-initialise during bootstrap.  
- **requires** *(array<string>)* – Dependency slugs (`module` or `module@1.2.0` for strict pin).  
- **capabilities** *(array<string>)* – Additional permissions beyond scope defaults (see capability matrix).  
- Extra fields are allowed and surfaced via diagnostics (e.g. `author`, `links`, `license`).

## `module.php` Contract
```php
<?php

use AavionDB\Core\Modules\ModuleContext;

return [
    'init' => function (ModuleContext $context): void {
        $commands = $context->commands();
        $commands->register('status', function (array $parameters) use ($context) {
            // ... return array or CommandResponse
        }, [
            'description' => 'Show runtime status',
            'parser' => static function ($ctx): void {
                // optional parser hook
            },
        ]);
    },
    'commands' => [
        // optional metadata for listing/help
    ],
];
```
- The returned array may merge with manifest data (module loader handles this).  
- `init` (callable) is required for autoload modules. Keep initialisation idempotent.  
- Use `ModuleContext` to access services; the context enforces capabilities on demand.  
- Optionally expose a `commands` key describing CLI help metadata (consumed by CoreAgent).

## Command & Parameter Conventions
- Command names are lowercase, words separated by spaces (`project list`, `auth grant`).  
- Parameters follow snake_case (e.g. `project`, `entity_slug`, `payload`). Array parameters should use nested keys (`payload.title`).  
- Responses must return the established schema: `{status, action, message, data, meta}` (use `CommandResponse::fromPayload()` where practical).  
- Throwing exceptions from handlers should be avoided; return structured errors instead (`CommandResponse::error()`). Unexpected exceptions are trapped by the registry and logged.

### Validation Guidelines
- Validate required parameters early; respond with `status=error`, `message` explaining what is missing.  
- Use `BrainRepository`/`AuthManager` helpers for storage/auth mutations—never manipulate `.brain` files directly.  
- Deny unsupported values explicitly (e.g. invalid log level) and document accepted options in module docs.  
- Emit events for observable changes (`events.dispatch` capability) to keep diagnostics consistent.

## Dependencies
- `requires` accepts module slugs (optionally `slug@1.2.0` for exact version pins).  
- Dependencies are initialised recursively before the requesting module runs. Missing modules, failed dependency initialisation, or version mismatches block the dependent and surface in diagnostics/events.  
- Circular relationships are detected; affected modules are quarantined with an explanatory error.

## System Modules (Foundation Layer)
| Module | Scope | Responsibility |
|--------|--------|----------------|
| `CoreAgent` | system | Runtime meta commands (version, diagnose, setup status) |
| `BrainAgent` | system | Brain lifecycle management |
| `ProjectAgent` | system | Project indexing and metadata |
| `EntityAgent` | system | Entity versioning workflow |
| `ExportAgent` | system | Data exports and snapshots |
| `AuthAgent` | system | API token lifecycle |
| `ApiAgent` | system | REST endpoint management |
| `UiAgent` | system | Console / web UI integration |
| `LogAgent` | system | Operational log access |
| `EventsAgent` | system | Event bus inspection |
| `SchedulerAgent` | system | (Future) scheduled job orchestration |

### Module Responsibilities
- **`CoreAgent` (`core`)** – Runtime meta commands (version, diagnose, setup status)
  - Expose status/info/diagnostics
  - Provide help/command listings
  - Manage bootstrap checks
- **`BrainAgent` (`brain`)** – Brain lifecycle management
  - List/init/switch brains
  - Handle backups and integrity reports
  - Expose brain config helpers and maintenance tooling (validation/repair)
- **`ProjectAgent` (`project`)** – Project indexing and metadata
  - Create/list/remove projects
  - Update project metadata (title/description)
  - Manage project-level metadata and stats
  - Coordinate with entity agent/cascade logic
- **`EntityAgent` (`entity`)** – Entity versioning workflow
  - CRUD for entities and versions
  - Handle canonical hashing and version history
  - Restore/delete operations using `@version` / `#commit` selectors
- **`ExportAgent` (`export`)** – Data exports and snapshots
  - Provides `export <project[,project…]|*>` with optional entity/version selectors
  - Produces deterministic JSON bundles (`project.items[*]`) with payloads, hashes, and guidance for downstream tooling
  - TODO: presets/destinations and schema profiles (see module doc)
- **`AuthAgent` (`auth`)** – API token lifecycle
  - Commands: auth grant/list/revoke/reset
  - Bootstrap key rotation
  - Audit logging integration and groundwork for scoped keys/roles
- **`ApiAgent` (`api`)** – REST endpoint management
  - api serve/stop/reset commands
  - Expose REST diagnostics
  - Bridge to AuthManager and future batched/async actions
- **`UiAgent` (`ui`)** – Console / web UI integration
  - Provide integration hooks for the external **AavionStudio** project
  - Maintain minimal diagnostics/console glue only where necessary
  - Link to REST/API for optional remote execution
- **`LogAgent` (`log`)** – Operational log access
  - Commands: log view/rotate/cleanup
  - Expose auth/log diagnostics
  - Future: log streaming
- **`EventsAgent` (`events`)** – Event bus inspection
  - List listeners & emitted events
  - Subscribe modules for instrumentation
  - Diagnostics for module telemetry and live feed endpoints
- **`SchedulerAgent` (`scheduler`)** – (Future) scheduled job orchestration
  - Define scheduled command hooks
  - Integrate with cron/queue backends
  - Emit scheduler diagnostics

> Implementation TODOs are tracked centrally in `.codex/NOTES.md`.

### Module Documentation Stubs
- [`modules/core.md`](./modules/core.md) – CoreAgent details *(draft)*
- [`modules/brain.md`](./modules/brain.md) – BrainAgent *(draft)*
- [`modules/project.md`](./modules/project.md) – ProjectAgent
- [`modules/entity.md`](./modules/entity.md) – EntityAgent *(draft)*
- [`modules/export.md`](./modules/export.md) – ExportAgent
- [`modules/auth.md`](./modules/auth.md) – AuthAgent
- [`modules/api.md`](./modules/api.md) – ApiAgent
- [`modules/ui.md`](./modules/ui.md) – UiAgent *(draft)*
- [`modules/log.md`](./modules/log.md) – LogAgent *(draft)*
- [`modules/events.md`](./modules/events.md) – EventsAgent *(draft)*
- [`modules/scheduler.md`](./modules/scheduler.md) – SchedulerAgent *(draft)*

## Future Work
- Expand capability matrix (granular read/write separation, filesystem/network access) and introduce policy configuration per deployment.  
- Hot-reload support by re-running `discover()` and module-specific teardown hooks.  
- Optional “provides”/“conflicts” metadata for richer dependency graphs and feature negotiation.
