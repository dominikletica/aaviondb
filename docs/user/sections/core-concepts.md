# Core Concepts

AavionDB keeps your data organised with a handful of simple building blocks. Understanding them helps you plan your projects and exports.

---

## Brains

- A **brain** is the top-level container that groups projects.
- The system brain stores configuration and scheduler tasks. User brains hold your project data.
- Switch brains when you want to separate environments (e.g., `default`, `production`, `staging`).

Useful commands:

- `brains` – list available brains.
- `brain switch <slug>` – change the active brain.
- `brain backup [slug]` – create a backup copy.
- `brain backups [slug]` – list existing backups for a brain.
- `brain backup prune <slug|*> [--keep=10]` – prune backups via retention rules.
- `brain restore <backup> [target]` – restore a snapshot (optionally into a new brain).

---

## Projects

- A **project** groups related entities, such as worldbuilding notes or product catalogues.
- Each project can have a title and description for LLM guidance or documentation.
- Projects support soft removal (archive) and permanent deletion.

See [Project Management](projects.md) for details.

---

## Entities

- An **entity** holds structured data inside a project.
- Entities are stored as JSON payloads and can reference optional schemas (called *fieldsets*).
- Saving an entity always creates a new version. Older versions remain available for rollback or comparison.

See [Entity Lifecycle](entities.md) to learn how to save, merge, and restore versions.

---

## Versions & Selectors

- Each save creates a sequential `@version` number and a unique `#hash` (SHA-256).
- Use selectors to target specific revisions:
  - `hero@12` – version number
  - `hero#abc123` – commit hash prefix
- Commands such as `show`, `delete`, `restore`, and `export` accept selectors.

---

## Fieldsets (Schemas)

- Fieldsets are JSON Schema documents stored via the SchemaAgent.
- Attach a schema to an entity with the `:fieldset` selector (e.g., `save project hero:character`).
- Partial updates merge data first, then validate against the schema to keep entities consistent.

More information: [Entity Lifecycle](entities.md#schema-validation) and [Security & Access](security.md#schema-management).

---

## Cache and Rate Limiting

- The cache stores computed results (like exports) to speed up repeated work.
- Rate limiting protects the REST API from abuse. Defaults can be changed in the system brain.

See [Performance & Cache](performance.md) and [Security & Access](security.md).

---

## Presets and Automation

- Presets define how exports select entities and transform payloads.
- The scheduler runs saved commands on a recurring basis (via CLI or `api.php?action=cron`).

Read [Exports & Presets](exports.md) and [Automation & Scheduler](automation.md) for workflows and examples.

---

Next stop: [Project Management](projects.md).
