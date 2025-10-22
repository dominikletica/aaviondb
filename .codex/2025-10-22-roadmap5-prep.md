# Roadmap #5 Preparatory Notes (2025-10-22)

## Objective
Unify the export workflow so that presets encapsulate **layout**, **destination settings**, **selection rules**, and **LLM guidance** without relying on separate layout files. Deliver default presets for JSON, JSONL, and Markdown while keeping CLI selectors (`export project entity@version`) fully compatible.

## Target Preset Format
```json
{
  "meta":         {"slug", "title", "description", "usage", "tags"},
  "settings":     {
    "destination": {"path", "response", "save", "format", "nest_children"},
    "variables":   {"name": {"type", "required", "default", "description"}},
    "transform":   {"whitelist", "blacklist", "post"}
  },
  "selection":    {"projects", "entities", "payload_filters", "include_references"},
  "templates":    {"root", "project", "entity"}
}
```

- Templates are plain strings with `${...}` placeholders.
- CLI overrides (`--path`, `--response`, `--save`, `--format`, `--nest_children`) merge with preset `settings.destination`.
- Variables map directly to `--param.*` / `--var.*` arguments.

## Exporter Requirements
1. Load preset (default to `context-unified` when none is provided).
2. Resolve variables (preset defaults + CLI overrides) and validate required params.
3. Build project/entity context data (meta, guide, policies, index, stats).
4. Render templates per format:
   - **json**  → render → `json_decode` → `json_encode(JSON_PRETTY_PRINT)`.
   - **jsonl** → one line per entity (`projects` template usually empty).
   - **markdown** → headings per project/entity.
5. Support multiple projects/entities and CLI selectors when preset does not lock them down.
6. Save artifacts to `${destination.path}` when `save=true`, and return content in response when `response=true`.

## Default Presets To Seed
- `context-unified` (JSON bundle, default fallback)
- `context-jsonl`
- `context-markdown-unified`
- `context-markdown-slim`

## Implementation To-Do
- Refactor `PresetValidator` and `PresetAgent` to new schema.
- Refactor `ExportAgent` to consume new preset format (variables, templates, destinations).
- Move `export.path`, `export.response`, `export.save` defaults from config-file into the system brain and honour CLI overrides.
- Provide helper utilities for template rendering (project/entity aggregation, JSONL helpers, markdown headings).
- Update docs (`docs/user/sections/exports.md`, developer manual, `commands.md`, `classmap.md`, `.codex/NOTES.md`).
