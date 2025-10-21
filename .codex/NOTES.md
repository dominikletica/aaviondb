# AavionDB – Developer Notes

> Maintainer: Codex (GPT-5)  
> Purpose: Track implementation decisions, open questions, and follow-up tasks during core development.

## TODO Overview

### Core Platform
- [x] Flesh out `BrainRepository` with entity/project CRUD and hash handling.
- [ ] Add diagnostics coverage / automated checks for brain integrity (tests + tooling).
- [x] Define module registration workflow for parser handlers via `CommandRegistry`.
- [x] Ship system log module (`log view/rotate/cleanup`).
- [x] Complete security documentation (`docs/dev/partials/security.md`).
- [x] Instrument rate limiting telemetry & admin bypass tooling for `SecurityManager`.

### Module Checklist
- **Shared tasks**: [ ] Standardise manifest/module scaffolding for remaining modules; [ ] emit diagnostics + logging hooks; [ ] add PHPUnit coverage once prototype stabilises.
- **CoreAgent (`core`)**: [x] Implement `status`, `diagnose`, `help` with metadata.
- **BrainAgent (`brain`)**: [x] Core commands (`brains`, `brain init/switch/backup/info/validate`); [x] add compaction/repair utilities; [x] optional cleanup command for inactive versions (with dry-run).
- **ProjectAgent (`project`)**: [x] Lifecycle commands; [x] metadata update support; [x] cascade coordination with EntityAgent; [x] project restore/unarchive command; [ ] extend restore with bulk reactivation controls as needed.
- **EntityAgent (`entity`)**: [x] CRUD/version commands with selectors; [x] cascade coordination with ProjectAgent; [x] hierarchy-aware listing; [x] hierarchy move command; [ ] hierarchy diagnostics (z. B. Move-Impact-Previews) pending.
- **EntityAgent (`entity`)**: [x] Support incremental `save` merges (partial payload updates with empty values deleting fields, schema validation after merge); [x] allow schema selectors to target historical revisions (`fieldset@13` / `#hash`) and evaluate merges against non-active entity versions.
- **ConfigAgent (`config`)**: [x] `set`/`get` commands for user/system config; [ ] advanced value typing + bulk import/export; [ ] audit trail integration.
- **ExportAgent (`export`)**: [x] Parser + CLI exports for single/multi projects; [x] Preset-driven selection & payload transforms; [x] RegEx support for preset payload filters; [ ] Export destinations & scheduler hooks; [ ] Advanced export profiles (LLM/schema aware).
- **AuthAgent (`auth`)**: [x] Token lifecycle commands; [ ] integrate audit logging + bootstrap key guidance; [ ] prepare scoped key/role management.
- **ApiAgent (`api`)**: [x] `serve/stop/status/reset`; [ ] batched/async hooks.
- **UiAgent (`ui`)**: [ ] Studio integration hooks; [ ] optional console stubs.
- **LogAgent (`log`)**: [x] Tail/framework log filtering; [x] rotation/cleanup commands; [ ] log storage abstraction.
- **EventsAgent (`events`)**: [x] Listener diagnostics; [ ] event stream commands/stats; [ ] subscription/feed endpoints.
- **SchedulerAgent (`scheduler`)**: [x] Task CRUD + log, `cron` execution, REST w/out auth; [ ] retention policies & advanced scheduling.
- **CacheAgent (`cache`)**: [x] CacheManager wiring with enable/disable/ttl/purge; [x] expose tag-level diagnostics; [ ] warm cache helpers for heavy exports.
- **SecurityAgent (`security`)**: [x] Rate limiting + manual lockdown + purge; [ ] whitelist/bypass rules for trusted clients; [ ] audit trails.
- **SchemaAgent (`schema`)**: [x] Baseline list/show/lint commands; [x] create/update/delete fieldset helpers; [ ] Studio integration hooks; [ ] cached lint results & metrics.

