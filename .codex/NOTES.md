# AavionDB – Developer Notes

> Maintainer: Codex (GPT-5)  
> Purpose: Track implementation decisions, open questions, and follow-up tasks during core development.

## 2025-02-14

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

## 2025-02-14 – Evening Session

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

## 2025-02-15

- Hardened façade bootstrap: `AavionDB::setup()` is now idempotent/lazy and runtime accessors auto-bootstrap. Added `AavionDB::auth()` accessor.
- Introduced `Core\Security\AuthManager` and expanded `BrainRepository` schema (`auth`, `api` sections) with atomic token usage tracking.
- `api.php` now enforces REST gating (API flag + bearer tokens); bootstrap token `admin` is blocked for REST.
- Documentation refreshed (core architecture, entry points, authentication, security) to outline the new flow. Remaining TODOs: implement auth/api commands + log module.
- Added `ModuleContext` + lifecycle: ModuleLoader now initialises autoload modules, emits telemetry, and exposes status via diagnostics.
- `BrainRepository` exposes helpers for token lifecycle (`registerAuthToken`, `revokeAuthToken`, `updateBootstrapKey`, `setApiEnabled`) so modules can mutate security state safely.
- Minimal sandbox/dependency pass: ModuleLoader enforces exact dependency availability (with cycle detection) and capability whitelists (system defaults vs user opt-in). `ModuleContext` throws when modules misuse restricted services.
- Documented module scaffolding (directory layout, namespace guidance, manifest schema, command/parameter conventions) and created per-module TODO checklists for upcoming implementation phases.
