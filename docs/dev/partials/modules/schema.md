# SchemaAgent Module

> **Status:** Implemented – fieldset CRUD helpers, discovery, and schema validation.  
> **Last updated:** 2025-10-20

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

## Call Flow
- `system/modules/schema/module.php` instantiates `AavionDB\Modules\Schema\SchemaAgent` and calls `register()`.  
- `SchemaAgent::registerParser()` rewrites verbs (`schema list`, `schema show`, `schema lint`, etc.) and lifts selectors/slug arguments into structured parameters before dispatch.  
- Command handlers:  
  - `schemaListCommand()` → `BrainRepository::listEntities('fieldsets')` and optionally `listEntityVersions()`; emits debug telemetry with counts.  
  - `schemaShowCommand()` → resolves selectors via `parseSelector()` and fetches the record with `getEntityVersion('fieldsets', …)`.  
  - `schemaLintCommand()` → delegates to `SchemaValidator::assertValidSchema()`.  
  - `schemaSaveCommand()` (shared by `create`, `update`, `save`) → normalises slug/payload, validates JSON, and persists using `BrainRepository::saveEntity()` with merge flags.  
  - `schemaDeleteCommand()` → removes entities or specific revisions through `BrainRepository::deleteEntity()` / `deleteEntityVersion()`.  
- Logging uses the module-scoped logger with `source=schema` to aid LogAgent filtering.

## Key Classes & Collaborators
- `AavionDB\Modules\Schema\SchemaAgent` – parser + command registrar.  
- `AavionDB\Core\Storage\BrainRepository` – persistence layer for the `fieldsets` project.  
- `AavionDB\Core\Schema\SchemaValidator` – JSON Schema validation and normalisation.  
- `AavionDB\Core\Modules\ModuleContext` – command registry, logger, debug helper.  
- `AavionDB\Core\CommandResponse` – uniform response wrapper for success/error states.

## Implementation Notes
- Module is located at `system/modules/schema`; schema persistence is handled via `BrainRepository::saveEntity()` against the special `fieldsets` project.
- Parser recognises inline selectors (e.g. `schema show character@12`) and forwards references to `BrainRepository::getEntityVersion()`.
- `schema list` relies on the same storage helpers as EntityAgent; versions are retrieved via `listEntityVersions("fieldsets", <slug>)` when requested.
- `BrainRepository::resolveSchemaDefinition()` validates schemas and returns both payload and version metadata; EntityAgent stores the resolved version alongside the entity so future saves validate against the same revision unless a new selector is provided.
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
