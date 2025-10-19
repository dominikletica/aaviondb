# ApiAgent Module

> Status: Implemented – orchestrates REST gateway enablement and telemetry.

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

## Implementation Notes
- Module files live in `system/modules/api`; classes autoload from `classes/`.
- Uses `BrainRepository::systemAuthState()` for readiness checks and telemetry, and `setApiEnabled()` to toggle availability.
- `api serve` aborts if no active tokens exist, prompting operators to run `auth grant`.
- Commands log notable transitions (`notice` level) through the shared PSR-3 logger.

## Outstanding Tasks
- [ ] Extend telemetry with rolling request counters once REST rate tracking is implemented.
- [ ] Integrate with the future Scheduler/Log agents for automated enable/disable windows.
