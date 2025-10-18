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
- **Web-based UI** – Interactive console and graphical data view (planned).
- **Export functionality** – Create JSON-based slices for integration with tools like ChatGPT or other LLMs.  
  These parser-friendly files act as contextual datasets for AI tools that can’t natively store large amounts of structured data.

## 🧩 Planned Command Overview

### Core Commands
| Command | Description |
|----------|-------------|
| `list projects` | Returns a list of all valid projects. |
| `list entities {project}` | Lists all valid entities within the specified project. |
| `list versions {project} {entity}` | Lists all versions of an entity. |
| `list commits {project} [entity]` | Returns all commits for a project (or a specific entity). |
| `show {entity}` | Displays the currently active version of an entity as structured JSON. |
| `show {entity} {version or commit_hash}` | Displays a specific version or commit of the entity. |
| `save {project} {entity} {full JSON}` | Saves an entity. If an ID exists, creates a new version and commit; otherwise creates a new entity. Returns version and deterministic commit hash. |
| `create {project}` | Creates a new project. |
| `remove {project} [entity]` | Marks the active version as invalid. |
| `restore {project} [entity] [version or commit_hash]` | Reactivates a previous version or commit. |
| `delete {project} [entity]` | Permanently deletes all versions of a specified project or entity (use with caution). |

### Export Commands
| Command | Description |
|----------|-------------|
| `export {project} [entities[:version or commit_hash]]` | Exports a project or subset as JSON for LLM use, including metadata for crawlers and parsers. |

---

### Simple Key-Value Storage
| Command | Description |
|----------|-------------|
| `set {key} {value}` / `get {key}` | Store and retrieve basic configuration values. |
| `get` | Lists all existing keys. *(These values are not versioned.)* |

---

### Brain Management
| Command | Description |
|----------|-------------|
| `brains` | Lists all available databases (“brains”). |
| `init {brain}` | Initializes or activates a brain (only one can be active at a time). |
| `backup {brain}` | Creates a snapshot of the active brain with timestamp. |
| `delete {brain}` | Permanently removes a brain. *(Use with extreme caution!)* |

---

### API Commands
| Command | Description |
|----------|-------------|
| `api serve` | Starts the REST API service (via `api.php`). |
| `api stop` | Stops the API service. |
| `api reset` | Regenerates the API key and invalidates the old one. |

---

### Authentication Commands
| Command | Description |
|----------|-------------|
| `auth grant` | Generates a new alphanumeric API key (16 characters). |
| `auth revoke {key}` | Invalidates the given API key. |

---

### Miscellaneous
| Command | Description |
|----------|-------------|
| `help` | Lists all available commands and usage syntax. |
| `status` / `info` | Displays framework version, memory usage, author info, GitHub link, and active brain. |

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
AavionDB::setup(); //can only run once
```

More commands will be documented as development progresses.

---

## 🧰 Development Notes
- Node.js and Composer are used **only for development**; production deployments require PHP 8.1+ only.
- Versioning is handled internally via deterministic commit hashes for integrity verification.
- JSON exports are structured for high parser compatibility, making aavionDB ideal for AI and automation workflows.

---

## 🧑‍💻 Support & Contact
This project is still in early development — use at your own risk.  
No support or issue tracking until version **1.0 stable**.

---

© 2025 **Dominik Letica** – All rights reserved.

---
