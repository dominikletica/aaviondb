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
- **Shared tasks**: [ ] Standardise manifest/module scaffolding for remaining modules; [ ] establish consistent diagnostics/logging hooks; [ ] schedule PHPUnit coverage once the feature set stabilises.
- **CoreAgent (`core`)**: [x] Implement `status`, `diagnose`, `help`; [ ] expand `help` output with richer metadata; [ ] add PHPUnit coverage for status/help/error paths.
- **BrainAgent (`brain`)**: [x] Core lifecycle commands; [x] compaction/repair utilities; [x] cleanup with dry-run preview; [ ] additional retention metrics; [ ] PHPUnit coverage for lifecycle/deletion/maintenance flows.
- **ProjectAgent (`project`)**: [x] Lifecycle commands; [x] metadata updates; [x] cascade coordination; [x] restore/unarchive support; [ ] advanced cascade options (auto cleanup/notifications); [ ] PHPUnit coverage for lifecycle/restore.
- **EntityAgent (`entity`)**: [x] CRUD & selectors; [x] cascade coordination; [x] hierarchy listing/move; [x] incremental `save` merges with schema validation; [ ] hierarchy diagnostics/subtree helpers; [ ] extended historic schema reference support; [ ] PHPUnit coverage (schema/hierarchy edge cases).
- **ConfigAgent (`config`)**: [x] `set`/`get` commands; [ ] file/payload import-export workflows; [ ] audit log for configuration changes; [ ] namespaced key suggestions and validation.
- **ExportAgent (`export`)**: [x] CLI/REST exports; [x] preset-driven selection & transforms; [x] regex payload filters; [x] destination overrides & file persistence; [ ] Studio-tailored alias coverage (via UIAgent); [ ] advanced LLM/schema profiles.
- **AuthAgent (`auth`)**: [x] Token lifecycle commands; [ ] audit logging and bootstrap guidance; [ ] scoped key/role management; [ ] PHPUnit coverage (grant/revoke/reset).
- **ApiAgent (`api`)**: [x] `serve/stop/status/reset`; [ ] rolling request telemetry; [ ] scheduler/log automation for maintenance windows; [ ] batched/async execution hooks.
- **UiAgent (`ui`)**: [ ] Studio integration hooks (UI lookup/load/validate/save aliases, resolver-aware lookups, non-resolved entity access, draft space); [ ] optional console tooling; [ ] asset and security documentation for external UIs.
- **LogAgent (`log`)**: [x] Tail/rotate/cleanup; [ ] log storage abstraction; [ ] optional streaming/compression enhancements.
- **EventsAgent (`events`)**: [x] Listener diagnostics; [ ] emitted-event telemetry; [ ] streaming/subscription endpoints.
- **SchedulerAgent (`scheduler`)**: [x] Task CRUD + `cron`; [ ] dry-run/preview mode; [ ] cron expression/priority scheduling; [ ] PHPUnit coverage.
- **CacheAgent (`cache`)**: [x] Enable/disable/ttl/purge; [x] tag diagnostics; [ ] cache warm-up helpers for heavy exports.
- **SecurityAgent (`security`)**: [x] Rate limiting + lockdown + purge; [ ] trusted-client allow lists/bypass rules; [ ] audit trail integration.
- **SchemaAgent (`schema`)**: [x] List/show/lint/create/update/delete; [ ] Studio integration hooks; [ ] schema usage metrics; [ ] PHPUnit coverage.
- **PresetAgent (`preset`)**: [x] CLI CRUD/import/export/vars; [x] default JSON/JSONL/Markdown/Text presets seeded; [ ] Studio integration guidance (UIAgent hooks, template packs) and translation readiness review.
- **ResolverAgent (`resolver`)**: [x] Single-shot shortcode resolution; [ ] diagnostics (lint/report) and batch tooling.

