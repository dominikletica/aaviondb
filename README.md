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

## 🧩 Command Overview

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
| `list entities <project>` / `entity list <project>` | List entities belonging to a project. |
| `list versions <project> <entity>` / `entity versions <project> <entity>` | Enumerate versions for an entity. |
| `list commits <project> [entity] [limit=50]` / `project commits …` | Show recent commits (optionally filtered by entity). |
| `show <project> <entity[@version|#commit]>` / `entity show …` | Render the active or selected entity version as JSON. |
| `save <project> <entity[@version|#commit][:fieldset[@version|#commit]]> {payload}` / `entity save …` | Create or merge a new entity version (supports partial payload updates and schema selectors). |
| `remove <project> <entity[,entity2]>` / `entity remove …` | Deactivate the active version(s) without purging history. |
| `delete <project> <entity[,entity2]>` / `entity delete …` | Permanently delete entities (all versions and commits). |
| `delete <project> <entity@version[,entity2#commit]>` | Remove specific versions or commits without deleting the entity. |
| `restore <project> <entity> <@version|#commit>` / `entity restore …` | Reactivate an archived version. |

### Project Management
| Command | Description |
|----------|-------------|
| `project create <slug> [title="..."] [description="..."]` | Create a project with optional metadata. |
| `project update <slug> [title="..."] [description="..."]` | Update project title/description. |
| `project remove <slug>` | Archive (soft delete) a project. |
| `project delete <slug> [purge_commits=1]` | Permanently delete a project (optionally purge commit history). |
| `project info <slug>` | Display project summary and statistics. |

### Brain Management
| Command | Description |
|----------|-------------|
| `brains` | List all available brains (system + user). |
| `brain init <slug> [switch=1]` | Create a new user brain and optionally activate it. |
| `brain switch <slug>` | Switch the active brain. |
| `brain backup [slug] [label=name]` | Create a backup for the specified (or active) brain. |
| `brain info [slug]` | Show metadata for the active or specified brain. |
| `brain validate [slug]` | Run integrity diagnostics. |
| `brain delete <slug>` | Permanently delete a non-active brain. |
| `brain cleanup <project> [entity] [keep=0]` | Purge inactive versions, optionally preserving the newest `keep` versions. |

### Export Commands
| Command | Description |
|----------|-------------|
| `export <project[,project…]|*> [entity[,entity[@version|#commit]]] [description="..."] [usage="..."] [preset]` | Generate deterministic JSON exports for one or multiple projects/entities (supports presets and guidance fields). |

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
| `auth revoke <token|preview>` | Revoke a token via full value or preview. |
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
| `cache status` | Show cache enablement, TTL, directory, and entry counts (removes expired artefacts). |
| `cache enable` | Enable caching globally. |
| `cache disable` | Disable caching and flush artefacts. |
| `cache ttl <seconds>` | Update the default TTL. |
| `cache purge [key=...] [tag=a,b]` | Purge cache entries (global, per key, or filtered by tags). |

### Security & Rate Limiting
| Command | Description |
|----------|-------------|
| `security config` / `security status` | Display security configuration and lockdown state. |
| `security enable` | Enable rate limiting enforcement. |
| `security disable` | Disable enforcement and purge cached counters/blocks. |
| `security lockdown [seconds]` | Trigger a manual lockdown (defaults to configured duration). |
| `security purge` | Remove cached security artefacts. |

### Logging
| Command | Description |
|----------|-------------|
| `log [level=ERROR|AUTH|DEBUG|ALL] [limit=10]` | Tail the framework log with optional level and limit filters. |

Commands can be invoked universally across interfaces:

REST:
```bash
GET api.php?command
{
"list projects"
}
```

PHP:
```php
AavionDB::command('list projects')
```

CLI:
```bash
php api.php list projects
```

Note: The preferred method is to use a parametric approach passing parameters and values: `brain`, `project`, `entity`, `ref`, `payload`, ...

---

## 🧭 Usage
Simply place the framework in your project directory and run the setup command to initialize it.

```bash
php api.php setup
```
or inside PHP:
```php
include aaviondb/api.php;
AavionDB::setup(); //lazily bootstraps; repeated calls are ignored within the same request
```

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
