# LogAgent Module

> Status: Implemented – log inspection for Monolog output.

## Responsibilities
- Tail `aaviondb.log` with level-based filters.
- Surface authentication-related entries via the shared log channel (`category=AUTH`).
- Provide limit-based slicing so CLI/REST calls remain efficient.

## Command
- `log [level=ERROR|AUTH|DEBUG|ALL] [limit=10]` – Show the most recent log lines matching the requested level (default `ERROR`) and limit (default `10`).

## Implementation Notes
- Module lives in `system/modules/log`; manifest enables `paths.read` and `logger.use` capabilities.
- Reads from `system/storage/logs/aaviondb.log` (path configurable via `log_path`).
- Filters are case-insensitive; `AUTH` relies on log entries tagged with `category=AUTH` (already emitted by AuthManager/AuthAgent).
- Entries are parsed into structured output: `timestamp`, `channel`, `level`, `message`, `context`, `extra`, `raw`.

## Examples

### CLI
```bash
php cli.php "log error limit=5"
```
```json
{
  "status": "ok",
  "action": "log",
  "message": "Showing up to 5 \"ERROR\" entries.",
  "data": {
    "level": "ERROR",
    "limit": 5,
    "path": "/system/storage/logs/aaviondb.log",
    "entries": [
      {
        "timestamp": "2025-10-19T10:32:11.123456+00:00",
        "channel": "aaviondb",
        "level": "ERROR",
        "message": "Failed to grant auth token",
        "context": {
          "scope": "RW",
          "category": "AUTH"
        }
      }
    ]
  }
}
```

### REST
```bash
curl -H "Authorization: Bearer <token>" \
  "https://example.test/api.php?action=log&level=AUTH&limit=3"
```
REST responses share the same JSON envelope and return HTTP 200 even when no entries match (the `message` clarifies that nothing was found).

### PHP
```php
$response = AavionDB::run('log', [
    'level' => 'ALL',
    'limit' => 20,
]);
```

## Error Handling
- Invalid level → `status=error`, message `Invalid level "FOO". Allowed: ERROR, AUTH, DEBUG, ALL.`
- Non-numeric limit → `status=error`, message `Limit must be a numeric value.`
- Log file missing → `status=ok`, empty `entries` array, message `Log file not found; no entries to display.`
- File read errors (permission issues) → `status=error` with `meta.exception` describing the failure.

> Rotation/cleanup commands are planned; see `.codex/NOTES.md` for upcoming tasks.
