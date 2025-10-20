# Schemas & Validation

Fieldsets (also called schemas) keep your entity payloads consistent. They are stored inside the special project `fieldsets` and can be attached to any entity.

---

## When to Use Schemas

- Guarantee that required fields exist (e.g., every character has a `name`).
- Enforce data types (`string`, `number`, `array`, etc.).
- Provide defaults for fields that can be omitted in partial updates.
- Improve cooperation with external UIs (forms can be generated automatically).

---

## Create a Schema

```bash
php cli.php 'schema create character {"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","properties":{"name":{"type":"string"},"role":{"type":"string"}},"required":["name"]}'
```

- The schema is saved as an entity `character` in the `fieldsets` project.
- Use `schema save` for idempotent upserts (creates or updates depending on existence).
- Set `--merge=1` to merge with the previous revision instead of replacing it.

REST example:

```bash
curl -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"command":"schema save character","payload":{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","properties":{"name":{"type":"string"}},"required":["name"]}}' \
  https://example.test/api.php
```

---

## Inspect Schemas

```bash
php cli.php "schema list"
php cli.php "schema show character"
php cli.php "schema show character@2"
```

- `@version` and `#hash` selectors let you inspect historical revisions.
- Add `with_versions=1` to `schema list` to display version history.

---

## Validate Without Saving

```bash
php cli.php 'schema lint {"type":"object","properties":{"name":{"type":"string"}},"required":["name"]}'
```

Returns `status=ok` when the JSON Schema is valid. Use this before you persist complex definitions.

---

## Attach a Schema to an Entity

```bash
php cli.php 'save storyverse hero:character {"name":"Aria","role":"Pilot"}'
```

- `hero` will be validated against the `character` schema on every save.
- Partial updates merge with the previous payload before validation.
- Use `fieldset@version` if you want to pin a specific schema revision.

To remove a schema reference from an entity, save it with an empty selector:

```bash
php cli.php 'save storyverse hero {"fieldset":""}'
```

---

## Keep Schemas Updated

- Use `schema update` when you want to replace the schema payload.
- Use `schema save` with `--merge=1` for incremental adjustments (add new optional fields, keep existing ones).
- Delete obsolete revisions with `schema delete character@3`.

---

## Tips

- Always lint schemas (`schema lint`) before saving to catch mistakes quickly.
- Combine schemas with presets to produce tailored exports for LLMs.
- Document your schemas in project descriptions so collaborators know what to expect.

Need a technical deep dive? Check the [SchemaAgent module reference](../../dev/partials/modules/schema.md).