### Roadmap to Alpha (pre-tests)
1. **Brain maintenance** – Extend `brain cleanup` with dry-run/retention preview and add compaction/repair helpers; update docs. *(DONE – backups still tracked separately below.)*
2. **Cascade behaviour** – Implement project/entity cascade hooks (auto-archive on project removal) and align documentation. *(DONE – follow-up: refine restore/reactivation ergonomics and cascade diagnostics.)*
3. **Configuration upgrades** – Add bulk import/export commands and config audit logging. *(Bulk JSON payload + audit events implemented; file-based import/export pending.)*
4. **Export destinations & profiles** – Introduce preset destinations (disk/response), scheduler hooks, and schema-aware/LLM profiles.
5. **Scheduler enhancements** – Provide dry-run/preview mode, retention policies, and cron-expression support.
6. **Cache warmup** – Add command(s) to pre-build cache artefacts for heavy exports/schemas.
7. **Security controls** – Implement trusted-client whitelists/bypass policies and enforcement audit logs.
8. **Auth/API improvements** – Add bootstrap guidance, scoped-role groundwork, and REST telemetry counters.
9. **Events telemetry** – Extend EventsAgent with emitted-event stats and optional streaming hooks.
10. **Log storage abstraction** – Allow pluggable/archived log storage to support future UI integrations.
11. **UiAgent stubs** – Flesh out Studio integration hooks and console stubs.
12. **SchemaAgent** - Force schema-version to be referenced inside an entity to be backwards-compatible when a schema changes (`:<schema>` sets reference to the latest version, `:<schema@<version>>` sets a distinct version). *(DONE – entity `fieldset_version` tracking in place.)*
13. **Preset management agent** – Persist export presets inside brains with full CRUD (`preset list/show/create/update/save/delete`) and link to ExportAgent.
14. **Entity hierarchy support** – Introduce parent/child relationships, cascading selectors, and documentation for hierarchical data modelling. *(DONE – hierarchy map + docs shipped; follow-up: subtree move helpers & OpenAPI alignment.)*
15. **Reference & query syntax** – Implement `[ref @project/entity/field]` resolution with round-trip-safe storage, fallback messages, and future `[query …]` filters (including recursive lookups).
16. **OpenAPI resource mode** – Extend API layer with a read/write resource interface compatible with OpenAPI tooling (no command execution; CRUD-focused endpoints for external clients).
17. **OpenAPI resource mode** – Extend API layer with a read/write resource interface compatible with OpenAPI tooling (no command execution; CRUD-focused endpoints for external clients).
18. **Log verbosity controls** – Review module loggers and add configurable log levels once core modules are stable.
19. **Testing plan** – Design and implement the PHPUnit coverage plan (execute last).

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
- DONE: System-level log module (commands `log view`, `log rotate`, `log cleanup`, …) to expose/manipulate Monolog output.
- DONE: Flesh out `docs/dev/partials/security.md` with sandboxing/permission model once defined.

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

- Session kickoff: document export preset roadmap and track regex filter support for payload matching.
- Implemented incremental entity saves with schema-aware validation (BrainRepository merges payload diffs, honours explicit null removals, applies fieldset-selected JSON Schemas, stores merge metadata); EntityAgent parses schema selectors & merge flags and exposes references for diagnostics.
- Extended selectors (`entity@version`/`#commit`, `fieldset@version`/`#commit`) during save; persisted commit metadata now retains merge/schema references.
- Added SchemaAgent (`list/show/lint`) backed by the shared SchemaValidator; documentation updated and TODOs aligned for planned enhancements.
- Added ConfigAgent (`set/get`), top-level command shortcuts (list/save/remove/delete), selective entity version deletion, brain cleanup/delete with `--keep` retention, commit listing, and generated developer references (`commands.md`, `classmap.md`).
- Implemented SchedulerAgent: task CRUD (`scheduler add/edit/remove/list/log`), unauthenticated `cron` execution with run logging, system-brain storage, and documentation updates.

### Design Notes – Entity Partial Saves & Schema Validation
- Merge flow: load active version, deep-copy payload, overlay incoming fields (associative merges, indexed replacements), treat explicit `null` values as deletion markers.
- Persisted version: recompute canonical JSON + hash, append as next version to keep history, include `merge` flag in metadata for diagnostics.
- Client contract: send only changed fields; deletions require explicit empties. Documented in MANUAL/API references.
- Fieldset handling: entity metadata includes `fieldset`; saves resolve schemas from project `fieldsets`, validate merged payload, and auto-fill optional fields via schema defaults/placeholders.
- Validation lifecycle: schema entities linted against JSON Schema meta-schema on write; saves fail with structured errors when schema missing/invalid or payload invalid.
- Open questions: confirm merge semantics location (`BrainRepository` helper), ensure optimistic concurrency, add diagnostics/logs for merge & schema failures, plan PHPUnit coverage.

## 2025-10-19 – Evening Session

