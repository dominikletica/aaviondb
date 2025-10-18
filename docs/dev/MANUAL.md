# 🧩 AAVION DEVELOPMENT MANUAL

> **Document Version:** 0.1.0-dev  
> **Framework Core:** AavionDB  
> **Audience:** Developers and Module Authors  
> **Location:** `/docs/dev/MANUAL.md`  
>
> This manual defines the technical specification, internal structure, and integration guidelines for developing custom modules, agents, and extensions within **aavionDB**.  
>
> For a conceptual overview, see [/.codex/AGENTS.md](../../.codex/AGENTS.md).

---

## 📘 Table of Contents
1. Core Architecture  
2. File Structure  
3. Bootstrap Process  
4. Brains & Storage Engine  
5. Modules & Autoloading  
6. Agents & Command Registry  
7. REST API Layer  
8. UI & Web Console  
9. Authentication & API Keys  
10. Versioning & Commit Hashes  
11. Hooks, Events & Listeners  
12. Coding Standards  
13. Extending AavionDB  
14. Security & Permissions  
15. Appendix  

---

## 🧠 Core Architecture

> Detailed implementation blueprint: [`docs/dev/core-architecture.md`](./core-architecture.md)

The **Aavion Core** (`AavionDB`) orchestrates all framework components.  
It handles initialization, routing, storage, and API dispatch.

**Responsibilities**
- Bootstraps framework environment  
- Mounts `system.brain` (always active)  
- Loads user modules  
- Manages all active Agents  
- Provides configuration and runtime state  

**Key Files**

| File | Purpose |
|------|----------|
| `/api.php` | Multi-interface API entry point |
| `/index.php` | UI entry point |
| `/system/storage/system.brain` | Persistent framework data store |
| `/system/core.php` | Framework root class |

---

## ⚙️ Bootstrap Process

### Execution Flow
1. Call `AavionDB::setup()`  
2. Load system modules  
3. Load user modules  
4. Mount `system.brain` (always active)  
5. Mount active user brain (via `init {brain}`)  
6. Register commands  
7. Initialize API/UI contexts  

### Example

```php
require_once __DIR__ . '/system/core.php';
AavionDB::setup();
AavionDB::command('list projects'); // or: AavionDB::run(list, ['projects'])
```

---

## 🧩 Brains & Storage Engine

Brains are logical data containers.

### System Brain
`system.brain` is always mounted and stores:  
- API keys  
- framework metadata  
- active brain reference  
- configuration  

### User Brains
Independent environments for project data.

**Example Path**

`/user/storage/demo.brain`

Each Brain is JSON-based with deterministic commit hashes.

---

## 🧱 Modules & Autoloading

Modules are discovered automatically.

**Discovery Rules**
- Scans `/system/modules` and `/user/modules`  
- Requires `module.php`  
- Optional `manifest.json`  

```json
{
  "name": "ExampleModule",
  "version": "1.0.0",
  "author": "Dominik Letica",
  "autoload": true
}
```

**Lifecycle**
1. Discover  
2. Validate  
3. Register  
4. Initialize  

**Example**

```php
return [
  'name' => 'ExampleModule',
  'init' => function ($core) {
      $core->registerCommand('example', fn() => "Hello from module!");
  }
];
```

### 🧩 System Modules (Foundation Layer)

System modules are built-in components that define the essential logic and commands of the AavionDB core.  
They are loaded before any user-defined modules and form the **foundation layer** of the framework.

#### 🧱 Purpose
System modules encapsulate self-contained functionality responsible for core operations such as:
- Brain initialization and I/O
- Project and entity management
- Versioning and commit control
- API dispatch and unified response handling
- Authentication and key validation
- Export and backup processes

Each module exposes its own command set to the internal dispatcher (PHP/REST/CLI).  
These commands are atomic and context-agnostic — meaning they can be executed identically in any environment.

#### 🧠 Core System Modules
| Module | Description |
|---------|-------------|
| `CoreAgent` | Bootstraps the framework and manages setup, info, and status commands |
| `BrainAgent` | Handles loading, mounting, and switching of user brains |
| `ProjectAgent` | Manages project creation, removal, and restoration |
| `EntityAgent` | Handles entity CRUD and version control |
| `ExportAgent` | Manages data exports and JSON serialization |
| `AuthAgent` | Generates and validates API keys stored in `system.brain` |
| `ApiAgent` | Exposes all commands through the REST layer |
| `UiAgent` | Provides the web console, visualization, and frontend hooks |