### Roadmap to Alpha (pre-tests)
1. **Brain maintenance** – Extend `brain cleanup` with dry-run/retention preview and add compaction/repair helpers; update docs. *(DONE – backups still tracked separately below.)*
2. **Cascade behaviour** – Implement project/entity cascade hooks (auto-archive on project removal) and align documentation. *(DONE – follow-up: refine restore/reactivation ergonomics and cascade diagnostics.)*
3. **Configuration upgrades** – Add bulk import/export commands and config audit logging. *(Bulk JSON payload + audit events implemented; file-based import/export skipped by user request.)*
4. **Filter & Resolver engine** – Ship the reusable filter/query DSL + resolver pipeline (preset/entity/export integration, `[ref …]` expansion, placeholder docs) per `2025-10-21-IDEAS`. *(DONE – ResolverEngine resolves `[ref]/[query]` in entity show & exports; shortcode docs live under `docs/dev/partials/resolver-and-shortcodes.md`.)*
5. **Export destinations & profiles** – Introduce preset destinations (disk/response) and advanced LLM/schema-aware profiles once the resolver lands. *(DONE – destination overrides, system defaults, JSON/JSONL/Markdown/Text presets, missing-field policies, nest_children rendering; follow-up: document Studio alias usage and extend profile examples as needed.)*
6. **Scheduler enhancements** – Provide dry-run/preview mode, retention policies, and cron-expression support.
7. **Cache warmup** – Add command(s) to pre-build cache artefacts for heavy exports/schemas.
8. **Security controls** – Implement trusted-client whitelists/bypass policies and enforcement audit logs.
9. **Auth/API improvements** – Add bootstrap guidance, scoped-role groundwork, and REST telemetry counters.
10. **Events telemetry** – Extend EventsAgent with emitted-event stats and optional streaming hooks.
11. **UiAgent stubs** – Flesh out Studio integration hooks and console stubs (include preset variable discovery).
12. **SchemaAgent** - Force schema-version to be referenced inside an entity to be backwards-compatible when a schema changes (`:<schema>` sets reference to the latest version, `:<schema@<version>>` sets a distinct version). *(DONE – entity `fieldset_version` tracking in place.)*
13. **Preset management agent** – Persist export presets inside brains with full CRUD (`preset list/show/create/update/delete/copy/import/export`) and link to ExportAgent. *(DONE – PresetAgent + FilterEngine integration; follow-up tasks captured under Step 4/11.)*
14. **Entity hierarchy support** – Introduce parent/child relationships, cascading selectors, and documentation for hierarchical data modelling. *(DONE – hierarchy map + docs shipped; follow-up: subtree move helpers & OpenAPI alignment.)*
15. **OpenAPI resource mode** – Extend API layer with a read/write resource interface compatible with OpenAPI tooling (no command execution; CRUD-focused endpoints for external clients).
16. **Reference & query syntax** – Extended resolver features beyond Step 4 (advanced recursion, query filters, timeline expressions).
17. **Log verbosity controls** – Review module loggers and add configurable log levels once core modules are stable.
18. **Fieldset storage review** – Evaluate moving fieldsets/schemas into the system brain for cross-brain reuse.
19. **Testing plan** – Design and implement the PHPUnit coverage plan (execute last).

### Planned Features
- Resolver/query engine for contextual references (roadmap steps 4 & 16).
- Export destinations and scheduler integrations (steps 5 & 6).
- Studio-focused UI/UX enhancements (preset variable discovery, schema editors).
- Translation readiness: audit command and error messages for language packs (UIAgent/Studio).
- Version diff tooling, soft-delete policies, and signed cross-brain import/export.
- Extended module lifecycle features (capability matrix, hot reload, provides/conflicts metadata).
- Parser/command-registry improvements (roles, structured metadata, batching, profiling hooks).
- Logging/diagnostics dashboards with remote sinks and telemetry exports.
- Event bus extensions (async queues, listener isolation, streaming/subscription APIs).
- Telemetry & observability upgrades (API counters, event streams, security/audit dashboards).
- OpenAPI-compatible resource mode for external clients (roadmap step 15).
- AavionStudio: project-type metadata so Studio can install preset/schema bundles automatically (authoring, coding, art, blog, docs projects). Backend hooks will land alongside the Studio implementation.

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
- Implemented `entity move` (deterministic subtree relocation) and `entity save --parent` without payload for single reparenting. README, manuals, commands, and class map updated; pending follow-up: richer hierarchy diagnostics (e.g. move impact previews).

