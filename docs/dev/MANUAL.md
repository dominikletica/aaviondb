# 🧩 AavionDB Developer Manual

> **Audience:** Module authors, core contributors, and maintainers  
> **Location:** `/docs/dev/MANUAL.md`  
> **Companion Guide:** [User Manual](../user/MANUAL.md)
> **Maintainer:** Dominik Letica & @codex

This manual acts as the technical reference for AavionDB. It explains architecture decisions, call flows, module responsibilities, and integration points. Each section links to detailed partials that can be maintained independently.

---

## 📘 Table of Contents

### 1. Architecture & Runtime
- [Runtime Blueprint](./partials/core-architecture.md) – service layout, dependency graph, bootstrap stages.
- [Filesystem Layout](./partials/file-structure.md) – directory responsibilities, writable locations, generated assets.
- [Bootstrap Sequence](./partials/bootstrap.md) – from entry point to ready `AavionDB` instance.
- [Entry Points](./partials/entry-points.md) – CLI, REST (`api.php`), PHP (`aaviondb.php`) call chains.
- [Resolver & Shortcodes](./partials/resolver-and-shortcodes.md) – inline reference DSL, resolver contexts, save-time sanitising.

### 2. Storage & State
- [Brains & Storage Engine](./partials/brains-and-storage.md) – persistence model, repository contracts, hashing.
- [Versioning & Commits](./partials/versioning.md) – selectors, canonical JSON encoding, integrity guarantees.
- [Cache Engine](./partials/modules/cache.md) – cache directories, invalidation strategies, tag semantics.

### 3. Command Routing & Modules
- [Agents & Command Registry](./partials/agents-and-command-registry.md) – parser workflow, module registration.
- [Module Architecture](./partials/modules.md) – manifest format, shared traits, context helpers.
- [Events & Hooks](./partials/events-and-hooks.md) – listener lifecycle, emitting patterns, planned streams.

### 4. Interfaces & Integrations
- [REST API Layer](./partials/rest-api.md) – request parsing, security hooks, dispatch pipeline.
- [Authentication](./partials/authentication.md) – token lifetimes, scope resolution, guard hooks.
- [UI Console](./partials/ui-console.md) – Studio integration expectations and planned adapters.
- [Extending AavionDB](./partials/extending.md) – custom module scaffolding, service injection.

### 5. Diagnostics & Observability
- [Logging & Diagnostics](./partials/logging-and-diagnostics.md) – Monolog channels, debug flag signal flow.
- [Error Handling](./partials/error-handling.md) – response envelope, exception mapping, retry headers.
- [Security](./partials/security.md) – rate limiter buckets, lockdown flow, telemetry persistence.

### 6. Module Reference (Per-Agent Deep Dive)

Each module partial documents command handlers, call flows, primary classes, and outstanding work:

| Module | Partial |
|--------|---------|
| CoreAgent | [core](./partials/modules/core.md) |
| BrainAgent | [brain](./partials/modules/brain.md) |
| ProjectAgent | [project](./partials/modules/project.md) |
| EntityAgent | [entity](./partials/modules/entity.md) |
| ConfigAgent | [config](./partials/modules/config.md) |
| ExportAgent | [export](./partials/modules/export.md) |
| PresetAgent | [preset](./partials/modules/preset.md) |
| SchemaAgent | [schema](./partials/modules/schema.md) |
| ResolverAgent | [resolver](./partials/modules/resolver.md) |
| AuthAgent | [auth](./partials/modules/auth.md) |
| ApiAgent | [api](./partials/modules/api.md) |
| SchedulerAgent | [scheduler](./partials/modules/scheduler.md) |
| CacheAgent | [cache](./partials/modules/cache.md) |
| SecurityAgent | [security](./partials/modules/security.md) |
| LogAgent | [log](./partials/modules/log.md) |
| EventsAgent | [events](./partials/modules/events.md) |
| UiAgent | [ui](./partials/modules/ui.md) *(planned integration hooks)* |

Each document follows a consistent layout: Responsibilities → Command Surface → Call Flow → Key Classes → Error Handling → TODOs.

### 7. Tooling & Roadmap

- [Developer Notes](../../.codex/NOTES.md) – working log, TODO tracking, roadmap to alpha.
- [Agents Handbook](../../.codex/AGENTS.md) – session rules, documentation policy, reference upkeep.
- [Command Reference](./commands.md) – canonical command syntax (includes aliases and selectors).
- [Class Map](./classmap.md) – tree of namespaced classes, method signatures, and return types.

### Tracking Work

Worklogs, planned enhancements, and outstanding TODOs are tracked centrally in [`.codex/NOTES.md`](../../.codex/NOTES.md).

## 🔗 Related Resources

- [User Manual](../user/MANUAL.md) – onboarding guide and end-user workflows.
- [README.md](../../README.md) – high-level project overview with links into the documentation set.
- [docs/README.md](../README.md) – canonical documentation landing page (links to user/developer manuals, command & class references).
- [`CHANGELOG.md`](../../CHANGELOG.md) – release history.
- [`/LICENSE`](../../LICENSE) – project license.

Maintain this index whenever partials change. New modules should add their partial under `partials/modules/` and update the table above.
