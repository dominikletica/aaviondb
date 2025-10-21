> **IMPORTANT NOTICE:**  
> This project is under active development and not yet ready for production use.  
> Clone, test, and experiment at your own risk.

# 🧠 Welcome to AavionDB

**AavionDB** is a modular, lightweight PHP framework for flexible data management, built around JSON serialization and designed to work in almost any PHP environment.  
It offers both a **native PHP API** and a **REST interface** for full integration into your existing projects.

## ✨ Key Features

- **User-friendly API calls** – Access data via PHP class or REST interface.
- **Structured flat-file storage** – Stores project data in modular, human-readable JSON files.  
  *(Future versions may add optional SQL backends.)*
- **Flexible fieldsets** for universal adaptability.
- **Lightweight and headless** – No persistent server process required.
- **Shared-host ready** – Only PHP ≥ 8.1 required, no Node.js or database servers needed.
- **Extensible architecture** – Custom modules and plugins can add new functionality.
- **UI integration hooks** – AavionDB exposes interfaces for the separate **AavionStudio** front-end (planned).
- **Export functionality** – Create JSON-based slices for integration with tools like ChatGPT or other LLMs.  
  These parser-friendly files act as contextual datasets for AI tools that can’t natively store large amounts of structured data.

## 📚 Documentation

- [User Manual](user/MANUAL.md) – Step-by-step guide in plain language with practical examples.
- [Developer Manual](dev/MANUAL.md) – Technical reference covering architecture, modules, and call flows.
- [Command Reference](dev/commands.md) and [Class Map](dev/classmap.md) – Quick lookup tables kept in sync with core updates.

## 🧩 Quick Command Overview

### Core & Diagnostics
| Command | Description |
|----------|-------------|
| `status` | Show a concise runtime snapshot (version, brains, modules). |
| `diagnose` | Output detailed diagnostics (paths, container, modules, brains). |
| `help [command]` | List available commands or show details for a specific one. |

### Listing & Entity Lifecycle
| Command | Description |
|----------|-------------|
| `list projects` / `project list` | List all projects in the active brain. |
| `list entities <project> [parent/path]` / `entity list <project> [parent/path]` | List entities belonging to a project. Provide a hierarchy path to scope results (e.g. `characters/heroes`). |
| `list versions <project> <entity>` / `entity versions <project> <entity>` | Enumerate versions for an entity. |
| `list commits <project> [entity] [limit=50]` / `project commits …` | Show recent commits (optionally filtered by entity). |
| `show <project> <entity[@version or #commit]>` / `entity show …` | Render the active or selected entity version as JSON. |
| `save <project> <entity-path[@version or #commit][:fieldset[@version or #commit]]> {payload}` / `entity save …` | Create or merge a new entity version. Use slash paths (`parent/child`) to assign hierarchy, bind schemas, or reposition an entity via `--parent` (payload optional when only moving). |
| `move <project> <source-path> <target-path> [--mode=merge/replace]` / `entity move …` | Reassign an entity (including its subtree) to a new parent path without touching payloads. |
| `remove <project> <entity-path[,entity2]> [--recursive=0/1]` / `entity remove …` | Deactivate entities. Default promotes child entities to the root; `--recursive=1` archives the entire subtree. Warnings surface when children are moved. |
| `delete <project> <entity-path[,entity2]> [--recursive=0/1]` / `entity delete …` | Permanently delete entities. Default promotes children before deleting the parent; `--recursive=1` purges descendants and their commits. Target specific versions with selectors (`entity@7`, `entity#hash`). |
| `delete <project> <entity@version[,entity2#commit]>` | Remove specific versions or commits without deleting the entity. |
| `restore <project> <entity> <@version or #commit>` / `entity restore …` | Reactivate an archived version. |

### Project Management
| Command | Description |
|----------|-------------|
| `project create <slug> [title="..."] [description="..."]` | Create a project with optional metadata. |
| `project update <slug> [title="..."] [description="..."]` | Update project title/description. |
| `project remove <slug>` | Archive (soft delete) a project (deactivates entities automatically). |
| `project restore <slug> [--reactivate=0/1]` | Restore an archived project; optionally reactivate entities/versions in-place. |
| `project delete <slug> [purge_commits=1]` | Permanently delete a project (optionally purge commit history). |
| `project info <slug>` | Display project summary and statistics. |