- Integrate cache subsystem (CacheManager + CacheAgent) with event-driven invalidation and CLI controls.
- Add SecurityManager with rate limiting (per-client/global/auth-failure) and SecurityAgent CLI; update REST flow, docs, and TODOs accordingly.
- Added debug flag plumbing across CLI/REST/PHP; module loggers now emit source/module metadata automatically.
- Extended LogAgent with `log rotate`/`log cleanup` and added log archive pruning.
- Cache telemetry now surfaces entry counts, total bytes, tag distribution; security status includes rate-limit cache stats.
- Export preset payload filters support `matches`/`regex` and `in` operators for richer selection.
- SchemaAgent gained `create`, `update`, `save`, and `delete` wrappers around the `fieldsets` project.
- Introduced EventsAgent with `events listeners` for quick EventBus diagnostics.
- Added `aaviondb.php` PHP entry point consolidating bootstrap for include-based integrations.
- **Next session focus:** proceed with Roadmap step 1 (extend `brain cleanup` with dry-run/retention preview and add compaction/repair utilities) before tackling the remaining roadmap items. Keep Roadmap order for subsequent work; unit tests are reserved for the final step once all features are in place.

## 2025-10-20 – Morning Session

- Split documentation into dedicated user and developer handbooks; added modular chapters for user workflows and refreshed developer index/partials.
- Normalised partial status tags, updated architecture/REST/logging references, and aligned README with the new documentation split.
- Recorded references in AGENTS/Manual to keep classmap and commands in sync; ensured NOTE log stays current.
- Delivered brain maintenance utilities: `brain cleanup` now supports dry-run previews with commit counts, new `brain compact` rebuilds commit indexes/orders, and `brain repair` realigns entity metadata; README + manuals + command references updated.
- Added backup inventory/prune/restore support with optional gzip compression and updated CLI/REST documentation.
- **Next:** move to Roadmap Step 2 (project/entity cascade hooks) after verifying maintenance commands in practice.

## 2025-10-20 – Midday Session

- Implemented hierarchy support for entities: slash-separated paths in `entity save` assign parents, `BrainRepository` maintains a per-project `hierarchy` map, and `hierarchy.max_depth` (configurable via `set`) guards recursion depth.
- Extended `entity remove` / `entity delete` with `--recursive`, child promotion logic, and structured cascade metadata + warnings; `ProjectAgent` now archives entities automatically when a project is removed.
- Surfaced hierarchy details (`parent`, `path`, `path_string`) in entity listings/reports and included cascade warnings in command responses/events.
- Updated README, user manual, developer module docs, command reference, and class map to explain hierarchy usage, recursion flags, and the outstanding need for a `project restore` command.
- Roadmap Step 2 and Step 14 marked complete (follow-up tasks captured); Module checklist now tracks open work for project restore/unarchive and hierarchy tooling helpers.
- Added `project restore` with optional entity reactivation, plus hierarchy-aware filtering for `entity list`/`list entities`. Documentation + classmap/commands refreshed; TODOs now focus on subtree move helpers and advanced cascade refinements.
- Implemented `entity move` (deterministic subtree relocation) und `entity save --parent` ohne Payload für Einzel-Reparenting. README, Manuals, Commands und Classmap aktualisiert; offen bleibt lediglich bessere Hierarchie-Diagnostik (z. B. Move-Impact-Previews).

## 2025-10-21 – Midday Session

- Dokumentationsstruktur konsolidiert: Root-README verschlankt, vollständige Handbücher unter `docs/README.md`; Querverweise in `AGENTS.md`, `docs/dev/MANUAL.md`, `docs/dev/partials/file-structure.md` angepasst.
- Handbücher (User/Dev) und Referenzen (`commands`, `classmap`) mit den neuen Befehlen (`entity move`, payloadlose Reparentings) synchronisiert; erledigte TODOs entfernt.
- Schema-Persistenz erweitert: `BrainRepository::resolveSchemaDefinition()` liefert Version/Commit; Entities speichern `fieldset_version`, Commits/Listen enthalten diese Metadaten; User-Doku erläutert, dass Saves standardmäßig die bestehende Schemarevision weiterverwenden.
- Prüfte Module-Dokumentation auf offene Punkte; Roadmap bleibt bei Schritt 3 (Config-Agent – Bulk Import/Export & Audit Logging).
- Config-Agent modernisiert: Bulk-Updates über JSON-Payload, `get *` alias, Doku & Beispiele aktualisiert. Audit-Logging/Bulk-Import weiterhin offen für Schritt 3.
- Config-Agent ergänzt um Audit-Events (`config.key.updated/deleted`) inkl. Log-Ausgabe; Bulk-Update Logik schreibt pro Key einen Eintrag.
