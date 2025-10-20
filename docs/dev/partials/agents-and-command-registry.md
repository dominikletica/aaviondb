# Agents & Command Registry

> **Status:** Maintained  
> **Last updated:** 2025-10-20

## Agents
- Agents encapsulate cohesive command sets (core, brain, entity, project, export, auth, api, ui, log…).  
- Implemented as modules; each agent registers commands via `CommandRegistry` and optional parser handlers/events.

## Command Registry
- Methods:  
  - `register(string $name, callable $handler, array $meta = [])`  
  - `registerParserHandler(?string $action, callable $handler, int $priority = 0)`  
  - `dispatch(string $name, array $parameters = []): CommandResponse`
- Normalises command names to lowercase. Duplicate registrations raise `CommandException`.

## Dispatch Guarantees
- All responses return `CommandResponse` (status/message/data/meta).  
- Unexpected exceptions logged via Monolog and returned as `status = error` with `meta.exception`.  
- Emits `command.executed`/`command.failed` for diagnostics.

## Parsing
- `CommandParser` interprets human-readable statements, supports JSON payloads, quotes, module-specific handlers.  
- Parser errors raise `CommandException` which callers must catch and convert into user-friendly messages.

## Future Work
- Permissions per command (role/key-based).  
- Structured metadata for auto-generated docs/help.  
- Batch/transaction support for grouped commands.  
- Passive command profiling hooks for performance tuning.
