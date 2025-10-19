# SchemaAgent Module

> Status: Implemented – fieldset discovery and schema validation helpers.

## Responsibilities
- Enumerate JSON schema fieldsets stored in the `fieldsets` project.
- Provide quick inspection of schema revisions (by `@version` or `#commit`).
- Offer on-demand linting for JSON Schema definitions using the shared validator.

## Commands
- `schema list [with_versions=1]` – List all fieldsets; optionally include version metadata.
- `schema show <fieldset[@version|#commit]>` – Show a specific schema revision (defaults to active version when no selector is supplied).
- `schema lint {json}` – Validate a JSON Schema payload prior to persistence.

## Implementation Notes
- Module is located at `system/modules/schema` and uses `SchemaValidator` for linting.
- Parser recognises inline selectors (e.g. `schema show character@12`) and forwards references to `BrainRepository::getEntityVersion()`.
- `schema list` relies on the same storage helpers as EntityAgent; versions are retrieved via `listEntityVersions("fieldsets", <slug>)` when requested.
- Commands are read-only for now; future extensions may add mutation helpers (create/update fieldsets, bulk linting, etc.).

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
- [ ] Add write helpers (create/update/remove fieldsets) with audit logging.
- [ ] Surface schema usage metrics and cross-reference with EntityAgent bindings.
- [ ] Integrate with future Studio UI for schema editing workflows.
- [ ] Add PHPUnit coverage for schema list/show/lint flows once the test harness is introduced.
