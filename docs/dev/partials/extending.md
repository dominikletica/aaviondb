# Extending AavionDB

> **Status:** Maintained  
> **Last updated:** 2025-10-20

1. Create `user/modules/MyModule/module.php` (+ optional `manifest.json`).  
2. Register commands via the provided `CommandRegistry`/`EventBus` dependencies in `init()`.  
3. Add parser handlers if custom syntax is required (`registerParserHandler`).  
4. Add REST/UI integrations as needed (e.g., expose routes, add UI components).  
5. Document the module (README, config instructions) and add tests.

Guidelines:
- Keep modules focused and composable; avoid cross-cutting state mutations outside of events/commands.  
- Always return `CommandResponse`-compatible arrays (`status`, `message`, `data`, `meta`).  
- Log errors rather than throwing, unless interacting with low-level storage or security APIs (caller must handle).  
- Document new commands in `docs/dev/commands.md` and surface user-facing behaviour in the user manual.  
- Update `.codex/NOTES.md` with TODOs and roadmap entries for larger additions.
