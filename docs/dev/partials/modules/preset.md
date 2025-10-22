# PresetAgent Module

> **Status:** Implemented – manages export presets in the system brain (no separate layout store).  
> **Last updated:** 2025-10-22

## Responsibilities
- Provide CLI/REST CRUD for presets (`preset list/show/create/update/delete/copy/import/export/vars`).
- Persist preset definitions (meta, settings, selection, templates) in `export.presets[slug]` via `BrainRepository`.
- Seed the bundled presets during bootstrap: `context-unified` (default), `context-jsonl`, `context-markdown-unified`, `context-markdown-slim`, `context-markdown-plain`, `context-text-plain` – all marked `read_only` + `immutable`.
- Validate payloads with `PresetValidator` (destination settings, variables, selection/filter DSL, templates) before writing.
- Surface placeholder metadata for Studio/UI tooling through `preset vars` (type, default, required, description).

## Call Flow
1. `module.php` instantiates `PresetAgent` when system modules are loaded.
2. `registerParser()` maps `preset ...` tokens to proper handlers and captures inline payloads (`--payload`, request body) or file arguments (`--file=path`).
3. `ensureDefaults()` seeds the bundled presets if they are missing (flags them as `read_only`/`immutable`).
4. Command handlers (`preset list/show/...`) work against the system brain namespace through `BrainRepository` helpers.

## Storage Schema
Each preset lives under `export.presets[slug]` with the following shape:

```jsonc
{
  "meta": {
    "title": "Context Unified",
    "description": "Description shown to users",
    "usage": "LLM usage hint",
    "tags": ["default"],
    "read_only": true,
    "immutable": true,
    "created_at": "...",
    "updated_at": "..."
  },
  "settings": {
    "destination": {
      "path": null,
      "response": true,
      "save": true,
      "format": "json",
      "nest_children": false
    },
    "variables": {
      "foo": {"type": "text", "required": false, "default": null, "description": "..."}
    },
    "transform": {"whitelist": [], "blacklist": [], "post": []},
    "policies": {"references": {"include": true, "depth": 1}, "cache": {"ttl": 3600, "invalidate_on": ["hash", "commit"]}},
    "options": {"missing_payload": "empty"}
  },
  "selection": {
    "projects": ["${project}"],
    "entities": [{"type": "payload_contains", "config": {"field": "payload.flags", "value": "${param.flag}"}}],
    "payload_filters": [],
    "include_references": {"enabled": true, "depth": 1, "modes": ["primary"]}
  },
  "templates": {
    "root": "{\\n  \"meta\": ${meta}, ... }",
    "project": "{\\n  \"slug\": ${project.slug}, ... }",
    "entity": "{\\n  \"uid\": ${entity.uid}, ... }"
  }
}
```

There is no longer a separate `export.layouts` bucket; templates live inside the preset.

## Validation Highlights (`PresetValidator`)
- Normalises meta (`title`, `description`, `usage`, `tags`, `read_only`, `immutable`).
- Validates `settings.destination` (path/response/save/format/nest_children), `settings.variables` (type/default/required/description), `settings.transform` and `settings.policies`.
- Ensures `selection.projects` placeholders (`${project}`, `${param.foo}`, literal slugs), entity/payload filter DSL (`type` + `config`), and reference options are well formed.
- Requires `templates.root` and `templates.entity` to be non-empty strings; `templates.project` may be empty when the root template handles everything.
- Normalises `settings.options.missing_payload` (`empty` → emit blank/null, `skip` → drop entity and emit warning).
- Normalises parameter types (`text`, `int`, `number`, `float`, `bool`, `array`, `object`, `comma_list`, `json`).
- Throws `InvalidArgumentException` with deterministic messages when definitions are malformed.

## Example Commands
```bash
# List presets
php cli.php "preset list"

# Create a preset with custom filters and templates
php cli.php "preset create focus-scene" --payload='{
  "meta": {"title": "Scene Focus", "description": "Export scenes by flag", "usage": "Hand to the LLM as context"},
  "settings": {
    "destination": {"format": "json"},
    "variables": {"scene": {"type": "text", "required": true}},
    "transform": {"whitelist": ["summary", "notes"]}
  },
  "selection": {
    "projects": ["${project}"],
    "entities": [{"type": "payload_contains", "config": {"field": "payload.scene", "value": "${param.scene}"}}]
  },
  "templates": {
    "root": "{\\n  \"meta\": ${meta},\\n  \"projects\": ${projects},\\n  \"entities\": ${entities}\n}",
    "project": "{\\n  \"slug\": ${project.slug},\\n  \"entities\": ${entities}\n}",
    "entity": "{\\n  \"uid\": ${entity.uid},\\n  \"payload\": ${entity.payload}\n}"
  }
}'

# Export / import
php cli.php "preset export focus-scene --file=presets/focus-scene.json"
php cli.php "preset import focus-scene presets/focus-scene.json" --force

# Clone bundled preset to customise
php cli.php "preset copy context-unified my-unified"

# Inspect placeholders for Studio forms
php cli.php "preset vars focus-scene"
```

- `context-unified` (default for `export`) – JSON bundle with meta/guide/policies/index/projects/entities.
- `context-jsonl` – Emits one JSON object per line (`JSONL`) plus a header line with metadata.
- `context-markdown-unified` – Rich Markdown with sections per project/entity and fenced JSON payloads.
- `context-markdown-slim` – Lightweight Markdown bullet list of entities.
- `context-markdown-plain` – Markdown headings rendered without inline JSON (uses `${entity.heading_prefix}`, `${entity.payload_plain}`).
- `context-text-plain` – Plain text key/value breakdown tailored for prompt injection.

All bundled presets are stored in code (`PresetAgent::buildContext*Preset()`) and written to the system brain on startup. They are marked `read_only` and `immutable`; copy them before tweaking (`preset copy context-unified my-export`).
If you attempt to update a read-only preset, the agent automatically saves the changes under `<slug>-vN` (starting with `-v2`) so the protected default remains untouched.

## Interaction with ExportAgent
- `ExportAgent` loads presets via `BrainRepository::getPreset()` and reads `settings.destination`, `settings.variables`, `settings.options`, and `selection` filters.
- Destination defaults (`export.response`, `export.save`, `export.format`, `export.nest_children`) are stored in the system brain (`config set --system`). CLI overrides (`--format`, `--path`, `--save`, `--response`) merge on top.
- FilterEngine receives placeholder maps derived from preset variables and `${project}` to evaluate entity selectors/payload filters.
- ResolverEngine reuses the same parameter bag, so `${param.*}` placeholders are available inside `[ref]` / `[query]` shortcodes while rendering.
- Templates are rendered directly by `ExportAgent` (no separate layout lookup) and the final content is emitted as JSON/JSONL/Markdown/Text depending on the destination format. Payload placeholders missing from the source can be handled via `settings.options.missing_payload` (`empty` vs `skip`), with warnings surfaced in the export response.

## Error Handling
- Missing preset → `Preset "<slug>" not found.`
- Attempting to modify/delete a protected preset → `Preset "<slug>" is read-only.`
- Validation failure → descriptive error (e.g. `Preset parameter "scene" is required.`).
