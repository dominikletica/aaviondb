# CacheAgent Module

> Status: Implemented – wraps the file-based cache subsystem for CLI/REST management.

## Responsibilities
- Toggle caching on/off at runtime (`cache.active` config key in the system brain).
- Expose TTL management (`cache.ttl`) and garbage-collect expired artefacts.
- Provide manual purge hooks (entire cache, single keys, or tagged subsets).

## Commands
- `cache status` – Show whether caching is enabled, the default TTL, cache directory, active entry count, cumulative size, tag distribution, and how many expired entries were removed during the check.
- `cache enable` / `cache disable` – Persistently toggle caching (disable also flushes artefacts).
- `cache ttl <seconds>` – Update the default TTL; values ≤ 0 are rejected.
- `cache purge [key=...] [tag=a,b]` – Remove cached artefacts. Without parameters everything is flushed; `key` targets a specific entry; `tag` accepts a comma-separated list of tags.

## Call Flow
- `system/modules/cache/module.php` instantiates `AavionDB\Modules\Cache\CacheAgent` and calls `register()`.  
- `CacheAgent::registerParser()` converts `cache ...` statements into a single `cache` command with a `subcommand` parameter (`status`, `enable`, `disable`, `ttl`, `purge`) and extracts flags like `key=` or `tag=`.  
- `CacheAgent::handleCacheCommand()` routes to dedicated methods: `statusCommand()`, `toggleCommand()`, `ttlCommand()`, `purgeCommand()`.  
- `CacheManager` (via `ModuleContext::cache()`) performs file system operations: `cleanupExpired()`, `statistics()`, `setEnabled()`, `setTtl()`, `purgeByKey()`, and tag-filtered deletions.

## Key Classes & Collaborators
- `AavionDB\Modules\Cache\CacheAgent` – parser + command registrar.  
- `AavionDB\Core\Cache\CacheManager` – JSON-file cache store under `user/cache/`.  
- `AavionDB\Core\Modules\ModuleContext` – provides cache service, command registry, and debug logger.  
- `AavionDB\Core\CommandResponse` – wraps results for CLI/REST/PHP parity.

## Implementation Notes
- Module lives in `system/modules/cache` and requires `cache.manage`, `commands.register`, `parser.extend`, and `logger.use` capabilities.
- The cache store is managed by `AavionDB\Core\Cache\CacheManager` using JSON files under `user/cache/`.
- Cache entries are flushed automatically on every successful brain write (`brain.write.completed` event). Manual purges are available for long-running CLI usage.
- `cache ttl` updates the system brain config (`cache.ttl`) and applies to newly written entries.
- Tag-based purges leverage metadata stored alongside each cache file (e.g. `security:*`, `export:*`).

## Examples

### CLI
```bash
php cli.php "cache status"
```
```json
{
  "status": "ok",
  "action": "cache",
  "message": "Cache is enabled (ttl=300s, 4 entries, 1.23 KiB, 0 expired removed).",
  "data": {
    "enabled": true,
    "ttl": 300,
    "directory": "user/cache",
    "entries": 4,
    "bytes": 1260,
    "expired_removed": 0,
    "tags": {
      "security": 2,
      "security:client:abc": 1,
      "export": 1
    }
  }
}
```

### REST
```bash
curl -H "Authorization: Bearer <token>" \
  "https://example.test/api.php?action=cache&subcommand=purge&tag=export"
```

## Error Handling
- Invalid TTL (`cache ttl foo`) → `status=error`, message `TTL requires a numeric value (seconds).`
- Non-positive TTL → `status=error`, message `TTL must be greater than zero.`
- Unknown subcommand → `status=error`, message `Unknown cache subcommand "foo".`

> Planned follow-ups: optional cache warmup helpers (see `.codex/NOTES.md`).
