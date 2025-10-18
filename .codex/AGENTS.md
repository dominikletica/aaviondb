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
│   │       ├── manifest.json     # Metadata (autoload, versioning, dependencies)
│   │       ├── assets/           # Optional module assets
│   │       └── templates/        # Optional UI templates
│   │
│   ├── storage/
│   │   └── system.brain          # Internal system database (JSON)
│   │
│   ├── assets/                   # Core UI and static resources
│   └── templates/                # Base UI templates
│   └── core.php                  # AavionDB-Class
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

## 🧩 Command Dispatch Layer

> **Design Goal:**  
> Every interface — whether REST, CLI, or PHP — must interpret and execute commands using the same unified syntax and parsing logic.

The command layer defines a single, human-readable syntax that is valid across **all runtime contexts**.  
Each command can be expressed as a simple, space-delimited instruction, optionally followed by structured JSON data.

### 🧭 Universal Command Format

**Syntax:**
```
[action] [project] [entity] {optional JSON payload}
```

**Examples:**
```
save demo article { "title": "Hello", "content": "World" }
show demo article
remove demo article
export demo article:102
```

### 💡 Interface Equivalence

All supported interfaces must accept the same structure:

| Context | Example | Notes |
|----------|----------|-------|
| **CLI** | `php cli.php "save demo test {"title":"My Entry"}"` | Direct console command |
| **PHP** | `AavionDB::command('save demo test {"title":"My Entry"}');` | Internal execution |
| **REST (preferred)** | `POST /api.php?action=save&project=demo&entity=test` | Structured parameter version |
| **REST (unified syntax)** | `POST /api.php?command` with body: `{ "save demo test": { "title": "My Entry" } }` | Optional unified syntax mode |

### ⚙️ Parsing Rules

- Commands are case-insensitive.  
- JSON payloads must be enclosed in `{}` braces.  
- CLI and REST commands share identical dispatch logic internally (`CommandParser::parse()`).  
- The parser automatically extracts:
  - `action` → verb (e.g. save, show, list, remove)
  - `project` → target project slug
  - `entity` → entity identifier
  - `payload` → JSON object (optional)
- Invalid or malformed JSON payloads must trigger a standardized error response.

### 🧩 Dispatch Flow

1. The raw command string is parsed by `CommandParser::parse()`.
2. Parsed parameters are normalized into a structured array:
   ```php
   [
     'action'  => 'save',
     'project' => 'demo',
     'entity'  => 'test',
     'payload' => [ 'title' => 'Hello', 'content' => 'World' ]
   ]
   ```
3. The dispatcher (`CommandDispatcher`) routes the command to the responsible **Agent**.
4. The result is returned as a unified response object.

### 🧬 Internal API

The framework provides two main entry points for internal execution:

```php
AavionDB::run('save', [
    'project' => 'demo',
    'entity'  => 'test',
    'payload' => ['title' => 'Hello']
]);

// OR, for unified syntax compatibility
AavionDB::command('save demo test {"title":"Hello"}');
```

Both calls must produce identical results and response objects.

### 🔁 Design Rationale

- **Full parity** between REST, CLI, and PHP.  
- **Simplified testing** — same commands usable in all contexts.  
- **Future integrations** (e.g., WebSocket or REPL interfaces) easily supported.  
- **Reduced API surface area** — one parser, one dispatcher.  
- **Predictable, language-agnostic behavior** ideal for LLM agents.

---

## 🧩 Development Guidelines & Quality Standards

The following directives define the development philosophy and quality goals for **AavionDB**.  
All Codex-generated code, internal modules, and system components **must adhere to these standards**.

### 🧩 Architectural Principles

- **Core Minimalism:**  
  The `/system/core.php` file must remain a thin orchestration layer.  
  It only coordinates module loading, dependency resolution, event routing, and API dispatching.  
  All actual functionality resides in modules.

- **Modular Everything:**  
  Each module defines a specific, limited area of responsibility.  
  Functionality should never be duplicated across modules.  
  Cross-module interaction must happen **only via AavionDB’s internal event bus or public class APIs**  
  exposed under the `AavionDB\` namespace.

- **Dependency Management:**  
  System and user modules declare dependencies through a manifest (`manifest.json` or `module.php` meta array).  
  The loader must ensure:
  - correct initialization order,
  - cyclic dependency prevention,
  - deferred activation until all required modules are ready.

- **Composable Design:**  
  Modules must be written to allow selective loading and replacement.  
  No module may assume that another non-system module exists.

### 💎 Code Quality Standards

- **Language & Version:** PHP ≥ 8.1  
- **Coding Standard:** PSR-12  
- **Namespace Convention:** `AavionDB\ModuleName\ClassName`  
- **File Naming:**  
  - main file → `module.php`  
  - optional classes → in `/classes` subdirectory  
  - manifest → `manifest.json`  

- **Documentation:**  
  - Every public function must include a PHPDoc block.  
  - Complex operations (e.g. commit calculation, dependency resolution) require inline doc comments.  
  - Each module provides a top-level header comment describing its purpose and dependencies.

### 🧪 Testing & Validation

- **Unit Tests:**  
  All modules must include unit tests in `/tests` using `phpunit`.  
  Tests should validate both functional behavior and data integrity (e.g., deterministic commit hashes).

- **Integration Tests:**  
  A minimal integration test suite should confirm interaction between core modules.  
  This ensures commands like `save`, `list`, `restore`, and `export` behave identically  
  across REST, CLI, and PHP entry points.

- **Self-Diagnostics:**  
  The framework must include an internal `AavionDB::diagnose()` method  
  that checks for missing modules, dependency errors, or schema mismatches.

- **Diagnostic Interface:**  
  The framework must also provide a graphical diagnostic dashboard integrated into the standard UI.  
  This interface can be accessed as sub-page via the UI (`index.php`) and must display system diagnostics,  
  loaded modules, storage metrics, logs, and version integrity checks.  

  The diagnostic UI is built using the same toolchain as the main interface:  
  **Vite + TailwindCSS + PostCSS + Alpine.js**, with **Tabler Icons** for all visual indicators.  
  Components must be modular and re-usable within the broader web console.  

  It should provide:
  - A web-based interactive shell (powered by **xterm.js** or a similar lightweight terminal emulator)
  - A read-only tree-view for navigating projects, entities, and versions (via **jsTree** or **VanillaTree**)
  - A live event log viewer for real-time framework events
  - Optional buttons for invoking framework commands that mirror CLI output inside the shell

  All diagnostic data must be obtained via internal API calls to ensure full compatibility  
  with the REST and PHP dispatch layers.

### 🧱 Design Objectives

| Goal | Description |
|------|--------------|
| **Consistency** | All API layers return the same unified response structure. |
| **Transparency** | Every module action can be traced through logged events. |
| **Reliability** | Deterministic versioning and reversible commits. |
| **Extensibility** | Modules can register new commands and REST endpoints. |
| **Maintainability** | Small, focused classes; no monolithic logic blocks. |
| **Readability** | PSR-12 formatting and meaningful naming conventions. |

### 💡 Developer Note

Codex should:
- **Generate small, focused PHP classes** for each functional responsibility.  
- **Document every method and argument** with type hints and docblocks.  
- **Automatically generate tests** that assert expected responses for common commands  
  (e.g., `list projects`, `save entity`, `export project`).  
- **Ensure deterministic hash generation** for version control validation.  
- **Never rely on global state** — all runtime data flows through injected or event-based contexts.  

---

© 2025 **Dominik Letica** — All rights reserved.  
All trademarks and names are property of their respective owners.
