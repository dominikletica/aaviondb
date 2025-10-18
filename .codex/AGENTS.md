# 🤖 AAVION AGENTS – Quick Overview

> **Version:** 0.1.0-dev  
> **Status:** Draft / Active Development  
> For the full specification, refer to [`docs/dev/MANUAL.md`](../docs/dev/MANUAL.md).  
> Implementation notes live in [`.codex/NOTES.md`](./NOTES.md); release history in [`CHANGELOG.md`](../CHANGELOG.md).

---

## Key Concepts
- **Brains** – JSON-based data stores (`system.brain` + user brains) with deterministic hashing, shared config map, and REST enablement flags.
- **Modules & Agents** – Auto-discovered via `ModuleLoader`; system modules provide core agents (Core, Brain, Entity, Project, Export, Auth, API, UI). Custom modules extend behaviour through commands, parser hooks, and events.
- **Unified Commands** – One syntax for CLI, REST, and PHP (`action project entity {json}`); `CommandRegistry` normalises responses and emits diagnostics events.
- **Entry Points** – `api.php` (REST/PHP), `cli.php` (terminal), `system/core.php` (embedded). All wrap errors in structured responses and log exceptions.
- **Authentication** – Bootstrap key `admin` (UI/CLI only) until the operator executes `auth grant` and `api serve`. REST requires non-bootstrap keys; auth actions are logged.
- **Logging & Diagnostics** – Monolog logger, module diagnostics, brain integrity report, planned system log module for viewing/rotating logs.

---

## Codex Workflow Guidance
- Keep documentation canonical: update relevant partials under `docs/dev/partials/` and `docs/dev/core-architecture.md` whenever behaviour changes. Flag drafts explicitly.
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
