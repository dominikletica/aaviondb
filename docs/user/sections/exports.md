# Exports & Presets

Exports produce deterministic JSON bundles optimised for LLM consumption. They flatten entities, embed guidance, and surface cache policies so downstream tools (for example ChatGPT) can reason about the payload quickly.

---

## Basic Export

```bash
php cli.php "export storyverse"
```

- Returns the active version of every entity in the `storyverse` project.
- The response follows the `context-unified-v2` layout (see example below).

REST:

```bash
curl -H "Authorization: Bearer <token>" \
  "https://example.test/api.php?action=export&project=storyverse"
```

PHP:

```php
$response = AavionDB::run('export', ['project' => 'storyverse']);
```

---

## Selecting Entities and Versions

- `export storyverse hero,villain` – limit the export to specific entities.
- `export storyverse hero@12` – export a fixed entity revision.
- `export storyverse hero@12,villain@current` – mix fixed revisions with the active one.
- `export *` – export every project in the active brain (no selectors allowed in this mode).

`description="..."` and `usage="..."` add human-readable guidance to the export bundle. When `usage` is omitted the exporter reuses the description.

---

## Preset Workflows

Presets now live in the **system brain** and are managed through the `preset` command family:

```bash
php cli.php "preset list"
php cli.php "preset show default"
php cli.php "preset copy default focus-scene"
php cli.php "preset update focus-scene" --payload='{"meta": {...}}'
php cli.php "preset delete focus-scene"
```

- `preset import <slug> <file>` and `preset export <slug> [--file=...]` allow round-tripping JSON definitions.
- `preset create <slug> --payload='{"meta": {...}}'` stores a new preset after validation.
- Presets define project selectors, entity filters, payload whitelists/blacklists, default descriptions, policies, and the output layout.
- Use placeholders: presets can reference `${project}` (the CLI argument) and `${param.name}` values passed via `--param.name=value`.

Execute a preset export:

```bash
php cli.php "export storyverse --preset=focus-scene --param.scene=intro"
```

The command automatically resolves the preset, applies filters through the FilterEngine, and renders the bundled layout.

### Inspecting Variables (`preset vars`)

```bash
php cli.php "preset vars focus-scene"
```

- Lists placeholders the preset expects (`project`, `${param.scene}`, …).
- Shows whether parameters are required, default values, and descriptions.
- Pass values with `--param.<name>=value` or the alias `--var.<name>=value`. Multiple variables per command are supported.

### Default Preset & Layout

The default preset is **read-only**. Copy it before making changes:

```bash
php cli.php "preset copy default my-custom-slice"
```

Default preset (`preset show default`):

```json
{
  "meta": {
    "description": "Default context export preset.",
    "usage": "Exports the current project with canonical entities for LLM ingestion.",
    "layout": "context-unified-v2",
    "read_only": true,
    "immutable": true
  },
  "selection": {
    "projects": ["${project}"],
    "entities": [],
    "payload_filters": []
  },
  "transform": {
    "whitelist": [],
    "blacklist": [],
    "post": []
  },
  "policies": {
    "references": { "include": true, "depth": 1 },
    "cache": { "ttl": 3600, "invalidate_on": ["hash", "commit"] }
  },
  "placeholders": ["project"],
  "params": {}
}
```

The matching layout (`context-unified-v2`) is stored alongside presets and controls the JSON structure rendered by the exporter. Update it only if you want to change the global output format.

Placeholders recognised by the exporter:

- `project` – always resolves to the project slug that you supply with `export <project>`.
- `${param.<name>}` – resolved from `--param.<name>=value` or `--var.<name>=value` (multiple variables are supported per command).
- `${param.<name>}` entries declared inside the preset’s `params` block will appear in `preset vars <slug>` together with their required/default metadata.

### Creating Your Own Preset

1. Copy the default preset: `preset copy default scene-kit`.
2. Edit the new preset definition via `preset update scene-kit --payload='{...}'` or `preset export` → modify file → `preset import`.
3. Use `preset vars scene-kit` to confirm which variables must be supplied.
4. Run the export: `export myproject --preset=scene-kit --param.scene=intro --param.timeline=main`.

---

## Inline Context in Exports

- Entity payloads that use `[ref …]` or `[query …]` shortcodes are resolved during export. The JSON keeps the original marker and appends the resolved output, for example:

  ```json
  "summary": "[ref @storyverse.hero.payload profile.summary]Aria joined the fleet...[/ref]"
  "cast": "[query project=storyverse|where=\"payload.tags contains squadron\"|format=markdown|template=\"* [{record.slug}]({record.url}) – {value.title}\"] * alpha_squad – Elite pilots\n * beta_squad – Recon detail\n[/query]"
  ```

- Resolver parameters inherit any `--param.*`/`--var.*` variables you pass to `export`, so presets can personalise queries without extra scripting.
- Templates expose `{record.url}` (relative path from the calling entity), `{record.url_relative}`, and `{record.url_absolute}` (project-root absolute without the project slug). Append your own base URL if you need fully-qualified links.
- Because the stored payload does not include the rendered suffix, you can re-import an export without duplicating resolved text—the engine removes the trailing portion during `save`.

---

## Example Response (`context-unified-v2`)

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
    "notes": [
      "Each entity is self-contained and references others via '@project.slug'.",
      "Active versions represent canon unless selectors override."
    ]
  },
  "policies": {
    "load": "Treat \"active_version\" as canonical unless selectors override.",
    "cache": {
      "ttl": 3600,
      "invalidate_on": ["hash", "commit"]
    },
    "references": {
      "include": true,
      "depth": 1
    }
  },
  "index": {
    "projects": [
      { "slug": "storyverse", "title": "Story Verse", "entity_count": 2 }
    ],
    "entities": [
      { "uid": "storyverse.hero", "project": "storyverse", "slug": "hero" }
    ]
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
      "payload": {
        "name": "Aria",
        "role": "Pilot"
      },
      "payload_versions": [
        {
          "version": "3",
          "status": "active",
          "hash": "b9d2…",
          "commit": "b9d2…",
          "committed_at": "2025-10-19T09:12:34Z",
          "payload": {
            "name": "Aria",
            "role": "Pilot"
          }
        }
      ]
    }
  ],
  "stats": {
    "projects": 1,
    "entities": 1,
    "versions": 1
  }
}
```

---

## Tips for LLM Efficiency

- Use presets to limit the slice (payload filters, whitelists, reference depth).
- Provide concise `usage` instructions so the model knows how to consume the bundle.
- `--param.*` enables preset placeholders (`${param.scene}`) for dynamic slices.
- Combine exports with the cache agent to warm or purge artefacts before handing them to an LLM.

---

Looking for scheduled or automated exports? Continue to [Automation & Scheduler](automation.md).
- Lege Pflichtfelder, Standardwerte und Typen im `params`-Block des Presets fest. Beispiel:

  ```json
  "params": {
    "scene": {"required": true, "type": "text"},
    "timeline": {"type": "comma_list", "default": "main,flashback"}
  }
  ```
