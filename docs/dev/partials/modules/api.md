# ApiAgent Module

> Status: Implemented – orchestrates REST gateway enablement and telemetry.  
> **Last updated:** 2025-10-20

## Responsibilities
- Toggle REST availability (`api serve`, `api stop`) while enforcing security preconditions.
- Surface REST diagnostics and metadata via `api status`.
- Provide an administrative reset that disables REST and revokes issued tokens.
- Record reason/actor metadata on API state changes for auditability.

## Commands
- `api serve [reason=text]` – Ensures at least one active token exists, enables REST, and returns the updated state (includes `changed` flag plus telemetry timestamps).
- `api stop [reason=text]` – Disables REST, recording the actor/reason; idempotent if already disabled.
- `api status` – Returns the current REST status (`enabled`, `active_tokens`, bootstrap flag, last request/enabled/disabled timestamps, last actor).
- `api reset` – Invokes `BrainRepository::resetAuthTokens()`, revoking all tokens and disabling REST in a single step; returns revocation count and `api_enabled=false`.

## Call Flow
- `system/modules/api/module.php` instantiates `AavionDB\Modules\Api\ApiAgent` and calls `register()`.  
- `ApiAgent::registerParser()` maps `api` verbs to canonical actions and captures optional `reason=` flags.  
- Command handlers:  
  - `apiServeCommand()` → loads auth state via `BrainRepository::systemAuthState()`, ensures active keys exist, then invokes `setApiEnabled(true, metadata)` to flip the flag.  
  - `apiStopCommand()` → mirrors serve but disables REST via `setApiEnabled(false, metadata)` and logs the transition.  
  - `apiStatusCommand()` → queries `systemAuthState()` and returns a telemetry snapshot (enabled flag, counts, timestamps).  
  - `apiResetCommand()` → wraps `resetAuthTokens()` to revoke keys, disables REST, and reports how many tokens were removed.  
- All handlers write to the shared logger with `source=api` and emit debug information when requested.

## Key Classes & Collaborators
- `AavionDB\Modules\Api\ApiAgent` – parser + command registrar.  
- `AavionDB\Storage\BrainRepository` – persistence for REST enablement, auth state, and metadata.  
- `AavionDB\Core\Modules\ModuleContext` – exposes command registry, logger, and diagnostics.  
- `AavionDB\Core\CommandResponse` – unified response object consumed by CLI/REST/PHP routes.

## Implementation Notes
- Module files live in `system/modules/api`; classes autoload from `classes/`.
- Uses `BrainRepository::systemAuthState()` for readiness checks and telemetry, and `setApiEnabled()` to toggle availability.
- `api serve` aborts if no active tokens exist, prompting operators to run `auth grant`.
- Commands log notable transitions (`notice` level) through the shared PSR-3 logger.

## Outstanding Tasks
- [ ] Extend telemetry with rolling request counters once REST rate tracking is implemented.
- [ ] Integrate with the future Scheduler/Log agents for automated enable/disable windows.
