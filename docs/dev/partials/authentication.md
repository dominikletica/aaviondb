# Authentication & API Keys (DRAFT)

> **Status:** Draft  
> **Last updated:** 0.1.0-dev

## Bootstrap Key
- Static key `admin` inserted on first boot (`is_bootstrap = true`).  
- Valid for UI login and CLI/PHP recovery.  
- REST remains disabled while `admin` is the only active key.

## Key Lifecycle
- `auth grant`: Generates random token (32+ chars), marks `is_bootstrap = false`, enables REST via `api serve` requirement. Outputs handling instructions.  
- `auth list`: Shows masked tokens, status (`active|revoked`), creation timestamps, bootstrap flag.  
- `auth revoke {key}`: Marks token as revoked. If no non-bootstrap keys remain, force `api stop` and reactivate `admin`.  
- `auth reset` (planned): Revokes all tokens, disables REST, re-enables `admin`.  
- Keys stored under `system.brain.auth.keys`.

## REST Control
- `api serve`: Enables REST only if at least one non-bootstrap key is active.  
- `api stop`: Disables REST; subsequent requests return HTTP 503 until re-enabled.

## Logging & Recovery
- All auth events logged (e.g., `system/storage/logs/auth.log`).  
- CLI/PHP require no key; use `auth grant` or `auth reset` if REST keys are lost.  
- UI should prompt user to create a personal key immediately after bootstrap.
