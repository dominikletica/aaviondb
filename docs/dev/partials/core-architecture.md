# Core Architecture (DRAFT)

> **Status:** Draft – consolidated from the previous `core-architecture.md`

## Runtime Boot Sequence
- `AavionDB::setup()` is idempotent; first call performs full bootstrap, subsequent calls return immediately.  
- Steps:
  1. Ensure directory structure via `PathLocator` (system modules, storage, logs, user dirs).  
  2. Register base services (logger, event bus, parser, command registry, brain repository, module loader).  
  3. Mount `system.brain`; create default structure if missing.  
  4. Ensure active user brain (default slug) exists and mount it.  
  5. Discover system modules (autoload) and user modules.  
  6. Wire diagnostics (brain integrity, module metadata, command stats).  
  7. REST remains disabled until `api serve` succeeds.
- Bootstrap failures must be logged and surfaced as structured error responses; callers should catch `BootstrapException`.

## Core Namespace Layout
```
system/
├── core.php                # Façade (AavionDB)
├── Core/
│   ├── Bootstrap.php       # Boot pipeline
│   ├── Container.php       # Lightweight service locator
│   ├── CommandRegistry.php # Command management
│   ├── CommandParser.php   # Statement parser
│   ├── Modules/            # Module loader & descriptors
│   ├── Logging/            # Logger factory
│   ├── Security/AuthManager.php
│   ├── EventBus.php        # Publish/subscribe hub
│   ├── RuntimeState.php    # Diagnostics snapshot
│   ├── Filesystem/PathLocator.php
│   ├── Hashing/CanonicalJson.php
│   ├── Exceptions/         # Exception hierarchy
│   └── Support/            # Helpers (Arr, etc.)
└── Storage/
    └── BrainRepository.php # Brain persistence
```

## Façade (`system/core.php`)
- Public methods: `setup`, `run`, `command`, `registerParserHandler`, `diagnose`, `events`, `commands`, `parser`, `brains`, `logger`, `isBooted`.  
- Public methods: `setup`, `run`, `command`, `registerParserHandler`, `diagnose`, `events`, `commands`, `parser`, `brains`, `logger`, `auth`, `isBooted`.  
- `run`/`command` wrap handlers in `try/catch`, log failures, and always return the unified response array.  
- `command` uses `CommandParser` to handle CLI-style statements (includes JSON payload detection).  
- `setup()` is idempotent and lazily invoked by all accessors, so consumers can rely on `AavionDB::run()` without an explicit bootstrap call.

## Canonical JSON & Hashing
- `CanonicalJson::encode()` sorts associative keys, preserves indexed arrays, and throws `JsonException` on failure.  
- `CanonicalJson::hash()` returns SHA-256 hash of the canonical JSON string.  
- Used for entity payload hashing, commit IDs, and integrity checks.

## Brain Storage Model
- System + user brains share the schema:
  - `meta`, `projects`, `commits`, `config`, `state`.  
  - Entities contain version map (`version`, `hash`, `commit`, `status`, `payload`, `meta`, timestamps).  
- Repository responsibilities:
  - CRUD helpers (`listProjects`, `listEntities`, `saveEntity`, `getEntityVersion`).  
  - Config API (`setConfigValue`, `getConfigValue`, `deleteConfigValue`, `listConfig`).  
  - Atomic writes with integrity verification; on mismatch → retry + log failure.  
  - Emit events (`brain.entity.saved`, `brain.created`, etc.) and populate integrity telemetry for diagnostics.

## Command Registry & Parser
- `CommandRegistry::dispatch()` normalises names, wraps handler execution, logs unexpected exceptions, emits telemetry events, and returns `CommandResponse`.  
- Parser supports global/action-specific handlers; modules extend syntax via `registerParserHandler` metadata or direct API.  
- Responses always include `status`, `action`, `message`, `data`, `meta`.

## Module Loader
- Scans `system/modules` + `user/modules`; loads `manifest.json` and `module.php`.  
- Generates `ModuleDescriptor` (slug, scope, version, dependencies, autoload flag, initializer, issues).  
- Exposes diagnostics and caches results; future work includes dependency resolution and init lifecycle.

## Diagnostics
- `RuntimeState::diagnostics()` aggregates paths, command stats, parser metrics, module data, brain integrity, etc.  
- `BrainRepository::integrityReport()` and `ModuleLoader::diagnostics()` surface detailed insights for dashboards/tests.  
- Command execution generates telemetry events; modules can consume them.  
- TODO: integrate with planned log module / diagnostics UI.

## Authentication Management
- `Security/AuthManager` centralises bootstrap token handling, REST gating, and token audit logging.  
- Auth state lives in `system.brain` (`auth` + `api` sections). Bootstrap token `admin` remains active until a user token is generated and activated.  
- REST access is blocked while `api.enabled = false` or when only the bootstrap key exists. Valid requests must supply `Authorization: Bearer <token>` (or `X-API-Key` header).  
- Successful REST calls update token usage metadata via `BrainRepository::touchAuthKey()` and surface telemetry through diagnostics for future log modules.

## Entry Points
- `api.php` (REST/PHP) and `cli.php` (CLI) both call `setup()`, handle errors gracefully, and apply consistent response schema.  
- REST requires `api_enabled = true` and non-bootstrap keys; CLI/PHP bypass keys but log actions.

## Pending Work
- Define module dependency resolution and sandboxing.  
- Flesh out security partial (permissions model).  
- Add unit/integration tests for parser, storage, auth workflows.  
- Implement system log module for viewing/rotating Monolog outputs.
