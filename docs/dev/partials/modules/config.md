# ConfigAgent Module

> **Status:** Implemented – configuration helpers for active/system brains.  
> **Last updated:** 2025-10-20

## Responsibilities
- Provide lightweight `set` / `get` commands to mutate or inspect configuration values.
- Support both active user brain and system brain through the `--system` flag.
- Parse scalar and JSON values conveniently (booleans, numbers, `null`, JSON objects/arrays).
- Accept bulk updates via JSON payloads to synchronise multiple keys in one call.

## Commands
- `set <key> [value] [--system=1]` – Store a configuration value. Omitting `value` removes the key. Accepts JSON payload (via `{}` or `[]`) for complex structures. Passing a JSON object payload without a key performs a bulk update (multiple keys processed in one call; `null` deletes a key).
- `get [key|*] [--system=1]` – Retrieve a specific value or list all entries when no key is provided (`*` explicitly requests all keys).

## Call Flow
- `system/modules/config/module.php` instantiates `AavionDB\Modules\Config\ConfigAgent` and calls `register()`.  
- `ConfigAgent::registerParser()` hooks the global parser for the top-level `set` / `get` verbs, captures key/value pairs, and promotes payload data before dispatch.  
- `ConfigAgent::setCommand()` and `ConfigAgent::getCommand()` map directly to `BrainRepository::setConfigValue()`, `deleteConfigValue()`, and `listConfig()` with the `--system` flag selecting the system brain.  
- Scalar values are normalised through `normalizeScalar()` while JSON payloads are decoded via the injected parser context to keep CLI/REST behaviour identical.

## Key Classes & Collaborators
- `AavionDB\Modules\Config\ConfigAgent` – parser + command registrar.  
- `AavionDB\Storage\BrainRepository` – persists configuration entries in user/system brains.  
- `AavionDB\Core\Modules\ModuleContext` – supplies command registry and logger.  
- `AavionDB\Core\CommandResponse` – consistent response wrapper for success/error paths.

## Implementation Notes
- Module resides in `system/modules/config`. Capabilities include storage read/write and parser hooks.
- Parser recognises top-level `set` / `get` commands; inline `--system` flag toggles system brain mutations/reads.
- Values are normalised before persisting: strings representing booleans/numbers/null are cast; JSON literals are decoded; otherwise raw strings are stored.
- Bulk payloads (JSON objects) are decoded server-side; each entry is validated, written, or removed (`null`), and aggregate results are returned.

## Examples

### CLI
```bash
php cli.php "set welcome_message \"Hello Traveller\""
php cli.php "set features {\"beta\":true,\"quota\":5}"
php cli.php "set {\"feature.alpha\":true,\"feature.beta\":false}"   # bulk update
php cli.php "set {\"feature.alpha\":null}"                            # bulk delete via null
php cli.php "get features"
php cli.php "get *"
php cli.php "set maintenance --system"
php cli.php "get --system"
```

### PHP
```php
$response = AavionDB::run('set', [
    'key' => 'export.cache_ttl',
    'value' => '3600',
]);

$response = AavionDB::run('get', [
    'key' => 'export.cache_ttl',
]);
```

## Error Handling
- Missing key on `set` -> `status=error`, message `Configuration key is required.`
- Malformed JSON payloads (bulk or value) return `status=error` with the JSON parser message.
- Exceptions from storage propagate as `status=error` responses with diagnostics metadata.
- Bulk updates report skipped entries (empty keys) via the `warnings` array in the response.

## Outstanding Tasks
- [ ] Add bulk import/export helpers (e.g. load from JSON file).
- [ ] Expose history/audit trail for config changes.
- [ ] Provide namespaced key suggestions and validation heuristics.
