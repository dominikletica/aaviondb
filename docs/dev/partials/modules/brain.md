# BrainAgent Module

> Status: Implemented – brain discovery, activation, and validation commands.

## Responsibilities
- Manage brain discovery, activation, initialisation, and backups.
- Expose configuration helpers (system vs user brains) for other modules.
- Publish integrity snapshots and maintenance hooks (validation, future cleanup).

## Commands
- `brains` – List system and user brains with metadata (type, size, entity versions, active flag).
- `brain init <slug> [switch=1]` – Create a new user brain and optionally activate it.
- `brain switch <slug>` – Set the specified brain as active.
- `brain backup [slug] [label=name]` – Create a timestamped backup copy in `user/backups/`.
- `brain info [slug]` – Return detailed information for the requested brain (defaults to active brain).
- `brain validate [slug]` – Run integrity diagnostics (checksum, last write/failure metadata).
- *(Planned)* `brain cleanup [slug] [project]` – Purge inactive versions on explicit request.

## Implementation Notes
- Module lives in `system/modules/brain` and leverages new helpers in `BrainRepository` (`listBrains`, `createBrain`, `setActiveBrain`, `backupBrain`, `brainReport`, `integrityReportFor`).

## Examples

### CLI
```bash
php cli.php "brains"
```
```json
{
  "status": "ok",
  "action": "brains",
  "data": {
    "count": 2,
    "brains": [
      {"slug": "system", "type": "system", "active": false},
      {"slug": "default", "type": "user", "active": true}
    ]
  }
}
```

### REST
```bash
curl -H "Authorization: Bearer <token>" \
  "https://example.test/api.php?action=brain%20info&slug=default"
```
Returns `status=ok` with the project/entity summary for the active brain.

### PHP
```php
$response = AavionDB::run('brain validate', ['slug' => 'default']);
```

## Error Handling
- Missing slug for commands that require one (`brain switch`, `brain backup`, `brain info`) triggers `status=error` with message `Parameter "slug" is required.`
- Referencing a non-existent brain raises `status=error` with an explanatory message from `BrainRepository`.
- Backups surface filesystem issues via `meta.exception` while logging the underlying error.
- Parser handler rewrites human-friendly statements (`brain init foo`) to structured commands + parameters.
- Backups are stored under `user/backups/`; PathLocator now ensures the directory exists.
- Diagnostics include footprint metrics (bytes + entity-version count) consumed by CoreAgent `status`.

## Outstanding Tasks
- [ ] Implement `brain cleanup` command (brain/project scope) once removal semantics are finalised.
- [ ] Add PHPUnit coverage for brain lifecycle + backup edge cases.
