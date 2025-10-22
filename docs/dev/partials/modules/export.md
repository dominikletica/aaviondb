# ExportAgent Module

> **Status:** Implemented – renders preset-driven exports across JSON/JSONL/Markdown/Text.  
> **Last updated:** 2025-10-22

## Responsibilities
- Register the unified `export` command for CLI/REST/PHP clients.
- Merge destination defaults (system config → preset → CLI overrides) and render exports using preset templates.
- Resolve project/entity slices in manual mode (selectors) or preset mode (FilterEngine DSL, payload filters, transforms).
- Expand resolver shortcodes (`[ref]`, `[query]`) before rendering so each export contains both the instruction and the resolved payload.

## Command Behaviour
- `export <slug[,slug…]> [entity[,entity[@version|#hash]]] [--path=…] [--format=json|jsonl|markdown|text] [--save=0|1] [--response=0|1] [--nest_children=0|1] [description="…"] [usage="…"]`
  - Manual mode; selectors are only allowed when exporting a single project.
  - `description` populates export meta + guide; `usage` overrides the LLM usage hint (defaults to `description`).
- `export <slug> --preset=my-slice [--param.topic=timeline] [--var.timeline=3] [--path=…] [--format=markdown] [--save=0|1] [--response=0|1] [description=…] [usage=…]`
  - Preset mode; project discovery, filters, transforms, policies, templates are all defined by the preset.
  - `${param.*}` placeholders resolve from `--param.`/`--var.` arguments (and the optional JSON payload `params`). Resolver shortcodes inherit the same parameter bag.
- `export *` – Whole-brain export (no selectors) using the requested preset or the default `context-unified`.
- Presets cannot be combined with explicit selectors; copy the preset and modify its selection rules when a custom slice is required.

## Call Flow
1. `registerParser()` normalises positional arguments (`project`, selectors) and named flags (`--preset`, `--param.*`, `--path`, `--format`, `description`, `usage`).
2. `ensureConfigDefaults()` seeds system-level config keys (`export.response`, `export.save`, `export.format`, `export.nest_children`).
3. `generateExport()`
   - Loads the selected preset (`BrainRepository::getPreset()`), merges destination defaults (system config → preset → CLI overrides), and resolves variables via `resolveVariables()`.
   - Resolves projects (`resolveManualProjects()`/`resolveProjectsForPreset()`), evaluates entity/payload filters through `FilterEngine`, and builds flattened slices via `buildProjectSlice()` / `buildEntityRecord()`.
   - Resolver shortcodes are expanded with `ResolverEngine` (same parameters passed to presets).
   - Aggregates stats/index/meta and renders template strings with `renderExportContent()` (JSON ↔ `json_decode/json_encode`, JSONL, Markdown/Text).
   - Optionally persists the export to disk (`persistExport()`) when `save=1`.
4. Command response includes the rendered content (if `response=1`), metadata (preset, scope, counts, saved path), and summary lists of projects/entities.

## Destination Defaults
- System brain config (`config set --system export.response 0/1`, `export.save`, `export.format`, `export.nest_children`, `export.path`) holds fleet-wide defaults.
- Preset `settings.destination` overrides these defaults per preset.
- CLI/REST flags (`--format`, `--path`, `--save`, `--response`) are applied last.
- Per preset missing-field policy is controlled via `settings.options.missing_payload` (`empty` → emit blanks/nulls, `skip` → drop the entity and emit warnings).

## Default Presets
- `context-unified` (default): JSON bundle with meta/guide/policies/index/projects/entities.
- `context-jsonl`: header line + JSON object per entity (newline separated).
- `context-markdown-unified`: rich Markdown sections with fenced JSON payloads.
- `context-markdown-slim`: lightweight Markdown bullet list.
- `context-markdown-plain`: human-readable markdown headings without embedded JSON (leverages `${entity.heading_prefix}` / `${entity.payload_plain}`).
- `context-text-plain`: plain text key/value breakdowns driven by `${entity.indent}` and `${entity.payload_plain}`.

Use `preset vars <slug>` to inspect variables, placeholders, and the configured missing-field policy for any preset.

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
  "entities": [
    {
      "uid": "storyverse.hero",
      "project": "storyverse",
      "slug": "hero"
    }
  ],
  "stats": {"projects": 1, "entities": 1, "versions": 1},
  "warnings": []
}
```

## Nesting & Multi-Version Slices

- `--nest_children=1` (or `settings.destination.nest_children=true`) preserves project hierarchy order when rendering. Markdown/Text presets can use `${entity.heading_prefix}` and `${entity.indent}` to visually nest child entities, while JSON/JSONL emit children immediately after their parent entries.
- Version selectors (e.g. `export aurora hero@12,hero@13`) populate `payload_versions` deterministically. The first requested version drives the canonical payload, additional versions remain in the array for timeline comparison.

## Missing Payload Fields

- Templates may reference deep payload paths via `${entity.payload.some.field}`. When a field is absent:
  - `settings.options.missing_payload = "empty"` (default) outputs blanks (or `null` in JSON/JSONL) and records a warning.
  - `settings.options.missing_payload = "skip"` removes the entity from the export and records a warning.
- Warnings are returned in both `data.warnings` (CLI/REST payload) and `meta.warnings`, enabling consumers to surface issues to end users.

## Integration Snippets

### CLI
```bash
php cli.php "export aurora --preset=scene-slice --param.scene=intro --format=jsonl --path=user/exports"
```

### REST
```bash
curl -H "Authorization: Bearer <token>" \
  "https://example.test/api.php?action=export&project=aurora&preset=scene-slice&param.scene=intro&format=markdown"
```

### PHP
```php
$response = AavionDB::run('export', [
    'project' => 'aurora',
    'preset'  => 'scene-slice',
    'param.scene' => 'intro',
    'format'  => 'text',
    'save'    => 0,
]);
```

## Error Handling
- Missing preset → `Preset "<slug>" not found.`
- Manual selectors with multiple projects → `Entity selectors are only supported when exporting a single project.`
- Invalid JSON rendered from templates → surfaces `InvalidArgumentException` with the underlying `JsonException` message.
- Missing payload fields honour the preset policy (`empty` vs `skip`) and are reported in `warnings`.
- File write failure → `Unable to write export file "...".`
