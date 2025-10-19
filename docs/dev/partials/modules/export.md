# ExportAgent Module

> Status: Implemented – delivers baseline JSON export capabilities.

## Responsibilities
- Accept `export <project|*> [entity[,entity[@version|#hash]]]` commands.
- Generate JSON-ready payloads for entire brains, single projects, or scoped entities/versions.
- Respect access scopes enforced by `BrainRepository` and include payload metadata for downstream tooling.

## Command Behaviour
- `export <slug[,slug…]> [entities] [description="How to use this slice"] [usage="LLM guidance"]` – Exports one or more projects (comma-separated). Without selectors the slice includes only the active version for each entity; `usage` overrides the guide text (falls back to `description`).  
- `export <slug> entity1,entity2@3,entity3#commit` – Restricts the export to the listed entities and revisions (`@version`, `#commit`). Multiple selectors per entity (for example `outline@12,outline@13,outline@14`) are supported, but only when exporting a single project.  
- `export * [description="..."]` – Bundles every accessible project into a single response; selectors are not permitted in wildcard mode.  
- Optional `description` parameters populate `export_meta.description`, while `usage` (when provided) overrides the guide’s usage text (otherwise it falls back to the description).

### Response Shape (Project Slice)
```json
{
  "project": {
    "items": [
      {
        "slug": "storyworld",
        "title": "Storyworld Outline",
        "description": "High-level outline used as context for the session.",
        "status": "active",
        "created_at": "2025-08-01T09:00:00+00:00",
        "updated_at": "2025-10-19T08:45:12+00:00",
        "archived_at": null,
        "entity_count": 2,
        "version_count": 4,
        "entities": [
          {
            "slug": "hero",
            "status": "active",
            "created_at": "2025-10-01T08:00:00+00:00",
            "updated_at": "2025-10-15T10:32:00+00:00",
            "archived_at": null,
            "active_version": "7",
            "selectors": null,
            "versions": [
              {
                "version": "7",
                "status": "active",
                "hash": "8cf0b3298d5e2fb932d1d61e6864d9a8ce4154f5d7d7e46f87b3066b6d729a1d",
                "commit": "c1176dfd7067486fb4f588c879bee0a023649b83f75ce8a45a9ad94c8f8d3bfe",
                "committed_at": "2025-10-18T20:10:05+00:00",
                "payload": { "name": "Aerin Tal", "role": "Navigator" },
                "meta": { "tags": ["hero"], "editor": "letica" },
                "selectors": null
              }
            ]
          },
          {
            "slug": "outline",
            "status": "active",
            "created_at": "2025-09-29T11:20:00+00:00",
            "updated_at": "2025-10-19T07:10:45+00:00",
            "archived_at": null,
            "active_version": "14",
            "selectors": ["@12", "@13", "@14"],
            "versions": [
              {
                "version": "12",
                "status": "inactive",
                "hash": "cf30dd1f4be20184b07a4e9bae0cc47da48a6491f75b1df0b551289b6b6076d7",
                "commit": "11d3d4f98e5bf1a2b0c2cfa87f2106efb67cc0a0a7b75a114500e3b8325e8c44",
                "committed_at": "2025-10-12T09:20:00+00:00",
                "payload": { "summary": "Draft outline v12" },
                "meta": {},
                "selectors": ["@12"]
              },
              {
                "version": "13",
                "status": "inactive",
                "hash": "c48dc42ad0356d6adc6a8412d3d35d88bbde50f2c7c6f3d5bb8862c8d8f8260f",
                "commit": "f63d4b245b32ac1a84b0974ea5292f3a7a1ad3d0f5e16b5a8d21a2352fb84424",
                "committed_at": "2025-10-17T06:51:00+00:00",
                "payload": { "summary": "Draft outline v13" },
                "meta": {},
                "selectors": ["@13"]
              },
              {
                "version": "14",
                "status": "active",
                "hash": "b8aa2f8e0f747aa54c145fac653cf2b51c22d7d8cf9a5b97fef2be40b94eb053",
                "commit": "7e3f9a0616e644a9cda247eb8c02c42d5b6cb7fcb3dc8a289912f5248dc51940",
                "committed_at": "2025-10-19T07:10:45+00:00",
                "payload": { "summary": "Draft outline v14" },
                "meta": {},
                "selectors": ["@14"]
              }
            ]
          }
        ]
      }
    ],
    "count": 1
  },
  "export_meta": {
    "generated_at": "2025-10-19T09:05:00+00:00",
    "scope": "project",
    "action": "export storyworld outline@12,outline@13,outline@14",
    "counts": {
      "projects": 1,
      "entities": 3,
      "versions": 5
    },
    "description": "Sliced export that contains data to use as context-source (SoT) for the current session.",
    "hash": "2c9faa1c3c1d4f74e4dd2c3f7ed46701fd7a4fbcdf4b0c7657f61d3b8521440b"
  },
  "guide": {
    "usage": "Sliced export that contains data to use as context-source (SoT) for the current session.",
    "navigation": [
      "project.items[*]",
      "project.items[*].entities[*]",
      "project.items[*].entities[*].versions[*]"
    ],
    "policies": {
      "cache": "Treat versions marked \"active\" as canonical; invalidate caches when their hash changes.",
      "load": "Use selectors (if present) to inspect archived or alternate revisions; otherwise rely on the active version."
    },
    "notes": [
      "Timestamps use ISO-8601 (UTC)."
    ]
  },
  "counts": {
    "projects": 1,
    "entities": 3,
    "versions": 5
  },
  "filters": {
    "entities": ["outline@12", "outline@13", "outline@14"]
  }
}
```

