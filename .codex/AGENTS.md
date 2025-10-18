# 🤖 AAVION AGENTS – Quick Overview

> **Version:** 0.1.0-dev  
> **Status:** Draft / Active Development  
> For the full specification, refer to [`docs/dev/MANUAL.md`](../docs/dev/MANUAL.md).  
> Implementation notes live in [`.codex/NOTES.md`](./NOTES.md); release history in [`CHANGELOG.md`](../CHANGELOG.md).

---

## Key Concepts
- **Brains** – JSON-based data stores (`system.brain` + user brains) with deterministic hashing, shared config map, and REST enablement flags.
- **Modules & Agents** – Auto-discovered via `ModuleLoader`; dependencies are resolved recursively and capability-scoped contexts keep access controlled. Modules follow the documented structure (`manifest.json`, `module.php`, optional `src/`) and register commands via `ModuleContext`. System modules provide core agents (Core, Brain, Entity, Project, Export, Auth, API, UI). Custom modules extend behaviour through commands, parser hooks, and events.
- **Unified Commands** – One syntax for CLI, REST, and PHP (`action project entity {json}`); `CommandRegistry` normalises responses and emits diagnostics events.
- **Entry Points** – `api.php` (REST/PHP), `cli.php` (terminal), `system/core.php` (embedded). All wrap errors in structured responses and log exceptions.
- **Authentication** – `AuthManager` stores hashed tokens in `system.brain`. Use `BrainRepository` helpers to mint/revoke tokens, toggle API flags, and rotate bootstrap keys. REST stays disabled until `api.enabled = true` and a non-bootstrap token exists; `admin` works only for CLI/UI recovery. Usage is logged for the upcoming log module.
- **Logging & Diagnostics** – Monolog logger, module diagnostics, brain integrity report, planned system log module for viewing/rotating logs.

---

## Codex Workflow Guidance
- Keep documentation canonical: update relevant partials under `docs/dev/partials/` and `docs/dev/partials/core-architecture.md` whenever behaviour changes. Flag drafts explicitly.
- Record architectural decisions and outstanding TODOs in [`.codex/NOTES.md`](./NOTES.md); summarise user-facing changes in [`CHANGELOG.md`](../CHANGELOG.md).
- Prefer non-throwing flows: wrap operations in `try/catch`, log via Monolog, return unified `CommandResponse` payloads.
- Maintain consistent entry-point behaviour (REST/CLI/PHP) and ensure tests/diagnostics are updated alongside code.
- When adding modules/commands, document them in the appropriate partial and note follow-up items (tests, security review, UI integration).

---

## References
- Developer Manual: [`docs/dev/MANUAL.md`](../docs/dev/MANUAL.md)
- README / user guide: [`README.md`](../README.md)
- Release history: [`CHANGELOG.md`](../CHANGELOG.md)
- Implementation notes: [`.codex/NOTES.md`](./NOTES.md)
