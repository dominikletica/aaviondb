# EventsAgent Module

> Status: Implemented – lightweight event bus diagnostics.

## Responsibilities
- Report registered event listeners via the shared `EventBus`.
- Provide a simple CLI/REST entry point (`events listeners`) for quick inspections.
- Serve as a foundation for future stream/subscription tooling.

## Commands
- `events listeners` – Return the listener count per event (sorted ascending).

## Implementation Notes
- Module lives in `system/modules/events` and requires `events.dispatch`, `commands.register`, `parser.extend`, and `logger.use` capabilities.
- Listener counts originate from `EventBus::listenerCount()`; diagnostics are read-only and inexpensive.
- The default command is an alias of `events listeners`, so `events` without arguments provides the same output.

## Examples

### CLI
```bash
php cli.php "events listeners"
```

### REST
```bash
curl "https://example.test/api.php?action=events&subcommand=listeners"
```

## Outstanding Tasks
- [ ] Expand telemetry (recent events, emit counters, listener origins).
- [ ] Add optional streaming/subscription support for the Studio UI.