### Brain Management
| Command | Description |
|----------|-------------|
| `brains` | List all available brains (system + user). |
| `brain init <slug> [switch=1]` | Create a new user brain and optionally activate it. |
| `brain switch <slug>` | Switch the active brain. |
| `brain backup [slug] [label=name] [--compress=1]` | Create a backup for the specified (or active) brain (optionally gzip-compressed). |
| `brain backups [slug]` | List stored backups (optionally filter by brain slug). |
| `brain backup prune <slug/*> [--keep=10] [--older-than=30] [--dry-run=1]` | Remove backups according to retention rules (preview with `--dry-run`). |
| `brain info [slug]` | Show metadata for the active or specified brain. |
| `brain validate [slug]` | Run integrity diagnostics. |
| `brain delete <slug>` | Permanently delete a non-active brain. |
| `brain cleanup <project> [entity] [keep=0] [--dry-run=1]` | Purge inactive versions (preview with `--dry-run`, optionally preserve the newest `keep` versions). |
| `brain compact [project] [--dry-run=1]` | Rebuild commit indexes and reorder entity versions for faster lookups. |
| `brain repair [project] [--dry-run=1]` | Fix entity metadata (active version, status, timestamps) when inconsistencies are detected. |
| `brain restore <backup> [target] [--overwrite=0] [--activate=0]` | Restore a brain from a backup file (optionally activate immediately). |

### Export Commands
| Command | Description |
|----------|-------------|
| `export <project[,project…] or *> [entity[,entity[@version or #commit]]] [description="..."] [usage="..."] [preset]` | Generate deterministic JSON exports for one or multiple projects/entities (supports presets and guidance fields). |

### Configuration & Key-Value Store
| Command | Description |
|----------|-------------|
| `set <key> [value] [--system]` | Store or delete a config entry (JSON payloads supported; omitting `value` deletes the key). |
| `get [key] [--system]` | Retrieve a config value or list all entries. |

### Authentication
| Command | Description |
|----------|-------------|
| `auth grant [label="..."] [projects=...]` | Issue a scoped API token (default scope `*`). |
| `auth list` | List existing tokens with metadata. |
| `auth revoke <token or preview>` | Revoke a token via full value or preview. |
| `auth reset` | Reset authentication state to bootstrap defaults. |

### REST API Control
| Command | Description |
|----------|-------------|
| `api serve` | Enable the REST API (requires at least one non-bootstrap token). |
| `api stop` | Disable the REST API (idempotent). |
| `api status` | Display REST telemetry (enabled flag, last request, token counts). |
| `api reset` | Disable REST and revoke all tokens. |

### Scheduler & Cron
| Command | Description |
|----------|-------------|
| `scheduler add <slug> <command>` | Register a scheduled CLI command. |
| `scheduler edit <slug> <command>` | Update a stored command. |
| `scheduler list` | List scheduler tasks. |
| `scheduler log [limit=20]` | Show recent scheduler runs. |
| `scheduler remove <slug>` | Delete a scheduled task. |
| `cron` | Execute all scheduled tasks (available via CLI and REST without authentication). |

### Cache Management
| Command | Description |
|----------|-------------|
| `cache status` | Show cache enablement, TTL, directory, entry counts, cumulative size, tag distribution, and remove expired artefacts. |
| `cache enable` | Enable caching globally. |
| `cache disable` | Disable caching and flush artefacts. |
| `cache ttl <seconds>` | Update the default TTL. |
| `cache purge [key=...] [tag=a,b]` | Purge cache entries (global, per key, or filtered by tags). |

### Security & Rate Limiting
| Command | Description |
|----------|-------------|
| `security config` / `security status` | Display security configuration, lockdown state, and rate-limit cache telemetry. |
| `security enable` | Enable rate limiting enforcement. |
| `security disable` | Disable enforcement and purge cached counters/blocks. |
| `security lockdown [seconds]` | Trigger a manual lockdown (defaults to configured duration). |
| `security purge` | Remove cached security artefacts. |

### Schema Management
| Command | Description |
|----------|-------------|
| `schema list [with_versions=1]` | List fieldset schemas with optional version metadata. |
| `schema show <slug[@version or #commit]>` | Show a schema revision (defaults to the active version). |
| `schema lint {json}` | Validate a JSON Schema payload before persisting it. |
| `schema create <slug> {json}` | Create a new schema entity. |
| `schema update <slug> {json} [--merge=0 or 1]` | Update an existing schema (default is replace, `--merge=1` merges). |
| `schema save <slug> {json} [--merge=0 or 1]` | Upsert helper that creates the schema when missing. |
| `schema delete <slug> [@version or #commit]` | Delete a schema or a specific revision. |

