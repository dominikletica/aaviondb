# AavionDB – Developer Notes

> Maintainer: Codex (GPT-5)  
> Purpose: Track implementation decisions, open questions, and follow-up tasks during core development.

## 2025-10-17

- **Canonical JSON & Hashing**  
  - Sort associative keys recursively; keep numeric arrays ordered.  
  - Encode via `json_encode` with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.  
  - Hash using `sha256` over canonical JSON; store lowercase hex string alongside payload metadata.  
  - Pretty-printing is reserved for UI/export layers only.

- **Core Layout**  
  - Introduced `docs/dev/core-architecture.md` as the authoritative blueprint for the bootstrap, storage, and runtime services.  
  - Core namespace will live under `system/Core/*`; storage abstractions under `system/Storage/*`.

- **Pending**  
  1. Flesh out `BrainRepository` with entity/project CRUD and hash handling.  
  2. Add diagnostics coverage for brain integrity checks.  
  3. Define module registration workflow for parser handlers.

## 2025-10-18 – Morning Session

- Implemented base façade (`system/core.php`) with bootstrap, command dispatch, diagnostics wiring, and test-only reset helper.
- Added core services: `Container`, `CommandRegistry`, `CommandResponse`, `EventBus`, `RuntimeState`, and helper utilities.
- Created filesystem scaffolding (`PathLocator`) and initial storage layer (`BrainRepository`) including automatic system/user brain creation with canonical JSON persistence.
- Updated developer manual to link to the new core architecture blueprint.
- Added extensible command parsing pipeline (CommandParser + ParserContext), routed facade command execution through structured parsing, and exposed parser diagnostics.
- Expanded `BrainRepository` with project/entity CRUD, deterministic version/commit handling, and shared commit lookup map; docs updated with the new lifecycle description.
- Command registry now links to the parser service, allowing modules to register handlers via metadata or API helpers.
- Brain writes now use locked temp files + atomic rename with post-write verification (SHA-256 + canonical JSON). Emits retry/completed/integrity_failed events and retries once before raising `StorageException`.
- Added `BrainRepository::integrityReport()` and wired it into diagnostics; write success/failure metadata is tracked in-memory and exposed via `AavionDB::diagnose()`.
- Facade now offers helper accessors (`AavionDB::parser()`, `AavionDB::brains()`, `AavionDB::registerParserHandler()`) to make module integration simpler.
- Brain schema now includes a shared `config` map; repository helpers implement `get/set/delete/list` for both system and user brains with key normalisation.
- Introduced Monolog-based logging; `AavionDB::logger()` exposes the PSR-3 instance writing to `system/storage/logs/aaviondb.log` with configurable levels.
- Added foundational module discovery (`ModuleLoader`); descriptors capture manifest/definition metadata and surface diagnostics for both system and user module trees.
- Command registry now normalises responses, emits `command.executed/failed` diagnostics, and logs unexpected exceptions before returning consistent error payloads.
- Added REST (`api.php`) and CLI (`cli.php`) entry points with defensive error handling and consistent JSON responses.
- TODO: System-level log module (commands `log view`, `log rotate`, `log cleanup`, …) to expose/manipulate Monolog output.
- TODO: Flesh out `docs/dev/partials/security.md` with sandboxing/permission model once defined.

## 2025-10-18 – Afternoon Session

- Hardened façade bootstrap: `AavionDB::setup()` is now idempotent/lazy and runtime accessors auto-bootstrap. Added `AavionDB::auth()` accessor.
- Introduced `Core\Security\AuthManager` and expanded `BrainRepository` schema (`auth`, `api` sections) with atomic token usage tracking.
- `api.php` now enforces REST gating (API flag + bearer tokens); bootstrap token `admin` is blocked for REST.
- Documentation refreshed (core architecture, entry points, authentication, security) to outline the new flow. Remaining TODOs: implement auth/api commands + log module.
- Added `ModuleContext` + lifecycle: ModuleLoader now initialises autoload modules, emits telemetry, and exposes status via diagnostics.
- `BrainRepository` exposes helpers for token lifecycle (`registerAuthToken`, `revokeAuthToken`, `updateBootstrapKey`, `setApiEnabled`) so modules can mutate security state safely.
- Minimal sandbox/dependency pass: ModuleLoader enforces exact dependency availability (with cycle detection) and capability whitelists (system defaults vs user opt-in). `ModuleContext` throws when modules misuse restricted services.
- Documented module scaffolding (directory layout, namespace guidance, manifest schema, command/parameter conventions) and created per-module TODO checklists for upcoming implementation phases.
- ModuleLoader now auto-loads `classes/` PHP files per module via manual require, ensuring deployments work without Composer.

### Module Implementation Roadmap (WIP)

**Shared tasks (apply to every module)**
- [ ] Scaffold `system/modules/<slug>/manifest.json` + `module.php` with declared capabilities/dependencies.
- [ ] Register commands via `CommandRegistry` + parser hooks (consistent naming, unified response schema).
- [ ] Emit module diagnostics, log meaningful events, update docs/CHANGELOG, and create PHPUnit stubs.

**CoreAgent (`core`)**
- [ ] Implement `status`, `diagnose`, `help`; provide command metadata for auto-help listings.

**BrainAgent (`brain`)**
- [ ] Commands: `brains`, `brain init`, `brain switch`, `brain backup`; surface integrity report.

**ProjectAgent (`project`)**
- [ ] Commands: `project list/create/remove/info`; coordinate cascade effects with EntityAgent.

**EntityAgent (`entity`)**
- [ ] Commands: `entity list/show/save/delete/restore`; enforce hashing + version semantics.

**ExportAgent (`export`)**
- [ ] Parser for `export {project} [entity[,entity]]` + optional `:version`/`:hash` selectors.
- [ ] Generate export payloads (project, subsets, or entire brain via `project=*`).
- [ ] Manage presets/destinations; prepare Scheduler hooks for async exports.

**AuthAgent (`auth`)**
- [ ] Commands: `auth grant/list/revoke/reset` using repository helpers.
- [ ] Integrate audit logging (LogAgent) + bootstrap key guidance.

**ApiAgent (`api`)**
- [ ] Commands: `api serve/stop/status/reset`; validate REST readiness before enabling.

**UiAgent (`ui`)**
- [ ] Scaffold CLI/HTTP console stubs; bridge to REST/API for optional remote execution.

**LogAgent (`log`)**
- [ ] Commands: `log view <level>`, `log rotate`, `log cleanup` with filters/pagination.
- [ ] Integrate with future log storage abstraction.

**EventsAgent (`events`)**
- [ ] Commands: `events list`, `events stats`, optional subscription hooks; surface EventBus telemetry.

**SchedulerAgent (`scheduler`)** *(future)*
- [ ] Define scheduled job abstraction (cron-like spec + persistence).
- [ ] Hook into LogAgent for execution audits and expose registration API for other modules.
- [ ] Provide CLI commands (`scheduler list`, `scheduler run`, `scheduler enable/disable`) for cron/automation integration.

> Next up after the break: begin with **CoreAgent** to establish baseline command conventions; other modules will follow in the listed order unless priorities shift.
