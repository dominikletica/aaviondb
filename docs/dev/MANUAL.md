# 🧩 AAVION DEVELOPMENT MANUAL

> **Document Version:** 0.1.0-dev  
> **Framework Core:** AavionDB  
> **Audience:** Developers and Module Authors  
> **Location:** `/docs/dev/MANUAL.md`
>
> This index aggregates the current draft specifications for the AavionDB core and its ecosystem.
> Detailed documents are stored in dedicated partials. Keep [`CHANGELOG.md`](../../CHANGELOG.md) and
> [`.codex/NOTES.md`](../../.codex/NOTES.md) in sync with structural changes. A high-level overview lives in
> [`.codex/AGENTS.md`](../../.codex/AGENTS.md).

---

## 📘 Table of Contents
1. [Core Architecture](#-core-architecture)  
2. [File Structure](#-file-structure)  
3. [Bootstrap Process](#-bootstrap-process)  
4. [Brains & Storage Engine](#-brains--storage-engine)  
5. [Modules & Autoloading](#-modules--autoloading)  
6. [Logging & Diagnostics](#-logging--diagnostics)  
7. [Agents & Command Registry](#-agents--command-registry)  
8. [REST API Layer](#-rest-api-layer)  
9. [UI & Web Console](#-ui--web-console)  
10. [Entry Points](#-entry-points)  
11. [Authentication & API Keys](#-authentication--api-keys)  
12. [Versioning & Commit Hashes](#-versioning--commit-hashes)  
13. [Hooks, Events & Listeners](#-hooks-events--listeners)  
14. [Extending AavionDB](#-extending-aaviondb)  
15. [Security & Permissions](#-security--permissions)  
16. [Appendix](#-appendix)

---

## 🧠 Core Architecture
- [`docs/dev/core-architecture.md`](./partials/core-architecture.md) *(DRAFT)* – runtime blueprint, service layout.

## 🗂️ File Structure
- [`docs/dev/partials/file-structure.md`](./partials/file-structure.md) *(DRAFT)*

## ⚙️ Bootstrap Process
- [`docs/dev/partials/bootstrap.md`](./partials/bootstrap.md) *(DRAFT)*

## 🧩 Brains & Storage Engine
- [`docs/dev/partials/brains-and-storage.md`](./partials/brains-and-storage.md) *(DRAFT)*

## 🧱 Modules & Autoloading
- [`docs/dev/partials/modules.md`](./partials/modules.md) *(DRAFT)*

## 📝 Logging & Diagnostics
- [`docs/dev/partials/logging-and-diagnostics.md`](./partials/logging-and-diagnostics.md) *(DRAFT)*

## 🧠 Agents & Command Registry
- [`docs/dev/partials/agents-and-command-registry.md`](./partials/agents-and-command-registry.md) *(DRAFT)*

## 🌐 REST API Layer
- [`docs/dev/partials/rest-api.md`](./partials/rest-api.md) *(DRAFT)*

## 🎨 UI & Web Console
- [`docs/dev/partials/ui-console.md`](./partials/ui-console.md) *(DRAFT)*

## 🛠️ Entry Points
- [`docs/dev/partials/entry-points.md`](./partials/entry-points.md) *(DRAFT)*

## 🔐 Authentication & API Keys
- [`docs/dev/partials/authentication.md`](./partials/authentication.md) *(DRAFT)*

## 🧬 Versioning & Commit Hashes
- [`docs/dev/partials/versioning.md`](./partials/versioning.md) *(DRAFT)*

## 🪝 Hooks, Events & Listeners
- [`docs/dev/partials/events-and-hooks.md`](./partials/events-and-hooks.md) *(DRAFT)*

## 🧩 Extending AavionDB
- [`docs/dev/partials/extending.md`](./partials/extending.md) *(DRAFT)*

## 🔒 Security & Permissions
- [`docs/dev/partials/security.md`](./partials/security.md) *(DRAFT – outlines pending)*

## 🧭 Planned Features & Future Improvements

> This section collects conceptual and architectural goals that are intended for future releases of **AavionDB** and its ecosystem.  
> These items are not yet implemented but serve as design reminders for Codex and contributors.

- **Decouple UI Interface** → Migrate the integrated management UI into a standalone project: **AavionStudio**, a Vite-powered web application using TailwindCSS, PostCSS, Alpine.js, and Tabler Icons.  
- **Add Fieldset Linting Module** → Introduce a `SchemaAgent` that hooks into `save project-slug:schema` commands and validates payloads against JSON Schemas stored as entities inside the userspace project `fieldsets`. 
- **Schema Versioning & Compatibility Layer** → Add support for versioned schemas (e.g., `schema@19`) with backward compatibility validation between revisions.  
- **Consistent Version Handling** → Change entity version handling to move from :version to @version.  
- **Extended Diagnostic Dashboard** → Enhance the built-in diagnostics with dependency visualization, memory footprint tracking, and event throughput statistics.  
- **Testing Module Integration** → Provide a dedicated Testing Agent that coordinates `phpunit` runs, aggregates logs, and produces comprehensive QA reports.  
- **Live Event Monitor** → Add real-time event tracing within AavionStudio using a WebSocket bridge.  
- **WebSocket Bridge Agent** → Implement push-based data synchronization between AavionDB and connected Studio clients.  
- **Enhanced REST API Layer** → Extend API with batched transactions, asynchronous exports, and granular action scopes.  
- **Grav Plugin Bridge** → Build a plugin integration to expose AavionDB data as virtual pages and blueprints directly inside Grav CMS.  
- **Schema-Aware Exports** → Enable exports that respect fieldset definitions for cleaner, LLM-optimized JSON output.  
- **Access Control Extensions** → Expand the Auth Agent with scoped API keys, command-level permissions, and optional role sets.  
- **UI Extensions System** → Allow Studio-level plugins to register custom dashboards, data visualizers, or tool panels via the UI Agent.  
- **Brain Integrity Utilities** → Add validation, compaction, and self-repair tools for Brain files.  
- **Performance Optimizations** → Introduce schema caching, delta-save operations, and faster lookup indexes for large datasets.  
- **LLM-optimized Exporter** → Implement advanced export profiles optimized for contextual AI ingestion and token-aware data segmentation.

## 📎 Appendix
- [`/.codex/AGENTS.md`](../../.codex/AGENTS.md) – quick overview & workflow guidance  
- [`CHANGELOG.md`](../../CHANGELOG.md) – release notes  
- [`.codex/NOTES.md`](../../.codex/NOTES.md) – development log  
- [`README.md`](../../README.md) – user-facing overview  
- `/LICENSE`