## 2025-10-21 – Midday Session

- Consolidated documentation: streamlined root README, full handbooks under `docs/README.md`; updated cross-references (`AGENTS.md`, `docs/dev/MANUAL.md`, `docs/dev/partials/file-structure.md`).
- Documented the export/preset concept in `.codex/2025-10-21-IDEAS.md` (context-unified-v2 layout, FilterEngine/Resolver plan, command placeholders).
- Synced user/developer manuals and quick references (`commands`, `classmap`) with hierarchy features (`entity move`, payload-less reparenting); cleared resolved TODOs.
- Extended schema persistence: `BrainRepository::resolveSchemaDefinition()` now returns version/commit; entities store `fieldset_version`; documentation clarifies schema reuse on saves.
- Reviewed module docs; roadmap remains focused on Config-Agent bulk import/export and audit logging.
- Enhanced Config-Agent with bulk JSON updates, `get *` alias, revised docs/examples, and audit events (`config.key.updated/deleted`) including logging feedback.

## 2025-10-21 – Afternoon Session

- Implemented `PresetAgent` system module with full CLI CRUD (`preset list/show/create/update/delete/copy/import/export`) and default preset/layout bootstrap.
- Added `PresetValidator` to normalise preset definitions and `FilterEngine` core service for DSL evaluation (`selectEntities`, `passesFilters`).
- Extended `BrainRepository` with preset/layout storage APIs (`list/get/save/delete` for presets and layouts).
- Rebuilt `ExportAgent` around the new preset infrastructure: parameter parsing, FilterEngine integration, flattened `context-unified-v2` rendering, project/index/stats aggregation.
- Updated user/developer docs (exports, preset module, error handling), command/class references, and class map to reflect the new workflow.
- Next focus: continue Roadmap Step 4 (export destinations & advanced profiles) and document remaining preset/policy follow-ups.

## 2025-10-22 – Morning Session

- Delivered ResolverEngine enhancements: caller hierarchy awareness, `{record.url}`, `{record.url_relative}`, `{record.url_absolute}` helpers, and consistent relative path generation.
- Added `ResolverAgent` with `resolve [shortcode] --source=project.entity[@version|#commit]` for quick CLI/REST previews, sharing parameter logic with ExportAgent.
- Synced documentation (developer & user manuals, resolver module reference, command/class maps) and refreshed examples to highlight new link helpers.
- Module checklist updated with resolver status; docs confirm `[query]` shortcodes reuse FilterEngine for selection.

## 2025-10-22 – Evening Session

- Refactored preset storage/validation to the new schema (`meta`, `settings`, `selection`, `templates`) and seeded bundled presets (`context-unified`, `context-jsonl`, `context-markdown-unified`, `context-markdown-slim`, `context-markdown-plain`, `context-text-plain`).
- Rebuilt `ExportAgent` around template rendering: merged system/preset/CLI destination overrides, added file persistence (`--path`/`--save`), format overrides, nest-children ordering, and structured replacement helpers (payload flattening, heading/indent placeholders, plain payload rendering).
- Introduced missing-field policies (`settings.options.missing_payload`) with configurable warning/skip behaviour; warnings now bubble through payload/meta responses.
- Moved export defaults (`export.response`, `export.save`, `export.format`, `export.nest_children`) into the system brain with automatic seeding; removed legacy `response_exports` / `save_exports` config flags.
- Updated developer/user documentation (`docs/dev/partials/modules/export.md`, `modules/preset.md`, `docs/user/sections/exports.md`, `docs/dev/commands.md`, `docs/README.md`) and refreshed command references to reflect the new workflow and presets.
- Documented scheduler enhancement concept in `.codex/2025-10-22-scheduler-enhancements.md` (cron expressions, priorities, retries, preview command) as groundwork for Roadmap Step 6 (Scheduler enhancements).
- **Next:** implement Roadmap Step 6 using the concept doc (cron expressions, priorities, retry/state tracking), then move on to UIAgent alias/translation planning once scheduler upgrades ship.
