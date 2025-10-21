# ExportAgent Module

> **Status:** Implemented – exports flattened `context-unified-v2` bundles with preset-aware selection.  
> **Last updated:** 2025-10-21

## Responsibilities
- Register the unified `export` command for CLI/REST/PHP clients.
- Resolve manual and preset-driven slices (projects, entities, versions) and render deterministic JSON bundles.
- Cooperate with `PresetAgent`, `PresetValidator`, and `FilterEngine` to evaluate filters, transforms, and layout templates.

## Command Behaviour
- `export <slug[,slug…]> [entity[,entity[@version|#hash]]] [description="..."] [usage="..."]`
  - Manual mode; selectors only allowed when targeting a single project.
  - `description` populates meta/guide text; `usage` overrides the guide usage (falls back to `description`).
- `export <slug> --preset=my-slice [--param.topic=timeline] [description=...] [usage=...]`
  - Preset mode; project discovery, entity selection, payload whitelists/blacklists, policies, and layout are defined by the preset.
  - `${param.*}` placeholders resolve from `--param.*` arguments.
- `export *` – whole-brain export (no selectors).  
- Presets cannot be combined with manual selectors; the preset fully defines the slice.

## Call Flow
1. `module.php` instantiates `ExportAgent` during module initialisation.
2. `registerParser()` normalises arguments (`project`, `--preset`, `--param.*`, selectors, `description`, `usage`).
3. `exportCommand()` delegates to `normaliseInput()` and `generateExport()`:
   - Loads preset/layout definitions (`BrainRepository::getPreset()`, `saveExportLayout()` ensures defaults).
   - Resolves project scopes via `resolveManualProjects()` or `resolveProjectsForPreset()`.
   - Uses `FilterEngine::selectEntities()` (with placeholder maps) to evaluate DSL filters; `passesFilters()` handles payload filters.
   - `buildProjectSlice()` + `buildEntityRecord()` compose flattened entity payloads, applying transform whitelists/blacklists and hierarchy metadata.
   - Aggregates stats/index data and renders the selected layout via `renderLayout()`.
4. The command returns the rendered payload directly; hashing and persistence are delegated to cache subsystems.

## Key Collaborators
- `AavionDB\Storage\BrainRepository` – project/entity metadata, preset/layout storage.
- `AavionDB\Core\Filters\FilterEngine` – shared DSL evaluator for entity/payload filters.
- `AavionDB\Modules\Preset\PresetAgent` – CLI management for presets (CRUD/import/export) and default bootstrap.
- `AavionDB\Modules\Preset\PresetValidator` – normalises preset definitions before persistence.
- `AavionDB\Core\Modules\ModuleContext` – exposes repository/logger/debug utilities.

## Response Layout (`context-unified-v2`)
```json
{
  "meta": {
    "layout": "context-unified-v2",
    "preset": "default",
    "generated_at": "2025-10-21T15:42:01Z",
    "scope": "project",
    "description": "Context bundle for story planning",
    "action": "export storyverse"
  },
  "guide": {
    "usage": "Load all entities and keep the latest notes handy.",
    "notes": ["Each entity is self-contained and references others via '@project.slug'."]
  },
  "policies": {
    "load": "Treat \"active_version\" as canonical unless selectors override.",
    "cache": { "ttl": 3600, "invalidate_on": ["hash", "commit"] },
    "references": { "include": true, "depth": 1 }
  },
  "index": {
    "projects": [{ "slug": "storyverse", "title": "Story Verse", "entity_count": 2 }],
    "entities": [{ "uid": "storyverse.hero", "project": "storyverse", "slug": "hero" }]
  },
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
      "payload": { "name": "Aria", "role": "Pilot" },
      "payload_versions": [
        {
          "version": "3",
          "status": "active",
          "hash": "b9d2…",
          "commit": "b9d2…",
          "committed_at": "2025-10-19T09:12:34Z",
          "payload": { "name": "Aria", "role": "Pilot" }
        }
      ]
    }
  ],
  "stats": { "projects": 1, "entities": 1, "versions": 1 }
}
```

### Field Notes
- `meta.layout` ties the payload to an export layout stored alongside presets in the system brain.
- `guide`, `policies`, `index`, and `stats` provide lightweight navigation hints for downstream consumers (Studio UI, cache warmers, LLM prompts).
- Entities are flattened – parent/child links use canonical `project.slug` identifiers.
- `payload_versions[]` always contains full payloads plus commit metadata for each selected revision.
- Additional layouts can be registered via `BrainRepository::saveExportLayout()` and referenced from presets.

## Example Integrations

### CLI
```bash
php cli.php "export aurora --preset=scene-slice --param.scene=intro"
```

### REST
```bash
curl -H "Authorization: Bearer <token>" \
  "https://example.test/api.php?action=export&project=aurora&preset=scene-slice&param.scene=intro"
```

### PHP
```php
$response = AavionDB::run('export', [
    'project' => 'aurora',
    'preset' => 'scene-slice',
    'param.scene' => 'intro'
]);
```
