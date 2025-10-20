# Security & Access

Protect your data by managing API tokens, rate limits, and lockdown controls. This chapter focuses on safe defaults without deep technical jargon.

---

## API Tokens

- Generate tokens with `auth grant`.  
- Limit access by specifying projects (`projects=storyverse,lore`).
- Revoke tokens immediately with `auth revoke <token or preview>`.
- List active tokens via `auth list`.

Example:

```bash
php cli.php 'auth grant label="Story Studio" projects=storyverse'
```

REST requests must send the token as `Authorization: Bearer <token>` (alternatives: `X-API-Key`, `token`, or `api_key` parameter).

---

## REST API Control

- `api serve` – Enable the REST interface once at least one user token exists.  
- `api stop` – Disable REST access without touching tokens.  
- `api status` – Show whether REST is enabled and how many tokens are active.  
- `api reset` – Disable REST and revoke all tokens in one go.

Keep REST disabled whenever it is not required, and rotate tokens regularly.

---

## Rate Limiting

The SecurityAgent enforces request limits for REST clients:

- Default rules allow generous usage but block abuse.
- Failed auth attempts trigger stricter limits.
- Admin tokens always bypass the limiter.

Commands:

- `security status` – View current limits, counters, and lockdown state.
- `security enable` / `security disable` – Toggle enforcement.
- `security lockdown [seconds]` – Manually block all requests (useful when rotating tokens).
- `security purge` – Clear rate limiter caches (resets counters).

---

## Cache & Security Settings

All settings live in the system brain so they can be versioned like any other data. Use `set`/`get` with the `--system` flag to update them.

```bash
php cli.php "set security.rate_limit 80 --system"
php cli.php "get security --system"
```

---

## Schema Management

Schemas (fieldsets) keep entities consistent:

- List existing schemas: `schema list`.
- Show a specific revision: `schema show character@3`.
- Create or update: `schema save character {"type":"object","properties":{...}}`.
- Validate without saving: `schema lint {json}`.
- Delete old revisions: `schema delete character@1`.

Attach schemas when saving entities: `save project entity:character`.

---

## Logging & Auditing

- `log` commands help you review errors, auth activity, and debug output (`log AUTH 20`, `log DEBUG 50 --debug`).
- Rotate archives with `log rotate [keep=10]` and prune old files via `log cleanup [keep=10]`.
- Scheduler logs provide an additional audit trail for automated tasks (`scheduler log`).

---

Continue with [Performance & Cache](performance.md) to keep the system fast.
