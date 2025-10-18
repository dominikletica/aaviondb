# Hooks, Events & Listeners (DRAFT)

> **Status:** Draft  
> **Last updated:** 0.1.0-dev

- `EventBus` offers synchronous publish/subscribe (`emit`, `on`, wildcard support).  
- Core emits events such as:
  - `command.executed`, `command.failed` (registry)  
  - `brain.entity.saved`, `brain.entity.restored`, `brain.created`  
  - `auth.key.generated`, `auth.key.revoked`, `api.enabled`, `api.disabled`  
- Modules register listeners during `init()`; they must catch exceptions and log errors to avoid breaking the dispatch chain.

Planned: async/event queue adapters, per-module isolation/sandboxing, UI live feed integration.
