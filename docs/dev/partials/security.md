# Security & Permissions (DRAFT)

> **Status:** Draft – evolving alongside core hardening

- **REST Hardening** – `AuthManager` guards `api.php` using hashed tokens stored in `system.brain`. Bootstrap token `admin` is rejected for REST, and the endpoint remains disabled until `api.enabled = true`.  
- **Token Telemetry** – Successful calls update usage metadata (`last_used_at`, `last_request_at`) for forthcoming log/analytics modules.  
- **Module Lifecycle Signals** – `ModuleLoader` emits `module.initialized` / `module.initialization_failed`, allowing watchdog modules to react to misbehaving extensions.  
- **Capability Whitelist** – Modules run inside a capability-scoped context (`commands.register`, `storage.read`, …). System modules receive full access by default; user modules must opt in to additional privileges, and missing capabilities raise runtime exceptions.  
- **Next Steps** – Finalise module sandboxing (execution scopes, command permissions), define rotation policies (`auth grant/revoke/reset`), and wire outputs into the planned logging module. Track progress in `.codex/NOTES.md`.
