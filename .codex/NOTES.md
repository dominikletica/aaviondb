# AavionDB – Developer Notes

> Maintainer: Codex (GPT-5)  
> Purpose: Track implementation decisions, open questions, and follow-up tasks during core development.

## TODO Overview

### Core Platform
- [x] Flesh out `BrainRepository` with entity/project CRUD and hash handling.
- [ ] Add diagnostics coverage / automated checks for brain integrity (tests + tooling).
- [x] Define module registration workflow for parser handlers via `CommandRegistry`.
- [ ] Ship system log module (`log view/rotate/cleanup`).
- [ ] Complete security documentation (`docs/dev/partials/security.md`).

### Module Checklist
- **Shared tasks**: [ ] Standardise manifest/module scaffolding for remaining modules; [ ] emit diagnostics + logging hooks; [ ] add PHPUnit coverage once prototype stabilises.
- **CoreAgent (`core`)**: [x] Implement `status`, `diagnose`, `help` with metadata.
- **BrainAgent (`brain`)**: [x] Core commands (`brains`, `brain init/switch/backup/info/validate`); [ ] add compaction/repair utilities; [ ] optional cleanup command for inactive versions.
- **ProjectAgent (`project`)**: [x] Lifecycle commands; [x] metadata update support; [ ] cascade coordination with EntityAgent.
- **EntityAgent (`entity`)**: [x] CRUD/version commands with selectors; [ ] cascade coordination with ProjectAgent.
- **EntityAgent (`entity`)**: [x] Support incremental `save` merges (partial payload updates with empty values deleting fields, schema validation after merge); [ ] allow schema selectors to target historical revisions (e.g. `fieldset@13` / `#hash`) and evaluate merges against non-active entity versions.
- **ConfigAgent (`config`)**: [x] `set`/`get` commands for user/system config; [ ] advanced value typing + bulk import/export; [ ] audit trail integration.
- **ExportAgent (`export`)**: [x] Parser + CLI exports for single/multi projects; [x] Preset-driven selection & payload transforms; [ ] RegEx support for preset payload filters; [ ] Export destinations & scheduler hooks; [ ] Advanced export profiles (LLM/schema aware).
- **AuthAgent (`auth`)**: [x] Token lifecycle commands; [ ] integrate audit logging + bootstrap key guidance; [ ] prepare scoped key/role management.
- **ApiAgent (`api`)**: [x] `serve/stop/status/reset`; [ ] batched/async hooks.
- **UiAgent (`ui`)**: [ ] Studio integration hooks; [ ] optional console stubs.
- **LogAgent (`log`)**: [x] Tail/framework log filtering; [ ] rotation/cleanup commands; [ ] log storage abstraction.
- **EventsAgent (`events`)**: [ ] Event stream commands/stats; [ ] subscription/feed endpoints.
- **SchedulerAgent (`scheduler`)** (future): [ ] Job abstraction; [ ] log/audit integration; [ ] CLI management commands.
- **SchemaAgent (`schema`)**: [x] Baseline list/show/lint commands; [ ] create/update fieldset helpers (wrapper around `entity save fieldsets <slug>` with lint + metadata scaffold); [ ] Studio integration hooks; [ ] cached lint results & metrics.

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

> Legacy module roadmap moved to the TODO overview at the top of this document.

## 2025-10-18 – Evening Session

- Session kickoff: focus on CoreAgent implementation (status/diagnostics/help) using the updated module plan.
- Implemented CoreAgent module (`system/modules/core`): registers `status`, `diagnose`, and `help` commands with module-aware metadata, brain footprint metrics, and status snapshot.
- Updated docs to reflect AavionStudio integration (external UI) and unified `@version` selector semantics.
- Added BrainAgent module (`system/modules/brain`): brains/list/init/switch/backup/info/validate with BrainRepository extensions (listBrains, createBrain, etc.).
- Added ProjectAgent module (`system/modules/project`): project lifecycle (list/create/remove/delete/info) using repository helpers + soft/hard delete semantics.
- Introduced `config.php` (based on `config.example.php`) for admin secret, default brain, storage paths, export behaviour, and API key length. Config is loaded at bootstrap and shared via container.
- Finalised AuthAgent baseline: REST requests now execute inside scoped contexts and BrainRepository enforces project filters for token scopes; documentation updated to reflect completion.

## 2025-10-19 – Morning Session

- Implemented ApiAgent module with `api serve/stop/status/reset`, including readiness checks, telemetry output, and token reset convenience command; docs and README aligned with the new workflow. Next focus: API scheduling hooks and test coverage for Auth/Api agents.
- Added ExportAgent baseline: parser + command wire up exports for single/all projects, entity/commit selectors, and payload aggregation; documentation and README updated with usage and response structure.
- Extended ProjectAgent to store project descriptions and expose `project update`; ExportAgent now accepts per-export descriptions, emits guide/policy blocks, and only includes the requested (or active) entity versions in each slice.
- ExportAgent payload shape unified (`project.items[*]`) with multi-project CSV support, preset-driven selectors/transformers, CLI usage hints, and deterministic counts/hashes for cache-aware LLM ingest.

## 2025-10-19 – Afternoon Session

- Session kickoff: document preset roadmap and track regex filter support for export payload matching.
- Implemented incremental entity saves with schema-aware validation: BrainRepository merges payload diffs, honours explicit null removals, applies fieldset-selected JSON Schemas (including placeholder/default expansion), and rejects invalid schema definitions in `fieldsets`. EntityAgent now parses `slug:schema` selectors, optional `fieldset`/`merge` flags, and surfaces merge metadata in responses.
- Extended selectors: `entity@version`/`#commit` and `fieldset@version`/`#commit` are honoured during saves; merge source and schema references are persisted for diagnostics.
- Introduced SchemaAgent module (list/show/lint) leveraging the shared SchemaValidator; docs updated and TODOs adjusted for future enhancements.

### Design Notes – Entity Partial Saves & Schema Validation
- Merge flow: load the current active entity version, deep-copy its payload, overlay incoming fields (associative arrays merge recursively, indexed arrays replace wholesale), and treat explicit `null` values as deletion markers (remove key from merged payload).
- Persisted version: after merge, rebuild canonical JSON, compute hash, and append as the next version so history stays complete; include a `merge` flag in the version metadata for diagnostics.
- Determinism: REST/CLI clients should send only changed fields; deletions must pass explicit empties to avoid accidental data loss. Document the contract in MANUAL + API reference.
- Fieldset handling: entity metadata gains `fieldset` (schema ID). On save, resolve `fieldsets` project inside the active brain, load schema payload (standard JSON Schema), and validate the merged payload. Missing optional fields get auto-filled with empty values defined by schema defaults or type-appropriate fallbacks.
- Validation lifecycle: schema entities linted against JSON Schema meta-schema on write; entity save aborts with structured error if fieldset not found, schema invalid, or merged payload fails validation.
- Open questions: decide where merge/delete semantics live (`BrainRepository::mergeEntityPayload` vs EntityAgent), ensure concurrency safety (optimistic lock via last hash), and extend logs/diagnostics to surface merge + schema failures for future tests.
