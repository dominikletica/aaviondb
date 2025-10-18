# CoreAgent Module (DRAFT)

> Status: Draft – placeholder for implementation details of the Core agent.

## Responsibilities
- Register runtime meta commands (`status`, `diagnose`, `help`) available across CLI/REST/PHP.
- Provide command metadata for auto-generated listings (consumed by `help`).
- Surface high-level diagnostics for other modules (module count, brain state, API flags).

## Commands
- `status`  
  Returns a concise snapshot: framework version, boot timestamp, active brain info, API enablement, module list, initialization errors, and brain footprint (file size + entity-version count).
- `diagnose`  
  Full diagnostic payload (`AavionDB::diagnose()`), exposing paths, modules, parser stats, brain integrity, etc.
- `help [command=name]`  
  Lists all registered commands (sorted) or shows detailed metadata for a specific command.

## Implementation Notes
- Delivered via `system/modules/core` with `CoreAgent` class registering handlers in `module.php`.
- System module capabilities: `commands.register`, `parser.extend`, `events.dispatch`, `paths.read`, `logger.use`.
- Responses leverage `CommandResponse` for consistent schema.

## Outstanding Tasks
- [ ] Extend `help` output once other modules provide richer metadata/usage examples.
- [ ] Add PHPUnit coverage (status/help edge cases, unknown command handling).
