# PresetAgent Module

> **Status:** Implemented – manages export presets and layouts in the system brain.  
> **Last updated:** 2025-10-21

## Responsibilities
- Provide CLI/REST CRUD for export presets (`preset list/show/create/update/delete/copy/import/export`).
- Persist presets and layout templates in the system brain namespace (`BrainRepository::savePreset()`, `saveExportLayout()`).
- Ensure the default preset (`default`) and layout (`context-unified-v2`) exist during bootstrap.
- Validate preset definitions (selection DSL, transforms, policies, placeholders) via `PresetValidator` before persisting.

## Call Flow
1. `module.php` instantiates `PresetAgent` during system module bootstrap.
2. `registerParser()` maps `preset ...` subcommands to dedicated handlers, capturing payload JSON (`--payload` or request body) and file arguments (`--file=` / path tokens).
3. `register()` wires command handlers:
   - `preset list` – enumerates presets with descriptions, layout id, timestamps.
   - `preset show <slug>` – dumps the stored definition (including meta/policies/selection).
   - `preset create|update <slug>` – accepts JSON payloads, validates via `PresetValidator::validate()`, and writes to the system brain.
   - `preset delete <slug>` – removes mutable presets; default preset is immutable.
   - `preset copy <source> <target> [--force]` – clones definitions (scrubs read-only flags).
- `preset import <slug> <path> [--force]` / `preset export <slug> [--file=path]` – bridge to disk files when needed.
- `preset vars <slug>` – expose placeholders, types, defaults, and required flags for Studio/UI auto forms.
4. `ensureDefaults()` runs on initialisation to seed the default preset + layout if missing.

## Storage Schema
- System brain `export.presets[slug]` – JSON definition with `meta`, `selection`, `transform`, `policies`, `placeholders`, `params`.
- System brain `export.layouts[id]` – layout template JSON (currently `context-unified-v2`).
- Metadata for each preset: `meta.slug`, `meta.description`, `meta.usage`, `meta.layout`, `meta.read_only`, `meta.created_at`, `meta.updated_at`.

## Validation Highlights (`PresetValidator`)
- Normalises meta fields (description/usage/layout/tags/read_only).
- Ensures project selectors, entity filter DSL, payload filters, and transforms are arrays of well-formed objects (`type` + `config`).
- Coerces policy defaults (`references.include/depth`, `cache.ttl`, `cache.invalidate_on`).
- Normalises placeholders and params (`required`, `default`, `description`).
- Supports explicit parameter types (`text`, `int`, `number`, `float`, `bool`, `array`, `object`, `comma_list`) which are surfaced via `preset vars`.
- Throws `InvalidArgumentException` with deterministic messages on malformed input (surfaced by CLI/REST).

## Example Commands
```bash
# List presets
php cli.php "preset list"

# Create a preset from JSON payload
php cli.php "preset create focus-scene" \
  --payload='{
    "meta": {"description": "Scene focus", "layout": "context-unified-v2"},
    "selection": {
      "projects": ["${project}"],
      "entities": [
        {"type": "payload_contains", "config": {"field": "payload.scene", "value": "${param.scene}"}}
      ]
    },
    "transform": {"whitelist": ["summary", "notes"]},
    "policies": {"references": {"include": true, "depth": 1}},
    "params": {"scene": {"required": true}}
  }'

# Update using file import
php cli.php "preset import focus-scene presets/focus-scene.json" --force

# Export to disk
php cli.php "preset export focus-scene --file=presets/focus-scene.json"

# Copy and tweak
php cli.php "preset copy focus-scene spotlight"

# Inspect variables/placeholders
php cli.php "preset vars focus-scene"

# Example vars response (excerpt)
{
  "data": {
    "placeholders": ["project", "param.scene"],
    "placeholder_details": [
      {"placeholder": "project", "type": "text", "source": "project"},
      {"placeholder": "param.scene", "type": "text", "source": "param", "name": "scene", "required": true}
    ],
    "params": {
      "scene": {"required": true, "type": "text", "default": null, "description": null}
    }
  }
}
```

## Default Preset & Layout
- The seeded `default` preset is part of the bootstrap process and is marked `read_only`/`immutable`. Any write attempt (`preset update/delete default`) returns a deterministic error.  
- To customise the default behaviour, copy it first (`preset copy default my-slice`) or adjust the PHP source:
  - Default preset definition lives in `system/modules/preset/classes/PresetAgent::defaultPresetDefinition()`.
  - Default layout template lives in `PresetAgent::defaultLayoutDefinition()`.
- Whenever these helpers change, run `preset export default` to review the generated JSON and update documentation/examples.

## Interaction with ExportAgent
- ExportAgent queries presets through `BrainRepository::getPreset()`; presets flagged as `read_only`/`immutable` guard system defaults.
- Layout ids referenced by presets (`meta.layout`) are fetched via `getExportLayout()` and rendered by `renderLayout()`.
- FilterEngine receives placeholder maps derived from preset parameters (`${param.*}` / `${var.*}`) and the current project slug (`${project}`).

## Error Handling
- Missing preset → `Preset "<slug>" not found.`
- Attempting to modify/delete the default preset → `Preset "default" is read-only.`
- Validation errors bubble up with the first failing field (e.g. `Preset parameter "timeline" is required.`).