### Logging
| Command | Description |
|----------|-------------|
| `log [level=ERROR or AUTH or DEBUG or ALL] [limit=10]` | Tail the framework log with optional level and limit filters. |
| `log rotate [keep=10]` | Rotate the active log file and keep only the most recent archives. |
| `log cleanup [keep=10]` | Delete archived log files beyond the retention threshold. |

### Events
| Command | Description |
|----------|-------------|
| `events listeners` | List registered event listeners with their counts. |

Commands can be invoked universally across interfaces:

REST:
```bash
curl -X POST https://example.test/api.php \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"command":"list projects"}'
```
or
```bash
GET api.php?action=list&scope=projects
```

PHP:
```php
require __DIR__ . '/aaviondb.php';

AavionDB::command('list projects');
// or for more control:
AavionDB::run('list', ['scope' => 'projects',]);
```

CLI:
```bash
php api.php list projects
```

> Note: The preferred method is to use a parametric approach passing parameters and values: `brain`, `project`, `entity`, `ref`, `payload`, ...  

All available commands and parameters can be found in the developers manual -> [MANUAL.md](dev/MANUAL.md)

---

## 🧭 Usage
Simply place the framework in your project directory and run the setup command to initialize it.

```bash
php api.php setup
```
or inside PHP:
```php
require __DIR__ . '/aaviondb.php';

// Optional: customise setup options before the first command.
AavionDB::setup([
    // 'active_brain' => 'default',
]);
```

`aaviondb.php` loads Composer's autoloader and ensures the framework is bootstrapped exactly once per request, so subsequent `AavionDB::run()` or `AavionDB::command()` calls are ready to use.

More commands will be documented as development progresses.

### Configuration

- Copy `config.example.php` to `config.php` and adjust the values before exposing AavionDB publicly.
  - `admin_secret` — Master secret (must start with `_` and be ≥ 8 characters). When set, it bypasses API keys and `api serve`; keep it safe.
  - `default_brain` — Slug of the initial user brain (defaults to `default`).
  - `backups_path`, `exports_path`, `log_path` — Override storage locations (relative paths resolve from the repo root).
  - `response_exports` / `save_exports` — Control whether export commands return JSON in the response and/or persist files to disk.
  - `api_key_length` — Length of generated API keys (default 16).
  - `cache.active`, `cache.ttl` — Stored in the system brain; manage via `cache` commands or `set cache.* --system=1`.
  - `security.active`, `security.rate_limit`, `security.global_limit`, `security.block_duration`, `security.ddos_lockdown`, `security.failed_limit`, `security.failed_block` — Rate limiter defaults (tweak with `security` commands or `set security.* --system=1`).
- If `config.php` is missing, the built-in defaults are used and the admin secret remains disabled (empty string).

### REST API

- Endpoint: `api.php?action=<command>` (e.g. `api.php?action=list&scope=projects`).
- JSON payloads are accepted in the request body (`POST`, `PUT`, `PATCH`, `DELETE`).
- Responses always follow the unified schema `{status, action, message, data, meta}`.
- All errors are returned with HTTP 400/500; PHP exceptions are logged, not exposed.
- REST remains disabled until the CLI command `api serve` toggles `api.enabled = true` and a non-bootstrap token exists.  Provide the token via `Authorization: Bearer <token>` (fallback: `X-API-Key` header or `token`/`api_key` parameter).  The bootstrap key `admin` never grants REST access.

### CLI

- Execute commands from the shell:

```bash
php cli.php "list projects"
```

- Exit code `0` indicates success; `1` signals an error.
- Output is JSON-formatted for easy piping to other tools.

---

## 🧰 Development Notes
- Node.js and Composer are used **only for development**; production deployments require PHP 8.1+ only.
- Install dependencies locally via `composer install` and `npm install` after cloning (both directories are ignored in git).
- When packaging releases, ship only production-ready dependencies (omit dev packages such as PHPUnit or frontend build tooling).
- Versioning is handled internally via deterministic commit hashes for integrity verification.
- JSON exports are structured for high parser compatibility, making aavionDB ideal for AI and automation workflows.
- Vendors (`/vendor`) and Node modules (`/node_modules`) are excluded from version control.  
  Run `composer install` and `npm install` to fetch dependencies locally.  
  When preparing releases, bundle only production dependencies.

---

## 🧑‍💻 Support & Contact
This project is still in early development — use at your own risk.  
No support or issue tracking until version **1.0 stable**.

---

© 2025 **Dominik Letica** – All rights reserved.

---
