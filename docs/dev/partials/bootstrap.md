# Bootstrap Process

> **Status:** Maintained  
> **Last updated:** 2025-10-20

### Execution Flow
1. `AavionDB::setup()` *(idempotent)* validates options, ensures directory layout, and prepares services.  
2. Discover system modules (`ModuleLoader`) and register parser/command hooks.  
3. Mount `system.brain`, loading configuration (`config` map), auth keys, scheduler tasks, cache state, and runtime flags (`api.enabled`, etc.).  
4. Mount active user brain. If none selected, fallback to `default.brain` (auto-created).  
5. Discover user modules and register their contributions.  
6. Wire diagnostics (event bus, logger, module metadata, cache/security telemetry, integrity report).  
7. Initialise CLI/PHP contexts; REST remains disabled until `api serve` is executed and a non-bootstrap token exists.

### Usage Example
```php
require_once __DIR__ . '/system/core.php';

// setup() can safely be called multiple times; subsequent calls are no-ops.
AavionDB::setup();

// Execute a command via unified dispatcher.
$response = AavionDB::command('list projects');
```

### Notes
- `setup()` must be invoked before interacting with the framework; entry points (`api.php`, `cli.php`, `aaviondb.php`) call it automatically.  
- For tests, `AavionDB::_resetForTests()` resets the runtime to allow repeated bootstrap within the same PHP process (not for production use).  
- Bootstrap failures are logged via Monolog and surface as structured error responses; raw exceptions should never reach end users.
- Use `AavionDB::isBooted()` when embedding the framework in long-running PHP processes.
