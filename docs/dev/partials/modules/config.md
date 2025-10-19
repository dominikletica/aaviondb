# ConfigAgent Module

> Status: Implemented – configuration helpers for active/system brains.

## Responsibilities
- Provide lightweight `set` / `get` commands to mutate or inspect configuration values.
- Support both active user brain and system brain through the `--system` flag.
- Parse scalar and JSON values conveniently (booleans, numbers, `null`, JSON objects/arrays).

## Commands
- `set <key> [value] [--system=1]` – Store a configuration value. Omitting `value` removes the key. Accepts JSON payload (via `{}` or `[]` literal or CLI payload) for complex structures.
- `get [key] [--system=1]` – Retrieve a specific value or list all entries when no key is provided.

## Implementation Notes
- Module resides in `system/modules/config`. Capabilities include storage read/write and parser hooks.
- Parser recognises top-level `set` / `get` commands; inline `--system` flag toggles system brain mutations/reads.
- Values are normalised before persisting: strings representing booleans/numbers/null are cast; JSON literals are decoded; otherwise raw strings are stored.
- Deleting a key wraps `BrainRepository::deleteConfigValue()`; updates call `setConfigValue()`.

## Examples

### CLI
```bash
php cli.php "set welcome_message \"Hello Traveller\""
php cli.php "set features {\"beta\":true,\"quota\":5}"
php cli.php "get features"
php cli.php "get"
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
- Exceptions from storage propagate as `status=error` responses with diagnostics metadata.

## Outstanding Tasks
- [ ] Add bulk import/export helpers (e.g. load from JSON file).
- [ ] Expose history/audit trail for config changes.
- [ ] Provide namespaced key suggestions and validation heuristics.
