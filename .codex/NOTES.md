# AavionDB – Developer Notes

> Maintainer: Codex (GPT-5)  
> Purpose: Track implementation decisions, open questions, and follow-up tasks during core development.

## 2025-02-14

- **Canonical JSON & Hashing**  
  - Sort associative keys recursively; keep numeric arrays ordered.  
  - Encode via `json_encode` with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.  
  - Hash using `sha256` over canonical JSON; store lowercase hex string alongside payload metadata.  
  - Pretty-printing is reserved for UI/export layers only.

- **Core Layout**  
  - Introduced `docs/dev/core-architecture.md` as the authoritative blueprint for the bootstrap, storage, and runtime services.  
  - Core namespace will live under `system/Core/*`; storage abstractions under `system/Storage/*`.

- **Pending**  
  1. Flesh out `BrainRepository` with entity/project CRUD and hash handling.  
  2. Implement command parsing utility for `AavionDB::command()`.  
  3. Add diagnostics coverage for brain integrity checks.

## 2025-02-14 – Evening Session

- Implemented base façade (`system/core.php`) with bootstrap, command dispatch, diagnostics wiring, and test-only reset helper.
- Added core services: `Container`, `CommandRegistry`, `CommandResponse`, `EventBus`, `RuntimeState`, and helper utilities.
- Created filesystem scaffolding (`PathLocator`) and initial storage layer (`BrainRepository`) including automatic system/user brain creation with canonical JSON persistence.
- Updated developer manual to link to the new core architecture blueprint.