Each module operates autonomously but communicates through the unified Aavion event system  
(e.g., `entity.saved`, `brain.switched`, `auth.key.generated`).

#### 🔄 Initialization Sequence
1. CoreAgent loads and verifies `system.brain`
2. BrainAgent mounts the active user brain
3. All system modules register their commands
4. Command registry becomes globally available to REST and CLI contexts
5. Framework is ready for external or programmatic access

#### 🧩 Future Extension Layer
Once the system modules are stable, additional user-defined modules can extend functionality  
(e.g., analytics, scheduled tasks, or integration bridges).  
These follow the same discovery mechanism but are loaded *after* all system modules have initialized.

---

> **Design Goal:**  
> The system module layer must always remain operational and independent of user space.  
> It should provide a complete, self-contained functionality required to perform all documented operations,
> even if no user-defined modules are present.

---

## 🧠 Agents & Command Registry

Agents group commands.

```php
AavionDB::registerCommand('list projects', function () {
    return ProjectManager::list();
});
```

| Agent | Role |
|--------|------|
| CoreAgent | System commands |
| BrainAgent | Brain management |
| ExportAgent | Exports |
| AuthAgent | API keys |
| ApiAgent | REST |
| UiAgent | UI bridge |

---

## 🌐 REST API Layer

The REST Agent exposes all internal AavionDB commands as structured HTTP endpoints.  
Requests follow a hybrid syntax, combining REST principles with the internal command structure.

---

### 🔑 Authentication

Each request must include a valid API key in the header:

```bash
Authorization: Bearer <API_KEY>
```

Keys are validated against active entries in `system.brain`.

---

### 🧭 URL Structure

```text
/api.php?action=<command>[&project=<slug>][&entity=<slug>][&ref=<version|hash>]
```

**Examples**

| Example | Meaning |
|----------|----------|
| `/api.php?action=list&scope=projects` | Lists all available projects |
| `/api.php?action=list&project=demo` | Lists all entities in project `demo` |
| `/api.php?action=show&project=demo&entity=test` | Shows current version of entity `test` |
| `/api.php?action=show&project=demo&entity=test&ref=102` | Shows specific version or commit |
| `/api.php?action=save&project=demo&entity=test` | Saves or updates entity `test` |
| `/api.php?action=remove&project=demo&entity=test` | Marks entity inactive |
| `/api.php?action=restore&project=demo&entity=test&ref=hash` | Restores entity version by hash |
| `/api.php?action=export&project=demo` | Exports all entities of project `demo` |

---

### 📬 Methods & Payloads

| Method | Typical Usage | Expected Body |
|---------|----------------|----------------|
| **GET** | Fetch or list entities | none |
| **POST** | Create or update entity | JSON payload (dynamic fieldset) |
| **PATCH** | Restore or modify version | optional version/hash |
| **DELETE** | Remove or deactivate entity | none |

**Example POST Request**

```bash
POST /api.php?action=save&project=demo&entity=test
Authorization: Bearer 2b18a9df37e91b5a
Content-Type: application/json
```

```json
{
  "title": "Sample Entity",
  "content": "This is a test entry stored in project demo.",
  "meta": {
    "author": "Dominik Letica"
  }
}
```

**Response**

```json
{
  "status": "ok",
  "project": "demo",
  "entity": "test",
  "version": "auto-managed",
  "commit": "b8f7a3d29e...",
  "message": "Entity successfully saved"
}
```

---

### 🧩 Supported Commands

| Command | Action | Description |
|----------|----------|-------------|
| `list` | GET | Lists projects or entities |
| `show` | GET | Shows specific entity or version |
| `save` | POST | Creates or updates entity |
| `remove` | DELETE | Marks entity inactive |
| `restore` | PATCH | Restores a specific version or hash |
| `export` | GET | Exports data as structured JSON |
| `backup` | GET | Creates Brain snapshot |
| `delete` | DELETE | Permanently deletes entity or project |

