# Versioning & Commit Hashes (DRAFT)

> **Status:** Draft  
> **Last updated:** 0.1.0-dev

- Each entity version increments an integer counter (`version`) and stores a SHA-256 hash of the canonical payload.  
- Commits add metadata: `commit` hash (deterministic across content + metadata), timestamps, status (`active|inactive`).  
- `BrainRepository::saveEntity()` auto-manages version increments, hash calculation, and commit lookup registration.  
- Restore operations (`restore`) accept version numbers or commit hashes; inactive versions remain available for history.

Future work: diff utilities, soft delete policy, cross-brain export/import with signature verification.
