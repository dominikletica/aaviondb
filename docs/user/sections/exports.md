# Exports & Presets

Exports provide deterministic bundles optimised for large-language-model consumption. They flatten entities, embed navigation hints, and include cache policies so downstream tools (e.g. ChatGPT, internal assistants) can reason about the payload quickly.

---

## Quick Start

```bash
php cli.php "export storyverse"
```

- Returns the active version of every entity in the `storyverse` project.
- Uses the default preset `context-unified` (JSON format) unless you override it.
- REST: `GET /api.php?action=export&project=storyverse`
- PHP: `$response = AavionDB::run('export', ['project' => 'storyverse']);`

### Filtering Entities

- `export storyverse hero,villain` – limit the export to specific entities.
- `export storyverse hero@12` – export a fixed revision.
- `export storyverse hero@12,villain@current` – mix fixed versions with the current active one.
- `export *` – export every project in the active brain (selectors disabled in this mode).

### Useful Flags

| Flag | Description |
|------|-------------|
| `--preset=<slug>` | Use a stored preset (default: `context-unified`). |
| `--format=json|jsonl|markdown|text` | Override the output format (defaults to preset/system setting). |
| `--path=/abs/or/relative` | Save the export to disk. Directories derive a timestamped file automatically. |
| `--save=0|1` | Enable/disable persistence (defaults to system setting). |
| `--response=0|1` | Include the rendered content in the API/CLI response. |
| `--nest_children=0|1` | Preserve hierarchy order (children follow parents in the output). |
| `--param.foo=value` / `--var.foo=value` | Provide preset variables (`${param.foo}` placeholders). |
| `description="…"` / `usage="…"` | Override meta guidance in the export bundle. |

System defaults (`export.response`, `export.save`, `export.format`, `export.nest_children`, `export.path`) live in the system brain and can be adjusted with `config set --system`.

---

## Working with Presets

Presets live in the system brain. They define project selection, entity filters, transforms, destination defaults, and templates.

```bash
php cli.php "preset list"
php cli.php "preset show context-unified"
php cli.php "preset copy context-unified focus-scene"
php cli.php "preset vars focus-scene"
```

### Executing a Preset

```bash
php cli.php "export storyverse --preset=focus-scene --param.scene=intro"
```

Shortcodes inside entities see the same parameters; no additional wiring is required.

### Inspecting Variables (`preset vars`)

```bash
php cli.php "preset vars focus-scene"
```

- Lists placeholders (`project`, `param.scene`, …), types, defaults, and whether they are required.
- Supply values via `--param.<name>=value` (alias `--var.<name>=value`) or embed them in the REST/PHP payload under `params`.
- The response also reports preset options (e.g. `missing_payload`) so you can anticipate warning behaviour.

### Bundled Presets

| Slug | Format | Description |
|------|--------|-------------|
| `context-unified` | JSON | Meta + guide + policies + index + project/entity payloads (default). |
| `context-jsonl` | JSON Lines | Header line + one JSON object per entity (newline separated). |
| `context-markdown-unified` | Markdown | Rich sections for projects/entities with fenced JSON payloads. |
| `context-markdown-slim` | Markdown | Lightweight bullet list of entity display names. |
| `context-markdown-plain` | Markdown | Narrative headings without inline JSON (uses `${entity.heading_prefix}` / `${entity.payload_plain}`). |
| `context-text-plain` | Text | Plain key/value breakdown (uses `${entity.indent}` / `${entity.payload_plain}`). |

All bundled presets are read-only. Kopiere sie vor Anpassungen: `preset copy context-unified my-export`. Falls du dennoch `preset update context-unified ...` ausführst, legt die CLI automatisch eine Kopie `context-unified-v2` (bzw. mit weiter hochgezählter Version) an und speichert dort deine Änderungen.

`preset vars <slug>` reports placeholders, parameter metadata, and the configured missing-field policy so UI clients can prompt for the correct inputs.

### Creating / Updating Presets

1. Copy a baseline: `preset copy context-unified scene-kit`.
2. Edit the definition via `preset update` (inline JSON) or export → edit → import.
3. Confirm variables with `preset vars scene-kit`.
4. Run: `export myproject --preset=scene-kit --param.scene=intro`.

Preset schema (`preset show <slug>`) contains four top-level blocks:

- `meta` – title/description/usage/tags + read-only flags.
- `settings` – destination defaults, variables, transforms, policies, and `options` (e.g. `missing_payload: "empty"|"skip"`).
- `selection` – project/Entity/payload filters + reference depth.
- `templates` – strings for `root`, `project`, `entity` with `${placeholder}` markers.

---

