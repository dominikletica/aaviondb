# Entity Lifecycle

Entities store your actual content. Every save creates a new version, so you can experiment without losing history.

---

## Create or Update an Entity

```bash
php cli.php 'save storyverse hero {"name":"Aria","role":"Pilot"}'
```

- Creates the entity if it does not exist.
- Stores the payload as version `@1`.

---

## Partial Updates (Merge)

You can update only the fields you need. Missing fields keep their previous value. Set a field to an empty value to remove it.

```bash
php cli.php 'save storyverse hero {"role":"Commander","callsign":""}'
```

- Sets `role` to `"Commander"`.
- Removes `callsign` because the value is empty.

---

## Schema Validation

Fieldsets are JSON Schema definitions stored via the SchemaAgent. See [Schemas & Validation](schemas.md) for a full walkthrough.

Attach a schema when saving:

```bash
php cli.php 'save storyverse hero:character {"name":"Aria","role":"Pilot"}'
```

- `character` is a fieldset stored via `schema create`.
- AavionDB merges the payload, then validates the result against the schema.
- Use `fieldset@12` or `fieldset#hash` to target older schema versions.

---

## Organise Entities into Hierarchies

Use slash-separated paths to nest entities under parents:

```bash
php cli.php 'save storyverse characters/hero {"name":"Aria","role":"Pilot"}'
```

- The last segment (`hero`) becomes the entity slug.  
- The preceding segments (`characters`) define the parent chain.  
- Parents must already exist. Missing segments are skipped and surfaced as warnings in the response. The entity is placed at the deepest valid level (the root if no parent can be resolved).  
- To reassign an entity without changing the slug, provide a `parent` option:  
  `php cli.php 'save storyverse hero {"role":"Ace"} parent=characters/veterans'`
- You can omit the payload when only moving the entity: `php cli.php 'save storyverse hero --parent=characters/veterans'` (internally merges with the existing payload).
- The maximum depth defaults to 10 segments. Adjust via `set hierarchy.max_depth 5`.

---

## List Children Within a Parent

```bash
php cli.php "entity list storyverse characters/heroes"
```

- Returns only the entities that live under `characters/heroes`.
- Omit the path to list everything, or pass `/` (empty path) to list only root-level entities.
- Add `with_versions=1` to include the version list for each entity.

REST example:

```bash
curl -H "Authorization: Bearer <token>" \
  "https://example.test/api.php?action=entity%20list&project=storyverse&parent=characters/heroes"
```

---

## Show an Entity

```bash
php cli.php "show storyverse hero"
```

Add selectors to view older versions:

```bash
php cli.php "show storyverse hero@2"
php cli.php "show storyverse hero#1c8f94"
```

---

## Restore an Older Version

```bash
php cli.php "restore storyverse hero @2"
```

Creates a new active version with the data from `@2`.

---

## Remove or Delete Entities

- `remove <project> <entity-path[,entity2]> [--recursive=0|1]` – Deactivate entities while keeping history. Without `--recursive=1`, direct children are promoted to the root level and a warning is returned. Use `--recursive=1` to archive the entire subtree.
- `delete <project> <entity-path[,entity2]> [--recursive=0|1]` – Permanently delete entities. Missing `--recursive=1` promotes children before deleting the parent; `--recursive=1` removes every descendant and their commits.  
- `delete <project> <entity@version>` – Delete only the selected version or commit hash.

Each command accepts comma-separated lists for bulk actions.

The command response contains `warnings` and `cascade` details so you can review which children were promoted or purged.

---

## Tracking Versions

```bash
php cli.php "list versions storyverse hero"
```

Shows versions, commit hashes, timestamps, and active status.

---

## REST Example

```bash
curl -H "Authorization: Bearer <token>" \
  -d 'command=save storyverse hero {"name":"Aria"}' \
  https://example.test/api.php
```

---

## PHP Example

```php
$response = AavionDB::command('save storyverse hero', [
    'payload' => ['name' => 'Aria', 'role' => 'Pilot'],
]);
```

## Move an Entire Subtree

```bash
php cli.php "entity move storyverse characters/heroes/alpha characters/veterans"
```

- Moves `alpha` (and all of its descendants) under `characters/veterans`.
- Use `/` or an empty string as the target path to move an entity back to the root: `entity move storyverse characters/heroes/alpha /`.
- Optional `--mode=replace` is reserved for future conflict-handling and currently behaves like `merge`.

REST:

```bash
curl -H "Authorization: Bearer <token>" \
  "https://example.test/api.php?action=entity%20move&project=storyverse&entity=characters/heroes/alpha&target=characters/veterans"
```

---

Ready to share your data? Continue with [Exports & Presets](exports.md).
