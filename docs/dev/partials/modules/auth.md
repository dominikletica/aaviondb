# AuthAgent Module

> Status: Implemented – baseline command set is available.

## Responsibilities
- Manage API token lifecycle (grant/list/revoke/reset) and maintain REST enablement flags.
- Support scoped tokens (ALL/RW/RO/WO) with optional project filters (`*` or CSV).
- Integrate with configuration (`config.php`) for admin secret, key length, and storage paths.
- Enforce scoped access for REST callers by driving `AavionDB::withScope()` and storage-level guards.

## Commands
- `auth grant [scope=RW] [projects=*] [label=name]` – Creates a new token, returning the plain value once. Scope defaults to `RW`; projects accept CSV (or `*`).
- `auth list` – Lists existing tokens (masked), including status, scope, projects, timestamps.
- `auth revoke <token|hash>` – Revokes a specific token (plain token or SHA-256 hash).
- `auth reset` – Revokes all tokens, re-enables bootstrap mode, and disables the REST API.

## Scope Semantics
- `ALL` – Full read/write access to every project. Project filters are ignored.
- `RW` – Read/write access limited to the declared projects (or all when `*` is used).
- `RO` – Read-only access for the declared projects.
- `WO` – Placeholder for upcoming write-only flows. Currently behaves like `RW` to keep command responses consistent after write operations.

BrainRepository validates every read/write request against the active scope and project filters. REST requests automatically execute inside the granted scope via `AavionDB::withScope()`.

## Implementation Notes
- Module located in `system/modules/auth`; relies on `BrainRepository` helpers (`registerAuthToken`, `listAuthTokens`, `revokeAuthToken`, `resetAuthTokens`, `setApiEnabled`).
- Parser rewrites friendly syntax (`auth grant scope=RO projects=demo,lab`) into structured parameters.
- Admin secret (from `config.php`) bypasses token checks but is logged; scopes are stored alongside hashed tokens.
- `api.php` executes commands within the granted scope, ensuring storage lookups respect project filters.
- `BrainRepository` tracks token metadata, touches usage timestamps, and rejects unauthorized access based on scope.

## Outstanding Tasks
- [ ] Implement API serve/stop commands (will reside in ApiAgent but interacts with auth state).
- [ ] Prepare PHPUnit coverage for grant/revoke/reset paths and error cases.
