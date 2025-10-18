# 🤖 AAVION AGENTS – System Overview & Architecture

> **Version:** 0.1.0-dev  
> **Status:** Draft / Active Development  
> **Maintainer:** Dominik Letica  
> **License:** MIT  
>  
> For extended developer specifications, see  
> ➜ `/docs/dev/MANUAL.md`

---

## 🧩 Overview

**aavionDB** is a modular, self-contained PHP framework designed for flexible data management, JSON-based storage, and AI-compatible data export.  
It provides both **CLI and REST interfaces**, with a strong focus on transparency, modular extensibility, and deterministic version control.

The system is built around the concept of **“Brains”** – self-contained data environments that can be initialized, switched, backed up, and exported independently.

Each subsystem, or *Agent*, serves a specific role within the framework. Agents communicate through the **Aavion Core** and share a unified data layer.

---

## 🧠 Core Concept

At the heart of the framework lies the **Aavion Core** (`AavionDB`), which initializes, routes, and manages all active Agents and Modules.

Initialization occurs once per instance:

```php
AavionDB::setup(); // can only run once
```

Once initialized, the framework:
1. Loads all valid **System Modules** from `/system/modules/`
2. Loads all valid **User Modules** from `/user/modules/`
3. **Mounts the system brain (`system.brain`)** — this is the permanent internal data store used for framework state, API keys, and configuration.  
   - The `system.brain` is always active and cannot be replaced or deactivated.
4. Mounts the active **User Brain** (defined via `init {brain}`)
5. Registers all command interfaces (CLI, REST)
6. Starts the runtime context (CLI, API, or UI)

---

## 📦 Directory Structure

The default directory layout is designed for **clarity**, **security**, and **extendability**:
```
aavionDB/
│
├── system/
│   ├── modules/
│   │   └── /
│   │       ├── module.php        # Entry point for the system module
│   │       ├── manifest.json     # Optional metadata (autoload, versioning)
│   │       ├── assets/           # Optional module assets
│   │       └── templates/        # Optional UI templates
│   │
│   ├── storage/
│   │   └── system.brain          # Internal system database (JSON)
│   │
│   ├── assets/                   # Core UI and static resources
│   └── templates/                # Base UI templates
│
├── user/
│   ├── modules/
│   │   └── /
│   │       ├── module.php        # Entry point for user-defined module
│   │       └── manifest.json     # Optional metadata
│   │
│   ├── storage/
│   │   └── -.brain               # User data containers (Brains)
│   │
│   ├── exports/
│   │   └── -.json                # LLM-friendly exports
│   │
│   └── cache/                    # Temporary runtime data
│
├── docs/
│   ├── MANUAL.md                 # User documentation
│   └── dev/
│       └── MANUAL.md             # Developer and API specification
│
├── api.php                       # REST and PHP entry point and router
├── index.php                     # UI entry point
├── cli.php                       # CLI entry point (planned)
└── README.md                     # Project documentation
```

---

## ⚙️ Module System

Modules are the functional core of aavionDB.  
They are **self-contained directories** with a `module.php` entry point and optional metadata in `manifest.json`.

Each module can expose one or more of the following:
- **Commands** (CLI verbs like `list`, `save`, `export`, etc.)
- **REST endpoints** (auto-registered)
- **UI components** (for the web console)
- **Event hooks** (triggered by other Agents)
- **Storage schemas** (for persistent data)

### Example Module Structure
```
user/modules/example/
├── module.php
├── manifest.json
├── assets/
│   └── style.css
└── templates/
└── dashboard.html
```

### Minimal `module.php`
```php
<?php
return [
    'name' => 'ExampleModule',
    'version' => '1.0.0',
    'author' => 'Dominik Letica',
    'init' => function($core) {
        $core->registerCommand('example', function($args) {
            return "Hello from ExampleModule!";
        });
    }
];
```

---

## 🧱 Storage & Versioning

All data is stored as **JSON structures** within *Brain files* (`.brain`).  
Each entity, commit, and version is deterministically hashed to ensure integrity and easy diffing.

