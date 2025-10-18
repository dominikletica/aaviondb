# Bootstrap Process (DRAFT)

> **Status:** Draft  
> **Last updated:** 0.1.0-dev

### Execution Flow
1. `AavionDB::setup()` *(idempotent)* validates options, ensures directory layout, and prepares services.  
2. Discover system modules (`ModuleLoader`) and register parser/command hooks.  
3. Mount `system.brain`, loading configuration (`config` map), auth keys, and runtime flags (`api_enabled`, etc.).  
4. Mount active user brain. If none selected, fallback to `default.brain` (auto-created).  
5. Discover user modules and register their contributions.  
6. Wire diagnostics (event bus, logger, module metadata, integrity report).  
7. Initialise UI/CLI contexts; REST remains disabled until `api serve` is executed.

### Usage Example
```php
require_once __DIR__ . '/system/core.php';

// setup() can safely be called multiple times; subsequent calls are no-ops.
AavionDB::setup();

// Execute a command via unified dispatcher.
$response = AavionDB::command('list projects');
```

### Notes
- `setup()` must be invoked before interacting with the framework; entry points (`api.php`, `cli.php`) call it automatically.  
- For tests, `AavionDB::_resetForTests()` resets the runtime to allow repeated bootstrap within the same PHP process (not for production use).  
- Bootstrap failures are logged via Monolog and surface as structured error responses; raw exceptions should never reach end users.
