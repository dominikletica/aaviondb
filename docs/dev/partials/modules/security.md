# SecurityAgent Module

> Status: Implemented – exposes rate limiting, lockdown, and security cache controls.

## Responsibilities
- Bridge `SecurityManager` controls to CLI/REST (`security` command).
- Allow operators to toggle enforcement, trigger manual lockdowns, and purge security artefacts.
- Surface the current configuration (`security.*` config keys) and lockdown metadata.

## Commands
- `security config` / `security status` – Display whether security is enabled, active configuration values, lockdown state, and reason.
- `security enable` / `security disable` – Toggle the enforcement flag; disabling also purges cached counters/blocks.
- `security lockdown [seconds]` – Manually trigger a lockdown (duration defaults to `security.ddos_lockdown`).
- `security purge` – Remove cached counters, blocks, and lockdown artefacts (tagged `security:*`).

## Implementation Notes
- Module is located in `system/modules/security`; manifest requests `security.manage`, `commands.register`, `parser.extend`, and `logger.use` capabilities.
- Backed by `AavionDB\Core\Security\SecurityManager`, which stores configuration in the system brain (`security.active`, `security.rate_limit`, `security.global_limit`, `security.block_duration`, `security.ddos_lockdown`, `security.failed_limit`, `security.failed_block`).
- Rate limiting state lives in the shared cache store (`user/cache/`) and is maintained even when general caching is disabled (forced writes via `CacheManager`).
- REST endpoint `api.php` now performs a three-step enforcement cycle: `preflight` (lockdown/block check) → `registerAttempt` → authentication guard; failed guards call `registerFailure`, successful calls use `registerSuccess`.
- Lockdown responses return HTTP 503 with a `Retry-After` header; per-client throttles respond with HTTP 429.

## Examples

### CLI
```bash
php cli.php "security lockdown 120"
```
```json
{
  "status": "ok",
  "action": "security",
  "message": "Security lockdown active for 120 seconds (until 2025-10-19T15:45:00+00:00).",
  "data": {
    "lockdown": true,
    "retry_after": 120,
    "locked_until": "2025-10-19T15:45:00+00:00"
  }
}
```

### REST
```bash
curl -H "Authorization: Bearer <token>" \
  "https://example.test/api.php?action=security&subcommand=config"
```

## Error Handling
- Invalid lockdown duration (`security lockdown foo`) → `status=error`, message `Lockdown duration must be a numeric value (seconds).`
- Non-positive duration → `status=error`, message `Lockdown duration must be greater than zero seconds.`
- Unknown subcommand → `status=error`, message `Unknown security subcommand "foo".`

> Upcoming work: whitelisting/allow-list management, telemetry output, and deeper audit logging (see `.codex/NOTES.md`).
