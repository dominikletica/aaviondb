# ResolverAgent Module

> **Status:** Implemented – lightweight CLI/REST shortcode resolver.  
> **Last updated:** 2025-10-22

## Responsibilities
- Provide a `resolve` command that expands a single `[ref …]` or `[query …]` snippet against a specific entity/version.
- Bridge the `ResolverEngine` to CLI/REST tooling without requiring consumers to reimplement context setup.
- Surface resolver parameters (`--param.*` / `--var.*`) for ad-hoc previews, debugging, or Studio integrations.

## Command
- `resolve [shortcode] --source=project.entity[@version|#commit] [--param.foo=value]`
  - `shortcode` – the raw shortcode string (wrap in quotes if it contains spaces).
  - `--source` – selects the entity context, defaulting to the active version when no selector is supplied.
  - `--param.*` / `--var.*` – supplies resolver placeholders (mirrors ExportAgent behaviour).
  - Returns the resolved string plus meta information (version, commit, hierarchy path).

## Call Flow
1. `module.php` instantiates `ResolverAgent` and registers parser + command metadata.
2. Parser reconstructs the shortcode from positional tokens and converts `--param.*` / `--var.*` into a parameter map.
3. Handler loads the requested entity version via `BrainRepository::getEntityVersion()` and fetches hierarchy info from `entityReport()`.
4. A `ResolverContext` is initialised with payload, params, version, and hierarchy path; `ResolverEngine::resolvePayload()` evaluates the shortcode.
5. Result is returned via `CommandResponse::success`, including resolved value and provenance meta.

## Key Classes
- `AavionDB\Modules\Resolver\ResolverAgent` – parser/command definition.
- `AavionDB\Core\Resolver\ResolverEngine` – shared shortcode evaluator.
- `AavionDB\Core\Resolver\ResolverContext` – carries project/entity/version/params/hierarchy.
- `AavionDB\Core\Storage\BrainRepository` – entity lookup and metadata access.

## Error Handling
- Missing shortcode → `Shortcode is required…`
- Unsupported prefix → `Shortcode must start with [ref …] or [query …].`
- Missing source → `Parameter "--source=…" is required.`
- Invalid source syntax → `Source must follow the pattern project.entity[@version|#commit].`
- Unknown entity/version/commit bubble up as `StorageException` messages.
