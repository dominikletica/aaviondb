# EventsAgent Module

> Status: Implemented – lightweight event bus diagnostics.

## Responsibilities
- Report registered event listeners via the shared `EventBus`.
- Provide a simple CLI/REST entry point (`events listeners`) for quick inspections.
- Serve as a foundation for future stream/subscription tooling.

## Commands
- `events listeners` – Return the listener count per event (sorted ascending).

## Call Flow
- `system/modules/events/module.php` instantiates `AavionDB\Modules\Events\EventsAgent` and calls `register()`.  
- `EventsAgent::registerParser()` rewrites `events` statements to `events listeners` by default.  
- `listenersCommand()` queries `ModuleContext::events()->listenerCount()`, sorts the map, and returns the counts via `CommandResponse::success()`.

## Key Classes & Collaborators
- `AavionDB\Modules\Events\EventsAgent` – parser + command registrar.  
- `AavionDB\Core\EventBus` – exposed through `ModuleContext::events()`; provides listener metadata.  
- `AavionDB\Core\Modules\ModuleContext` – shared entry point for command registry and event bus.  
- `AavionDB\Core\CommandResponse` – response envelope.

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
