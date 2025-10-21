# 🤖 AAVION AGENTS – Quick Overview

> **Version:** 0.1.0-dev  
> **Status:** Draft / Active Development  
> For the full specification, refer to [`docs/dev/MANUAL.md`](../docs/dev/MANUAL.md).  
> Implementation notes live in [`.codex/NOTES.md`](./NOTES.md); release history in [`CHANGELOG.md`](../CHANGELOG.md).

---

## Key Concepts
- **Brains** – JSON-based data stores (`system.brain` + user brains) with deterministic hashing, shared config map, and REST enablement flags.
- **Modules & Agents** – Auto-discovered via `ModuleLoader`; dependencies are resolved recursively and capability-scoped contexts keep access controlled. Modules follow the documented structure (`manifest.json`, `module.php`, optional `classes/`) and register commands via `ModuleContext`. System modules provide core agents (Core, Brain, Entity, Project, Export, Auth, API, UI). Custom modules extend behaviour through commands, parser hooks, and events.
- **Unified Commands** – One syntax for CLI, REST, and PHP (`action project entity {json}`); `CommandRegistry` normalises responses and emits diagnostics events.
- **Entry Points** – `api.php` (REST/PHP), `cli.php` (terminal), `aaviondb.php` (embedded). All wrap errors in structured responses and log exceptions.
- **Authentication** – `AuthManager` stores hashed tokens in `system.brain`. Use `BrainRepository` helpers to mint/revoke tokens, toggle API flags, and rotate bootstrap keys. REST stays disabled until `api.enabled = true` and a non-bootstrap token exists; `admin` works only for CLI/UI recovery. Usage is logged for the log module.
- **Logging & Diagnostics** – Monolog logger, module diagnostics, brain integrity report, system log module for viewing/rotating logs.

---

## Session Boot Checklist
- Review `.codex/NOTES.md` before coding: align the TODO overview with repository reality, add any missing tasks, and open the next session entry in the worklog timeline.
- Scan developer documentation (`docs/dev/**`, `docs/README.md`, module partials) for pending updates; correct discrepancies immediately or flag them in the TODO list when larger follow-up is needed.
- Ensure visible repository text remains English and terminology stays consistent; schedule clean-up work whenever foreign-language fragments appear.
- Reconfirm the operating rules in this guide and cross-check sandbox/approval notes so upcoming commands follow the expected constraints.

---

## Codex Workflow Guidance
- Keep documentation canonical: update relevant partials under `docs/dev/partials/`, `docs/dev/partials/modules/`, `docs/dev/MANUAL.md` and this file whenever behaviour changes. Update user documentation under `docs/user/`accordingly. Flag drafts explicitly and clear them as soon as implementations land. Before closing a session (or starting the next one), **read every Markdown file end-to-end** so silent drift is caught immediately.
- Maintain [`.codex/NOTES.md`](./NOTES.md) as the authoritative worklog: promote completed items out of the TODO overview, capture new follow-ups, log every completed step and close out sessions with concise outcomes.
- Cross-check code and documents after every feature or refactor; update docs immediately or log the mismatch (with owner and next steps) in the TODO list.
- Prefer non-throwing flows: wrap operations in `try/catch`, log via Monolog, return unified `CommandResponse` payloads.
- Maintain consistent entry-point behaviour (REST/CLI/PHP) and ensure tests/diagnostics are updated alongside code.
- When adding modules/commands, document them in the appropriate partial and note follow-up items (tests, security review, UI integration).
- Repository content must remain **English-only** (code comments, docs, commit messages). Communicate with the maintainer in German if requested, but never commit German text to the repo; translate or rewrite immediately when such fragments appear.
- Treat `docs/dev/classmap.md` and `docs/dev/commands.md` as canonical references for classes and CLI actions; update them whenever APIs or commands change so they remain trustworthy lookup tables.
- Keep the root `README.md` as a concise entry point (overview + pointers) and treat `docs/README.md` + sub manuals as the canonical documentation set—always update both when behaviour changes.
- Plan for the upcoming **AavionStudio** project (external UI) that must interact with all framework features via native module calls **and** REST endpoints. Ensure new APIs, commands, and schema adjustments remain accessible/consistent for the Studio integration.

---

## References
- Developer Manual: [`docs/dev/MANUAL.md`](../docs/dev/MANUAL.md)
- README / user guide: [`README.md`](../README.md)
- Release history: [`CHANGELOG.md`](../CHANGELOG.md)
- Implementation notes and worklog: [`.codex/NOTES.md`](./NOTES.md)
