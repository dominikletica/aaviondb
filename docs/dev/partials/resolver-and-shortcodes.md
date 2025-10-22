# Resolver & Shortcode Engine

> **Status:** Implemented – shared resolver powers entity exports and inline references.  
> **Last updated:** 2025-10-22

## Overview
- `AavionDB\Core\Resolver\ResolverEngine` resolves inline shortcodes inside entity payloads whenever a payload is rendered (`entity show`, `export`, future Studio/UI outputs).
- `ResolverContext` supplies project/entity identifiers, active version, command variables, and the current (transformed) payload to the engine.
- Shortcode results are rendered inline as `[ref …]resolved[/ref]` and `[query …]resolved[/query]` so consumers (LLMs, humans, Studio UI) can see both the instruction and the produced data.
- During persistence `BrainRepository::saveEntity()` strips any rendered suffix to keep stored payloads deterministic (`[ref …]` only). This makes round-tripping safe: exports can be re-imported without embedding stale resolved data.

## Supported Shortcodes

### `[ref …]` – direct references
```
[ref @project.slug.path.to.field|format=markdown|separator=\n]
```
- **Target** – `@project.entity` (defaults to the current project when `@` is omitted). Versions can be pinned via `@project.entity@12` or `@project.entity#hash`.
- **Path syntax** – dot notation for objects, `[index]` for arrays. Additional path fragments can be appended with `|field`/`|0`.  
  Example: `[ref @aurora7.kael.profile|details.notes[0]]`
- **Options**  
  - `format` – `json` (default), `plain`, or `markdown`.  
  - `separator` – glue string for `plain`/`markdown` lists (defaults to newline).  
  - `template` – per-item template when resolving lists (placeholders `{value}`, `{record.version}`, `{record.payload.field}`).
- Cycle protection prevents infinite loops; a repeated visit to the same `project.entity:path` returns `<cycle>`.

### `[query …]` – filtered lookups
```
[query project=aurora7|where="payload.tags contains pilot;payload.rank >= 3"|select=payload.brief|format=markdown|template="* {record.slug}: {value}"|limit=5]
```
- **Scoping** – `project=` (CSV) or `projects=` (array) limits the search. Omitted ⇒ current project.
- **Selection** – `select=` path decides which field is extracted for each match (default `payload`). Wildcards such as `payload.details` work on associative structures.
- **Where clause (`where="…"`)**  
  - Each condition separated by `;`.  
  - Supported operators: `=`, `!=`, `>`, `<`, `>=`, `<=`, `contains`, `!contains`, `in (…)`, `not in (…)`, `~` (PCRE).  
  - Values accept quotes (`"str"`/`'str'`), numeric literals, JSON arrays (`["a","b"]`), or `(a,b,c)` lists.
- **Sorting & paging** – `sort=payload.name asc|limit=10|offset=5`.
- **Formatting** – `format=json|plain|markdown|raw`.  
  - `template` and `separator` follow the same rules as `[ref]`.  
  - `json` includes the full record (uid, project, slug, value). `raw` strips the record wrapper.
- Query evaluation reuses entity metadata + active payloads. Results honour the same cycle guard as `[ref]`.

## Placeholder Resolution
- `${project}`, `${entity}`, `${uid}`, `${version}` expand to the current resolver context.
- `${param.*}` pulls values from command arguments (`--param.foo=bar`, `--var.foo=bar`, JSON payload fields). Arrays become comma-separated lists.
- `${payload.*}` reads the caller's payload before resolution; useful for self-referential lookups.
- Placeholders work inside targets, where clauses, and option values.
- URL helpers inside templates expose hierarchy-aware paths:
  - `{record.url}` – relative path from the caller’s entity to the referenced entity (falls back to project-root absolute when the caller path is unknown).
  - `{record.url_relative}` / `{record.url_absolute}` – explicit relative vs. project-root absolute variants (absolute paths never include the project slug so users can prepend their own site prefix).

## Call Sites
- `EntityAgent::entityShowCommand()` – resolves payload before returning it to the caller.
- `ExportAgent::buildEntityRecord()` – resolves transformed payloads for every exported version.
- Stripping on save happens inside `BrainRepository::saveEntity()`; modules do not need to perform cleanup manually.

## Example
```json
{
  "summary": "See [ref @aurora7.arena_brief.payload synopsis] for the latest arena synopsis.",
  "relationships": "[query project=aurora7|where=\"payload.participants contains ${entity}\"|select=payload|format=markdown|template=\"* [{record.slug}]({record.url}) – {value.title}\"|limit=8]"
}
```
Rendered output (excerpt):
```
summary: See [ref @aurora7.arena_brief.payload synopsis]The arena shifts each cycle...[/ref]
relationships: [query project=aurora7|where="payload.participants contains kael_mercer"|...]
* [arena_round_41](../arena_round_41) – Clash of the captains
* [arena_round_42](../arena_round_42) – Debrief aftermath
[/query]
```

Keep payload definitions concise—heavy lifting belongs in resolver/query expressions so exports remain LLM-friendly and deterministic.
