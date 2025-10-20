# Performance & Cache

AavionDB includes a built-in cache and housekeeping commands that keep the framework responsive as your data grows.

---

## Cache Overview

- Cache files live in `user/cache/`.
- Entries expire automatically based on the configured TTL (time-to-live).
- The cache invalidates itself when source data changes, so stale data is avoided.

---

## Inspect Cache Status

```bash
php cli.php "cache status"
```

Shows whether the cache is enabled, the current TTL, entry counts, size, and tag distribution. Expired entries are removed as part of this call.

---

## Enable or Disable the Cache

```bash
php cli.php "cache enable"
php cli.php "cache disable"
```

Disabling the cache also purges existing artefacts.

---

## Adjust the TTL

```bash
php cli.php "cache ttl 900"
```

Sets the default TTL to 15 minutes (900 seconds). Configure shorter durations for highly dynamic datasets.

---

## Purge Cache Entries

- `cache purge` – remove everything.
- `cache purge key=export.storyverse` – remove a specific key.
- `cache purge tag=export,preset` – remove entries that match any tag.

Use tags in presets or modules to group related artefacts.

---

## When to Purge

- After significant schema changes.
- Before running large exports if you need fresh data immediately.
- When debugging new presets or scheduler tasks.

---

## Complementary Tools

- `brain cleanup` removes inactive entity versions to reduce storage footprint.
- `project info` and `list versions` help identify entities with large histories.
- Use the debug flag (`--debug` or `debug=1`) to log cache hits and misses during troubleshooting.

---

For problem solving tips, jump to [Troubleshooting & FAQ](troubleshooting.md).
