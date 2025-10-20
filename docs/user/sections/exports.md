# Exports & Presets

Exports turn your project data into JSON packages that other tools (like ChatGPT) can consume as context.

---

## Basic Export

```bash
php cli.php "export storyverse"
```

- Exports the active versions of all entities in `storyverse`.
- The response contains a JSON object with project metadata, entities, versions, and cache guidance.

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

- `export storyverse hero,villain` – Choose specific entities.
- `export storyverse hero@12` – Export entity version 12.
- `export storyverse hero@12,villain@current` – Mix fixed versions and active versions.
- `export storyverse*` is not allowed; use comma-separated projects (`project1,project2`) or `*` to export every project in the active brain.

---

## Description & Usage Guidance

Add human-readable instructions:

```bash
php cli.php 'export storyverse description="Context bundle for story planning" usage="Load all entities and keep the latest notes handy."'
```

- `usage` overrides `description` for downstream guidance.  
- If only `description` is provided, the exporter copies it to `usage` automatically.

---

## Presets

Presets live in `user/presets/export/`. They help you:

- Select slices based on entity lists or payload filters.
- Modify exported payloads (whitelist/blacklist fields).
- Provide default descriptions and usage texts.

Call a preset with:

```bash
php cli.php "export storyverse:preset-name"
```

To inspect or edit a preset, open the JSON file and adjust `selection` and `transform` rules. Regex filters are supported when `match` is set to `regex`.

---

## Example Export Snippet

```json
{
  "status": "ok",
  "data": {
    "scope": "project",
    "project": {
      "slug": "storyverse",
      "title": "Story Verse",
      "description": "Shared world bible",
      "entities": [
        {
          "slug": "hero",
          "active_version": 3,
          "versions": [
            {
              "version": 3,
              "status": "active",
              "hash": "b9d2…",
              "committed_at": "2025-10-19T09:12:34Z",
              "payload": { "name": "Aria", "role": "Pilot" }
            }
          ]
        }
      ]
    },
    "guide": {
      "description": "Context bundle for story planning",
      "usage": "Load all entities and keep the latest notes handy.",
      "cache": {
        "policy": "invalidate-on-active-version-change"
      }
    }
  }
}
```

---

## Tips for LLM Efficiency

- Keep descriptions short and action oriented.
- Use presets to remove fields the LLM does not need.
- Export only the relevant entities to reduce token usage.

---

Need automated exports? Continue to [Automation & Scheduler](automation.md).