## Inline Context & Shortcodes

- Entity payloads that contain `[ref …]` or `[query …]` shortcodes are resolved during export. The JSON keeps the original marker and appends the resolved output, e.g. `"summary": "[ref …]Resolved text[/ref]"`.
- Resolver templates expose `{record.url}`, `{record.url_relative}`, `{record.url_absolute}` for hierarchy-aware links. Relative URLs omit the project slug so you can prepend your own base path.
- When re-importing an export, the engine strips the resolved suffix so stored entities stay canonical.

---

## Example Output (`context-unified`)

```json
{
  "meta": {
    "title": "Context Unified",
    "description": "Context bundle for story planning",
    "preset_description": "Unified JSON export optimised for deterministic LLM context ingestion.",
    "usage": "Load all entities and keep the latest notes handy.",
    "preset_usage": "Exports the current project with canonical entities for LLM ingestion.",
    "preset": "context-unified",
    "format": "json",
    "generated_at": "2025-10-22T09:30:12Z",
    "scope": "project",
    "action": "export storyverse"
  },
  "guide": {
    "usage": "Load all entities and keep the latest notes handy.",
    "notes": [
      "Unified export ensures deterministic ordering and hash tracking."
    ]
  },
  "policies": {
    "references": {"include": true, "depth": 1},
    "cache": {"ttl": 3600, "invalidate_on": ["hash", "commit"]}
  },
  "index": {
    "projects": [{"slug": "storyverse", "title": "Story Verse", "entity_count": 2, "version_count": 4}],
    "entities": [{"uid": "storyverse.hero", "project": "storyverse", "slug": "hero"}]
  },
  "projects": [{
    "slug": "storyverse",
    "title": "Story Verse",
    "description": "Shared universe",
    "status": "active",
    "created_at": "2024-11-01T18:22:00Z",
    "updated_at": "2025-10-21T07:10:00Z",
    "entity_count": 2,
    "version_count": 4,
    "entities": [
      {
        "uid": "storyverse.hero",
        "project": "storyverse",
        "slug": "hero",
        "version": "3",
        "commit": "b9d2…",
        "active": true,
        "parent": null,
        "children": [],
        "refs": [],
        "payload": {"name": "Aria", "role": "Pilot"},
        "payload_versions": [
          {
            "version": "3",
            "status": "active",
            "hash": "b9d2…",
            "commit": "b9d2…",
            "committed_at": "2025-10-19T09:12:34Z",
            "payload": {"name": "Aria", "role": "Pilot"}
          }
        ],
        "display_name": "Aria",
        "payload_pretty": "{\n    \"name\": \"Aria\",\n    \"role\": \"Pilot\"\n}"
      }
    ]
  }],
  "entities": [{"uid": "storyverse.hero", "project": "storyverse", "slug": "hero"}],
  "stats": {"projects": 1, "entities": 1, "versions": 1},
  "warnings": []
}
```

### Nested Children & Versions

- `--nest_children=1` (or `settings.destination.nest_children=true`) keeps parent/child order intact. Markdown/Text presets expose `${entity.heading_prefix}` and `${entity.indent}` so children can be rendered beneath their parents; JSON/JSONL emit children immediately after parents.
- To export multiple revisions of the same entity, list them explicitly (`export storyverse hero@12,hero@13`). The first version becomes the canonical payload, while additional revisions remain inside `payload_versions` for comparison.

### Missing Payload Fields & Warnings

- Presets can reference nested payload fields via `${entity.payload.some.field}`. When a field is missing:
  - `settings.options.missing_payload = "empty"` (default) emits blanks (`null` in JSON/JSONL) and records a warning.
  - `settings.options.missing_payload = "skip"` drops the entity altogether and records a warning.
- Warnings are returned in both `data.warnings` and `meta.warnings`. Example fragment:

```json
{
  "warnings": [
    {
      "type": "missing_payload_field",
      "project": "storyverse",
      "entity": "villain",
      "placeholder": "entity.payload.profile.backstory",
      "policy": "empty",
      "message": "Placeholder \"entity.payload.profile.backstory\" missing in entity \"villain\" (storyverse); policy=empty."
    }
  ]
}
```

---

## Troubleshooting

- **Preset not found** – `Preset "<slug>" not found.`
- **Manual selectors with multiple projects** – `Entity selectors are only supported when exporting a single project.`
- **Missing preset parameter** – `Preset parameter "scene" is required.`
- **Missing payload field** – check the `warnings` array; adjust your preset or set `settings.options.missing_payload` to `skip`/`empty` as needed.
- **File cannot be written** – check the destination path/permissions or use `--save=0`.
