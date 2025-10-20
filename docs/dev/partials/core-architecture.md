# Core Architecture

> **Status:** Maintained  
> **Scope:** Runtime blueprint, service layout, and orchestration rules.

---

## Overview

AavionDB centres around `AavionDB::setup()`, an idempotent bootstrap that wires the service container, storage, modules, and diagnostics. The bootstrap is invoked automatically by all entry points (`cli.php`, `api.php`, `aaviondb.php`) and should never leak raw exceptions to callers.

---

## Runtime Boot Sequence

1. **Directory preparation** – `PathLocator` ensures writable directories exist (logs, cache, brains, presets, scheduler, backups).  
2. **Core services** – The container registers the logger factory, event bus, command parser, command registry, security manager, cache manager, and brain repository.  
3. **System brain mount** – `BrainRepository` loads `system.brain`, creating defaults for config, scheduler, cache, and security keys when missing.  
4. **Active user brain** – Resolves the configured active brain; creates `default.brain` on first run.  
5. **Module discovery** – Loads `system/modules/**` manifests, resolves dependencies, and initialises autoload modules through `ModuleContext`.  
6. **User module discovery** – Searches `user/modules/**` and repeats the registration process.  
7. **Diagnostics wiring** – Collects runtime metrics, integrity checks, and module telemetry via `RuntimeState`.  
8. **REST gating** – The REST layer remains disabled until `api serve` flips `api.enabled = true` and at least one user token exists.  

Any failure logs an error (Monolog `ERROR` level) and surfaces as a structured response with `status=error` and `meta.exception`.

---

## Namespace Layout

```
system/
├── core.php                # AavionDB façade
├── Core/
│   ├── Bootstrap.php       # Boot pipeline
│   ├── Container.php       # Lightweight service locator
│   ├── CommandRegistry.php # Command dispatch + telemetry
│   ├── CommandParser.php   # Statement parsing / handlers
│   ├── Modules/            # Loader, descriptors, context
│   ├── Logging/            # Module-aware logger factory
│   ├── Security/AuthManager.php
│   ├── EventBus.php        # Publish/subscribe hub
│   ├── RuntimeState.php    # Diagnostics snapshot
│   ├── Filesystem/PathLocator.php
│   ├── Hashing/CanonicalJson.php
│   ├── Exceptions/         # Exception hierarchy
│   └── Support/            # Helper utilities
└── Storage/
    └── BrainRepository.php # Brain persistence
```

---

## Façade (`system/core.php`)

- Public API: `setup`, `run`, `command`, `registerParserHandler`, `diagnose`, `events`, `commands`, `parser`, `brains`, `logger`, `auth`, `security`, `cache`, `isBooted`.  
- `run(array|string $action, array $parameters = [])` routes structured commands.  
- `command(string $statement, array $context = [])` parses CLI-style statements (JSON payload support) before dispatching.  
- Both wrappers translate exceptions into the unified response array and emit telemetry events.  
- `debugLog()` provides module-scoped debug output when the global debug flag or `--debug` parameter is set.

---

## Canonical JSON & Hashing

- `CanonicalJson::encode()` sorts associative keys recursively, preserves indexed array order, and throws `JsonException` on failure.  
- `CanonicalJson::hash()` generates an SHA-256 digest used for entity commits, presets, and schema fingerprints.  
- This ensures deterministic hashes across CLI/REST/PHP callers and underpins cache invalidation and deduplication.

---

## Brain Storage Model

- Both system and user brains share the structure:  
  `meta`, `projects`, `entities`, `commits`, `config`, `state`, `scheduler`, `security`, `cache`.  
- Entities track versions with `{version, status, hash, commit, committed_at, payload, meta}`.  
- Repository responsibilities:  
  - CRUD for brains, projects, entities, schemas, presets, scheduler tasks.  
  - Config operations (`setConfigValue`, `getConfigValue`, `deleteConfigValue`, `listConfig`).  
  - Atomic writes with optimistic locking (hash comparison) and rollback logging.  
  - Event emission (`brain.entity.saved`, `brain.project.deleted`, etc.) for downstream modules.  
- Hash integrity is verified before committing writes; mismatches trigger re-read and retry cycles.

---

## Command Registry & Parser

- `CommandParser` tokenises statements, resolves aliases, handles selectors (`@version`, `#hash`, `:fieldset`), and supports quote-aware payload extraction.  
- Handlers can register pre/post processors via `AavionDB::registerParserHandler()`.  
- `CommandRegistry::dispatch()` performs final normalisation, permission checks, diagnostic timing, and error wrapping into `CommandResponse`.  
- Telemetry events: `command.parser.parsed`, `command.executed`, `command.failed` (consumed by LogAgent, EventsAgent, and SchedulerAgent).

---

## Module Loader

- Reads `manifest.json` for each module (slug, version, capabilities, dependencies, autoload flag).  
- Initialises modules through `module.php` -> `register(ModuleContext $context)`.  
- Capability checks gate access to services (e.g., `repository`, `cache`, `security`).  
- Failures mark the module inactive and record issues in diagnostics; dependent modules are skipped.  
- Module-specific loggers include `source` metadata to support filtered log views.

---

## Diagnostics & Telemetry

- `RuntimeState::diagnostics()` aggregates filesystem paths, command counts, module states, cache/security telemetry, and export statistics.  
- Each module contributes via `ModuleContext::diagnostics()` callbacks.  
- Debug logs follow the `source=<module>` convention for LogAgent filtering.  
- Planned enhancements: diagnostic snapshots persisted per brain and integration with AavionStudio dashboards.

---

## Authentication & Security Hooks

- `Security\AuthManager` manages bootstrap tokens, REST guard scopes, and audit metadata.  
- REST access is allowed when:
  1. `api.enabled = true`
  2. At least one non-bootstrap token exists
  3. The incoming token passes scope validation
- `Security\SecurityManager` handles rate limiting, lockdowns, and request attempt bookkeeping.  
- Both managers persist state in `system.brain` and expose telemetry used by `security status`.

---

## Entry Points

- `api.php` – REST gateway; parses query/body payloads, enforces rate limiting, auth guards, optional debug flag, and dispatches via `AavionDB::run()` or `::command()`.  
- `cli.php` – Thin wrapper around `AavionDB::command()` with JSON output.  
- `aaviondb.php` – PHP entry point that loads Composer, bootstraps once per request, and exposes helper functions for embedding AavionDB into other applications.

---

## Roadmap Notes

- Expand sandbox metadata (`provides`, `conflicts`) to allow module capability negotiation.  
- Persist diagnostic snapshots for UI visualisation.  
- Harden bootstrap error reporting for headless environments (CLI exit codes + REST meta flags).  
- Add PHPUnit coverage for bootstrap regression protection.
