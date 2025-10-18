# AavionDB Core Architecture (Draft 1)

> Status: In active development  
> Maintainer: Codex (GPT-5)  
> Scope: Defines the baseline runtime architecture required before system modules, UI, and test suites are implemented.

This document captures the concrete implementation blueprint for the initial **Core** of AavionDB.  
It refines and extends the high-level goals from `docs/dev/MANUAL.md` and `.codex/AGENTS.md`.

---

## 1. Runtime Boot Sequence

The canonical entry point remains `AavionDB::setup()`. The boot process is executed exactly once per request and resolves the following phases:

1. **Environment bootstrap** – load configuration, establish paths, wire service container.
2. **System brain mount** – open `system/storage/system.brain`; create with default structure when absent.
3. **User brain resolution** – determine active user brain from system brain metadata; lazily create if referenced but missing.
4. **Service registration** – register the Command Registry, Event Bus, Storage Engines, Logger.
5. **Module discovery** – scan `/system/modules` (required) and `/user/modules` (optional); validate manifests; preload metadata.
6. **Command binding** – allow modules to register commands; populate shared registry.
7. **Context finalisation** – expose dispatcher methods (`run`, `command`) and mark the runtime as ready for CLI/REST/UI use.

When bootstrapping fails, the setup method throws typed exceptions. No partial state must be written.

---

## 2. Core Namespace Layout

Autoload mapping (`composer.json`) already binds `AavionDB\` to `system/`. The core will be structured as follows:

```
system/
├── core.php                # Exposes façade class AavionDB
├── Core/
│   ├── Bootstrap.php       # Handles setup pipeline
│   ├── Container.php       # Lightweight service locator (lazy factories only)
│   ├── CommandRegistry.php # Stores callable command handlers and metadata
│   ├── EventBus.php        # Publish/subscribe for internal events
│   ├── Exceptions/         # Framework-specific exception hierarchy
│   │   ├── AavionException.php
│   │   ├── BootstrapException.php
│   │   ├── StorageException.php
│   │   └── CommandException.php
│   ├── Filesystem/
│   │   ├── BrainFilesystem.php   # Encapsulates access to .brain files
│   │   └── PathLocator.php       # Resolves canonical paths for system/user assets
│   ├── Hashing/CanonicalJson.php # Canonical encoder + hashing utilities
│   ├── RuntimeState.php          # Immutable snapshot of boot outcome
│   └── Support/                  # Shared helpers (e.g. Arr::ksortRecursive)
└── Storage/
    ├── BrainStore.php            # In-memory representation of a mounted brain
    ├── BrainRecord.php           # Value object for a stored version
    └── BrainRepository.php       # High-level CRUD operations on brains
```

All classes will follow PSR-12 and ship with PHPDoc on public APIs.

---

## 3. Facade (`system/core.php`)

The façade exposes the runtime to consumers:

```php
namespace AavionDB;

