# SchemaAgent Module

> Status: Implemented – fieldset CRUD helpers, discovery, and schema validation.

## Responsibilities
- Enumerate JSON schema fieldsets stored in the `fieldsets` project.
- Provide quick inspection of schema revisions (by `@version` or `#commit`).
- Offer on-demand linting for JSON Schema definitions using the shared validator.
- Create, update, upsert, and delete fieldset schemas via friendly CLI/REST wrappers.

## Commands
- `schema list [with_versions=1]` – List all fieldsets; optionally include version metadata.
- `schema show <fieldset[@version|#commit]>` – Show a specific schema revision (defaults to active version when no selector is supplied).
- `schema lint {json}` – Validate a JSON Schema payload prior to persistence.
- `schema create <slug> {json}` – Create a new schema entity; fails when the slug already exists.
- `schema update <slug> {json} [--merge=0|1]` – Update an existing schema (default is replace, `--merge=1` performs a deep merge).
- `schema save <slug> {json} [--merge=0|1]` – Upsert helper that creates the schema if it does not exist.
- `schema delete <slug> [@version|#commit]` – Delete a schema entirely or remove a specific revision.

## Implementation Notes
- Module is located at `system/modules/schema`; schema persistence is handled via `BrainRepository::saveEntity()` against the special `fieldsets` project.
- Parser recognises inline selectors (e.g. `schema show character@12`) and forwards references to `BrainRepository::getEntityVersion()`.
- `schema list` relies on the same storage helpers as EntityAgent; versions are retrieved via `listEntityVersions("fieldsets", <slug>)` when requested.
- Create/update/save commands wrap fieldset operations with basic existence checks and default to full replacements (merge disabled unless explicitly requested).

## Examples

### CLI
```bash
php cli.php "schema list"
```
```json
{
  "status": "ok",
  "action": "schema list",
  "data": {
    "project": "fieldsets",
    "count": 2,
    "schemas": [
      {"slug": "character", "version_count": 5},
      {"slug": "location", "version_count": 3}
    ]
  }
}
```

```bash
php cli.php "schema show character@3"
```

```bash
php cli.php 'schema create npc {"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","properties":{"name":{"type":"string"}},"required":["name"]}'
```

### PHP
```php
$response = AavionDB::run('schema lint', [
    'payload' => [
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'export' => ['type' => 'boolean', 'default' => false]
        ],
        'required' => ['name']
    ],
]);
```

## Error Handling
- Missing fieldset selector → `status=error`, message `Fieldset selector is required.`
- Unknown schema/version → propagates `StorageException` such as `Schema "character@13" not found in project "fieldsets".`
- Invalid schema payloads return `status=error` with `SchemaException` message details.

## Outstanding Tasks
- [ ] Surface schema usage metrics and cross-reference with EntityAgent bindings.
- [ ] Integrate with future Studio UI for schema editing workflows.
- [ ] Add PHPUnit coverage for schema commands once the test harness is introduced.