_(For a more detailed overvies see project's [README.md](../../README.md))_

---

### 🧰 Response Format

```json
{
  "status": "ok",
  "action": "save",
  "project": "demo",
  "entity": "test",
  "version": 103,
  "commit": "b8f7a3d29e...",
  "data": { ... },
  "message": "Entity updated successfully"
}
```

**Error Example**

```json
{
  "status": "error",
  "code": 403,
  "message": "Invalid or missing API key"
}
```

---

## 🧩 Unified Response Model (CLI · PHP · REST)

AavionDB uses a unified internal dispatch layer for all interaction types.  
Every command — regardless of how it is called — returns the same structured response format.

### 🧠 Supported Contexts
| Context | Entry Point | Typical Use |
|----------|--------------|--------------|
| **REST** | `/api.php` | External API access via HTTP |
| **CLI** | `/api.php` | Administrative shell interface |
| **PHP** | `AavionDB::run()` | Internal framework integration |

---

### 🧰 Example Equivalents

| Type | Example | Description |
|------|----------|-------------|
| REST | `/api.php?action=show&project=demo&entity=test` | Public API request |
| CLI | `php api.php show demo test` | Console execution |
| PHP | `AavionDB::run('show', ['project' => 'demo', 'entity' => 'test']);` | Direct internal call |

---

### 🧾 Unified Response Format

All layers return standardized JSON-encoded responses with consistent structure and keys.

```json
{
  "status": "ok",
  "action": "show",
  "project": "demo",
  "entity": "test",
  "version": 103,
  "commit": "b8f7a3d29e...",
  "data": { ... },
  "message": "Entity successfully loaded."
}
```

In CLI or PHP mode, the same response is returned as:
- **CLI:** pretty-printed JSON or table (depending on verbosity)
- **PHP:** associative array or object implementing `JsonSerializable`

---

### 🧩 Benefits
- Identical API structure across all interfaces  
- Simplified debugging and integration  
- Easy testing and mocking (the REST API can be locally simulated via PHP calls)  
- One single validation and permission layer  
- Consistent versioning and commit handling  

---

### 💡 Implementation Note

Internally, all calls — even REST requests — are dispatched through:

```php
$response = AavionDB::run($action, [
    'project' => $_GET['project'] ?? null,
    'entity'  => $_GET['entity'] ?? null,
    'ref'     => $_GET['ref'] ?? null,
    'payload' => json_decode(file_get_contents('php://input'), true)
]);
```
Other commands — such as those specified in the README.md or added dynamically through modules — are mapped accordingly.
Modules may register additional parameters or override existing behaviors, but all responses conform to the unified API schema.

The returned `$response` object always includes `status`, `message`, and `data` fields.  
When the environment is REST, it’s encoded as JSON with appropriate headers.

---

## 🎨 UI & Web Console

Provides:
- Interactive console  
- Tree view for entities  
- API integration  
- Theme & dark mode  
- Markdown rendering  

Assets path:
```text
/system/assets/
/system/templates/
```

---

## 🔐 Authentication & API Keys

Stored inside `system.brain` under `_auth`.

| Command | Description |
|----------|-------------|
| `auth grant` | Generate key |
| `auth revoke {key}` | Invalidate key |
| `auth list` | (Planned) List valid keys |

---

## 🧬 Versioning & Commit Hashes

An auto-incremental version number is assigned to each entity.  
Deterministic SHA-256 hashing ensures data integrity.

```json
{
  "id": "entity5",
  "project": "project1",
  "version": "13",
  "hash": "8e7a0d6c3d2b...7e9a1",
  "timestamp": "2025-10-18T04:12:31Z",
}
```

---

## 🪝 Hooks, Events & Listeners

Modules can register event hooks.

```php
$core->on('entity.saved', function($entity) {
    Logger::info("Saved entity {$entity['id']}");
});
```

| Event | Trigger |
|--------|----------|
| `entity.saved` | After saving |
| `brain.switched` | After brain activation |
| `auth.key.generated` | After API key creation |

---

## 🧩 Extending AavionDB

1. Create `/user/modules/MyAgent/module.php`  
2. Register commands via `$core->registerCommand()`  
3. Add REST/UI features as needed  

---

## 🔒 Security & Permissions
- API-key validation for all writes  
- `system.brain` is not exposed to user space  
- Planned: module sandboxing  

---

## 📎 Appendix
- `/.codex/AGENTS.md` Overview  
- `/CHANGELOG.md`  
- `/LICENSE`
