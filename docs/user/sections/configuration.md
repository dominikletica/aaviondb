# Configuration & Diagnostics

Tune framework behaviour and inspect runtime state with a few helper commands. All examples work via CLI, REST, and PHP just like the rest of the handbook.

---

## Set or Remove Configuration Values

Use the `set` command to store lightweight settings such as feature toggles or custom messages.

- `set <key> <value>` sets single entries.
- `set {"key":"value", ...}` sets multiple entries at once.

```bash
php cli.php 'set welcome_message "Hello Traveller"'
php cli.php 'set features {"beta":true,"quota":5}'
php cli.php 'set {"feature.alpha":true,"feature.beta":false}'
php cli.php 'set {"feature.alpha":null}'   # deletes feature.alpha
```

- Omitting the value deletes the key: `php cli.php "set welcome_message"` (or `null`).
- To write into the system brain (shared across all projects), add `--system`:

```bash
php cli.php 'set security.rate_limit 80 --system'
```

REST example:

```bash
curl -H "Authorization: Bearer <token>" \
  -d 'command=set cache.ttl 900' \
  https://example.test/api.php
```

PHP example:

```php
$response = AavionDB::run('set', [
    'key' => 'export.cache_ttl',
    'value' => '3600',
]);
```

---

## Read Configuration

```bash
php cli.php "get"             # list every key in the active brain
php cli.php "get *"           # shows all keys
php cli.php "get features"    # show one specific key
php cli.php "get --system"    # list system-wide keys
```

When called with REST, pass `action=get` (and optional `key`/`system=1`) in the query or JSON body.

---

## Status & Diagnostics

Inspect the framework at a glance:

- `status` – Quick snapshot (active brain, modules, API status, cache/security flags).  
- `diagnose` – Detailed report with filesystem paths, module metadata, and integrity info.  
- `help [command]` – List available commands or show usage details for a specific one.

Examples:

```bash
php cli.php "status"
php cli.php "diagnose"
php cli.php "help export"
```

Use `--debug` (or `debug=1` via REST) to include module-level debug logs for complex flows.

---

## Event & Log Utilities

- `events listeners` – See which modules listen to which events (helpful when debugging hook behaviour).  
- `log [level] [limit]` – Tail the framework log. Combine with `log rotate` / `log cleanup` to manage archives.

For log commands, remember the level filter:

```bash
php cli.php "log DEBUG 20"
php cli.php "log rotate keep=5"
```

---

## Brain Maintenance

- `brain backup [slug] [label="nightly"] [--compress=1]` – Create a snapshot (use `--compress` to save space).
- `brain backups [slug]` – List stored backups; omit the slug to see everything.
- `brain backup prune <slug|*> [--keep=10] [--older-than=30] [--dry-run=1]` – Remove backups via retention rules (keep latest N or delete older than N days).
- `brain restore <backup> [target] [--overwrite=0] [--activate=0]` – Restore a backup into a brain (preview target slug first).
- `brain cleanup <project> [entity] [keep=0] [--dry-run=1]` – Remove old inactive versions (preview with `--dry-run`).
- `brain compact [project] [--dry-run=1]` – Rebuild commit indexes and tidy version ordering for faster lookups.
- `brain repair [project] [--dry-run=1]` – Fix entity metadata (active versions, statuses, timestamps) when inconsistencies appear.

Use these commands after large imports, manual edits, or whenever diagnostics highlight mismatched metadata.

---

Next steps: [Performance & Cache](performance.md) keeps the system fast, while the [Troubleshooting & FAQ](troubleshooting.md) section lists common pitfalls.