- Multi-project (CSV) and wildcard (`*`) exports share the same structure: `project.items` contains one entry per project; `export_meta.scope` becomes `projects` (CSV) or `brain` (`*`).  
- When selectors are applied via presets, `export_meta.scope` is set to `project_slice` to signal that only a subset of entities/versions is present.
- Every entity includes only the active version unless selectors were provided. Selectors are recorded both at entity level (combined list) and per version (indicating which selector(s) matched).  
- Version records always contain `version`, `status`, `hash`, `commit`, `committed_at`, and `payload`; optional fields include `meta` (metadata persisted alongside the version) and `selectors`.  
- Full payloads are emitted so downstream LLM tooling can ingest the slice without additional round-trips.

### Field Overview

- **Top-level**  
  - `project.items[]` → array of project slices (single or multi); `project.count` → number of slices  
  - `export_meta` → `generated_at`, `scope`, `action`, `counts`, `description`, `hash`  
  - `guide` → `usage`, `navigation[]`, `policies{}`, `notes[]`  
  - `counts` → mirrors `export_meta.counts` for quick access  
  - `filters` (optional) → list of entity selectors applied

- **Project entry (`project.items[*]`)**  
  - `slug`, `title`, `description`, `status`, `created_at`, `updated_at`, `archived_at`, `entity_count`, `version_count`, `entities[]`

- **Entity entry**  
  - `slug`, `status`, `created_at`, `updated_at`, `archived_at`, `active_version`, `selectors` (optional array), `versions[]`

- **Version entry**  
  - `version`, `status`, `hash`, `commit`, `committed_at`, `payload`, optional `meta`, optional `selectors`

## Examples

### CLI
```bash
php cli.php "export demo hero,intro@2 description=\"Story slice\" usage=\"Focus on hero arcs\""
```

### REST
```bash
curl -H "Authorization: Bearer <token>" \
  "https://example.test/api.php?action=export&project=demo&entities=hero,intro@2"
```

### PHP
```php
$response = AavionDB::run('export', [
    'project' => 'demo',
    'entities' => 'hero,intro@2',
    'description' => 'Slice for dialogue scenes',
]);
```

All variants yield the JSON envelope described above.

## Implementation Notes
- Module lives in `system/modules/export`; manifests declare parser + storage capabilities.
- Parser normalises shorthand (`export demo foo,bar@2`), accepts optional description/usage text, and supports comma-separated project slugs as well as the wildcard `*`.
- ExportAgent relies on `BrainRepository` report helpers (`projectReport`, `entityReport`, `getEntityVersion`) to assemble metadata (hashes, commits, payloads, optional meta payload extensions) suitable for cache/diff workflows.
- Version selectors (`@version`, `#hash`) raise descriptive errors when the requested revision cannot be located.

## Presets
- Presets live under `user/presets/export/<name>.json` (automatically created if missing). Use `export <project>:<preset>` to apply one. Presets only work with single-project exports.  
- CLI selectors cannot be combined with presets; the preset controls the slice definition.  
- Example preset:

```json
{
  "description": "Character context slice",
  "usage": "Use this slice to prime the LLM with current character beats.",
  "selection": {
    "entities": ["hero", "outline@14"],
    "payload": { "path": "export", "equals": true }
  },
  "transform": {
    "whitelist": ["title", "payload.chapters", "payload.name"],
    "blacklist": ["payload.draftNotes"]
  }
}
```

- `selection.entities` accepts the same syntax as the CLI (slug, `@version`, `#commit`). `selection.payload.path` uses dot-notation relative to the version payload (e.g. `export`, `chapters.0.title`) and compares the value via `equals`.  
- `transform.whitelist` keeps only the listed payload fields (dot-paths), while `transform.blacklist` removes fields. Whitelist is applied first, then blacklist.  
- To extend the schema, adjust `ExportAgent::loadPreset()` and the related helpers (`preparePayloadFilter`, `prepareTransform`) – the Studio UI will adhere to the same structure.

## Error Handling
- Wildcard exports plus selectors → `Entity selectors are not supported when exporting all projects.`
- Multiple projects plus selectors → `Entity selectors are only supported when exporting a single project.`
- Preset + selectors → `Entity selectors cannot be combined with preset-based exports.`
- Unknown preset → `Preset "<name>" not found (expected <path>).`
- Invalid preset JSON → `Preset "<name>" contains invalid JSON: …` (REST → 400, CLI/PHP surface `meta.exception`).
- Payload filter mismatch via preset simply skips the entity version; if all versions are filtered out, the entity is omitted from the slice.

## Outstanding Tasks
- [ ] Add export presets/destination management (disk writes, streaming backends).
- [ ] Coordinate asynchronous exports with the future SchedulerAgent.
- [ ] Introduce profile adapters for schema-aware or LLM-optimised payloads.
