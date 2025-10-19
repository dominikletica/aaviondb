# Versioning & Commit Hashes

> **Status:** Implemented  
> **Last updated:** 0.1.0-dev

- Each entity version increments an integer counter (`version`) and stores a SHA-256 hash of the canonical (merged) payload.  
- Commits add metadata: `commit` hash (deterministic across content + metadata), timestamps, status (`active|inactive|archived`), `merge` flag, optional `fieldset`, plus optional `source_reference` / `fieldset_reference` when selectors are used.  
- `BrainRepository::saveEntity()` auto-manages version increments, hash calculation, commit lookup registration, and incremental payload merges (null removes keys, nested objects merge recursively). Merge sources default to the active version but can be overridden with `entity@<version>`/`entity#<hash>` selectors.  
- Restore operations (`restore`) accept version numbers or commit hashes; inactive versions remain available for history.

Future work: diff utilities, soft delete policy, cross-brain export/import with signature verification.
