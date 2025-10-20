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

- `remove <project> <entity>` – Deactivate the entity without deleting history.
- `delete <project> <entity>` – Delete the entity and all versions.
- `delete <project> <entity@version>` – Delete only the selected version.

Each command accepts comma-separated lists for bulk actions.

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

---

Ready to share your data? Continue with [Exports & Presets](exports.md).
