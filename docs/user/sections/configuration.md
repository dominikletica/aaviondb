# Configuration & Diagnostics

Tune framework behaviour and inspect runtime state with a few helper commands. All examples work via CLI, REST, and PHP just like the rest of the handbook.

---

## Set or Remove Configuration Values

Use the `set` command to store lightweight settings such as feature toggles or custom messages.

```bash
php cli.php 'set welcome_message "Hello Traveller"'
php cli.php 'set features {"beta":true,"quota":5}'
```

- Omitting the value deletes the key: `php cli.php "set welcome_message"`.
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

Next steps: [Performance & Cache](performance.md) keeps the system fast, while the [Troubleshooting & FAQ](troubleshooting.md) section lists common pitfalls.