final class AavionDB
{
    public static function setup(array $options = []): void;
    public static function run(string $action, array $parameters = []): array;
    public static function command(string $statement): array;
    public static function diagnose(): array;
    public static function events(): Core\EventBus;
    public static function commands(): Core\CommandRegistry;
    public static function isBooted(): bool;
}
```

- `setup()` delegates to `Core\Bootstrap`.
- `run()` executes a command by name, guaranteeing unified response structure.
- `command()` parses human-readable CLI statements into `run()`.
- `diagnose()` gathers diagnostics (loaded modules, brain state, config).
- The façade tracks boot state via a `Core\RuntimeState` singleton.

No global variables will be used; state lives inside the container and injected services.

---

## 4. Canonical JSON & Hashing

Deterministic hashing is required for version control.  
Implementation outline (`Core\Hashing\CanonicalJson`):

1. Accept native PHP values (arrays / scalars).
2. Recursively sort **associative** keys lexicographically.
3. Preserve order of **indexed** arrays.
4. Encode via `json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`.
5. Return both the canonical JSON string and SHA-256 hash (lowercase hex).

This process is O(n log n) due to sorting; caching is planned but not part of the initial prototype.

---

## 5. Brain Storage Model

`.brain` files reside under:

- `system/storage/system.brain` (framework metadata, auth keys, active brain pointer).
- `user/storage/<slug>.brain` (user data).

### 5.1 File Schema (JSON Object)

Top-level keys:

| Key | Type | Purpose |
|-----|------|---------|
| `meta` | object | Brain metadata (slug, UUIDv4, timestamps, schema version). |
| `projects` | object | Map of projects keyed by slug. |
| `commits` | object | Global lookup by commit hash (optional optimisation). |

Project object:

```json
{
  "slug": "demo",
  "title": "Demo Project",
  "created_at": "2025-01-01T12:00:00Z",
  "updated_at": "2025-01-01T12:00:00Z",
  "entities": {
    "article": {
      "active_version": "3",
      "versions": {
        "1": {
          "version": 1,
          "hash": "abc...",
          "commit": "abc...",
          "committed_at": "2025-01-01T11:00:00Z",
          "status": "inactive",
          "payload": { "...": "..." }
        },
        "3": {
          "version": 3,
          "hash": "def...",
          "commit": "def...",
          "committed_at": "2025-01-01T12:00:00Z",
          "status": "active",
          "payload": {}
        }
      }
    }
  }
}
```

Commits object (optional but enables quick lookup):

```json
"commits": {
  "def...": {
    "project": "demo",
    "entity": "article",
    "version": "3"
  }
}
```

The structure intentionally keeps associative maps at every layer to retain deterministic ordering during canonical encoding.

### 5.2 Brain Repository Responsibilities

- Load `.brain` JSON into PHP arrays.
- Validate required keys; supply defaults on new files.
- Provide CRUD helpers: `listProjects()`, `saveEntity()`, `listVersions()`, etc.
- Persist changes via canonical encoding to ensure deterministic bytes & hashes.
- Emit storage events on significant operations (project/entity creation, version commits).
- Maintain a global commit lookup (`commits`) to resolve hashes → project/entity/version.

Any write must be atomic (write to temp file, `rename()`).

---

### 5.3 Entity Version Lifecycle

1. `saveEntity(project, entity, payload, meta)`  
   - Canonicalises payload → SHA-256 hash.  
   - Calculates next version number (`versions` max + 1).  
   - Produces commit metadata (`project`, `entity`, `version`, `hash`, `timestamp`, `meta`) and hashes it deterministically.  
   - Marks previous active version as `inactive`, persists new record, updates project timestamps, and stores commit lookup.  
   - Emits `brain.entity.saved`.

2. `getEntityVersion(project, entity, ref = null)`  
   - Returns active version when `ref` is `null`.  
   - Accepts direct version identifiers or commit hashes (resolved via global lookup).

3. `listProjects()` / `listEntities(project)`  
   - Provide lightweight metadata maps for discovery without exposing payloads.

This lifecycle ensures deterministic persistence while keeping read operations inexpensive.

---

### 5.4 Atomic Persistence Guarantees

- Brain files are written via temporary files located in the same directory, using exclusive locks and a single atomic `rename`.
- After the swap, the file is re-read immediately; the SHA-256 hash and canonical JSON bytes must match the pre-write payload.
- Any mismatch triggers a retry (once) and emits diagnostic events:
  - `brain.write.retry`
  - `brain.write.integrity_failed`
  - `brain.write.completed` (on success, includes final hash and attempt count)
- Persistent failure raises a `StorageException`, preventing partially written data from being considered valid.

---

## 6. Command Registry

`Core\CommandRegistry` manages all callable commands:

- `register(string $name, callable $handler, array $metadata = []): void`
- `dispatch(string $name, array $params = []): CommandResponse`
- Names are stored in lowercase; aliases permitted via metadata.
- `CommandResponse` is a value object (status, message, payload, meta).
- Parser integration: `CommandRegistry::setParser()` is invoked during bootstrap, enabling modules to call `registerParserHandler()` or provide `['parser' => callable|array]` metadata when registering commands.

Initial built-in commands (to be supplied by system modules later) will follow the same interface.

---

## 7. Event Bus

`Core\EventBus` offers a minimal publish/subscribe mechanism:

- `on(string $event, callable $listener): void`
- `emit(string $event, array $payload = []): void`
- Supports wildcard listeners (`system.*`).
- Listeners execute synchronously for now.

Events will be namespaced (`brain.loaded`, `command.executed`, `storage.commit.created`).

---

## 8. Command Parsing Layer

The parser converts human-readable statements (`"save demo article {...}"`) into structured parameters for the dispatcher.  
Implementation class: `Core\CommandParser`.

### 8.1 Baseline Behaviour

1. Normalize the statement (`trim`, collapse whitespace).
2. Extract first token as the command action (e.g. `save`, `list`).
3. Split the remaining segment into argument tokens respecting quoted strings.
4. Detect trailing JSON blocks (payload) and decode them deterministically.
5. Produce a `ParsedCommand` value object containing:
   - `action` – normalized command name
   - `tokens` – ordered list of argument tokens
   - `payload` – decoded JSON payload or `null`
   - `parameters` – default parameter bag (`tokens`, `payload`, `raw`)
   - `raw` metadata (original statement, argument substring, JSON substring)

### 8.2 Extensibility

- `CommandParser::registerHandler(?string $action, callable $handler, int $priority = 0)` allows modules to customise parsing.
- Handlers receive a mutable `ParserContext` and may:
  - transform or replace tokens/payload/parameters,
  - override the resolved action (`setAction()`),
  - stop further handler processing (`stopPropagation()`).
- Global handlers register with `null` action and run for every statement prior to command-specific handlers.

### 8.3 Integration Points

- Modules register handlers during their initialisation (e.g. to interpret `entity:version` syntax).
- The facade `AavionDB::command()` uses the parser and forwards structured parameters to `run()`.
- Parser diagnostics expose registered handler counts to aid debugging.
- `BrainRepository` will later expose convenience helpers for registering parser extensions tied to entity syntax (e.g. `entity:version`), keeping parsing and storage extensions aligned.
- Modules may register handlers directly through `CommandRegistry::registerParserHandler()` or by supplying `['parser' => ...]` metadata during `register()`.

---

## 9. Diagnostic Data

`AavionDB::diagnose()` returns:

- Framework version & git hash (`RuntimeState`).
- Loaded modules (names, versions, autoload flags).
- Active brains (system + user).
- Command registry statistics (#commands, duplicates, aliases).
- Storage integrity summary (hash verification, file timestamps).
- Parser handler overview (global + per-action counts) for debugging command extensions.

Diagnostics pull data exclusively from public service APIs to mirror the unified response model.

---

## 10. Documentation & Changelog

- This file remains the authoritative core spec.
- `docs/dev/MANUAL.md` will link here and focus on conceptual overview.
- Internal decisions, migration notes, and implementation breadcrumbs will be tracked in `.codex/NOTES.md`.
- All functional changes must update `/CHANGELOG.md` (to be created when runtime components land).

---

## 11. Next Steps

1. Update `docs/dev/MANUAL.md` to reference this blueprint.
2. Scaffold filesystem layout (`system/Core/...`, `system/Storage/...`).
3. Implement façade (`system/core.php`) with bootstrap stub.
4. Provide initial tests & fixtures once modules and commands are in place.

---