- **Current version** is always explicitly marked.
- **Commit hashes** are deterministic (e.g., SHA-256 over serialized content).
- **Rollback / Restore** can target specific hashes or versions.

Brains are portable and can be swapped between instances without breaking integrity.

---

## 🌐 REST & CLI Agents

Two major Agents handle communication:

| Agent | Description |
|--------|--------------|
| **CLI Agent** | Parses and executes all console commands. Supports nested verbs, e.g. `list projects`, `export project:entity`. |
| **REST Agent** | Provides an HTTP interface for remote access (served through `api.php`). Authentication via API key. |

Each Agent uses the same Command Registry, ensuring consistent behavior across interfaces.

---

## 🧩 UI Agent

The **UI Agent** offers a web-based console and graphical interface:  
- Interactive shell (simulates CLI)  
- Tree view for Brains, Entities, and Versions  
- Visual feedback for `save`, `export`, `restore`, etc.  
- API key authentication  
- Optional live preview for JSON entities  

UI assets live in:
```
system/assets/
system/templates/
```

User-defined UI modules can extend this interface.

---

## 🔐 Authentication Agent

Manages API key generation, validation, and revocation.

| Command | Description |
|----------|-------------|
| `auth grant` | Generates a new API key (16 chars, alphanumeric). |
| `auth revoke {key}` | Invalidates an existing key. |
| `auth list` | (Planned) Lists all valid keys and their scopes. |

---

## 🧠 Brains Agent

Manages all logical user-level databases (Brains).

> ⚠️ **Note:** The `system.brain` is always active and cannot be deactivated or deleted.  
> It stores framework internals such as API keys, access control, and system settings.

| Command | Description |
|----------|-------------|
| `brains` | Lists all available Brains. |
| `init {brain}` | Activates or creates a new user-level Brain. |
| `backup {brain}` | Duplicates current Brain with a timestamp. |
| `delete {brain}` | Removes the specified Brain (with confirmation). |

User Brains can be swapped at runtime, while `system.brain` remains mounted as a persistent, immutable system layer.

---

## 🧰 Export Agent

Exports entities or projects into **LLM-friendly JSON slices**.  
Includes metadata headers and field normalization for improved parsing.

| Command | Description |
|----------|-------------|
| `export {project}` | Exports entire project to JSON. |
| `export {project} {entity[:version]}` | Exports single entity or version. |
| `export {project} [list]` | Exports multiple entities as array bundle. |

---

## 🧩 Simple Storage Agent

A lightweight key-value store for quick access to unversioned data,  
ideal for configuration parameters and runtime flags.

| Command | Description |
|----------|-------------|
| `set {key} {value}` | Sets a key-value pair. |
| `get {key}` | Retrieves a value. |
| `get` | Lists all stored keys. |

---

## 📡 API Agent

Responsible for REST handling and lifecycle management.

| Command | Description |
|----------|-------------|
| `api serve` | Launches API endpoint handler (`api.php`). |
| `api stop` | Stops API service. |
| `api reset` | Generates a new API key. |

---

## 🧩 Planned Integrations

| Type | Description |
|------|--------------|
| **SQLite Driver** | Optional backend for relational data. |
| **Git Adapter** | Version bridging to native Git commits. |
| **LLM Exporter (v2)** | Extended metadata and embedding support. |
| **WebSocket Bridge** | Real-time data synchronization for the UI. |

---

## 🧩 Development Notes

- Built entirely in **PHP ≥ 8.1**, no persistent daemon required.
- Node.js and Composer are **development-only dependencies**.
- Versioning uses **deterministic commit hashes**.
- All modules and agents follow **auto-discovery patterns**.
- Data integrity is ensured via **SHA-256 fingerprints**.
- Full modular isolation: system modules cannot override user modules unless explicitly configured.

---

## 🧩 Related Documentation

For detailed class specifications, module hooks, and agent internals, refer to:

📘 [../docs/dev/MANUAL.md](/docs/dev/MANUAL.md)

---

© 2025 **Dominik Letica** — All rights reserved.  
All trademarks and names are property of their respective owners.

