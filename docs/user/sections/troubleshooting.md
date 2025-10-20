# Troubleshooting & FAQ

This section answers common questions and helps you resolve issues quickly.

---

## CLI Command Fails with “Unknown command”

- Make sure you wrapped the command in quotes when using the CLI (`php cli.php "list projects"`).
- Run `help` to list available commands.
- Confirm the active modules via `status`.

---

## REST Request Returns 401 Unauthorized

- Verify the API token is included (`Authorization: Bearer <token>`).
- Ensure the REST API is enabled: `php cli.php "api status"`.
- Check rate limiting via `security status`; you may have been temporarily blocked.

---

## REST Request Returns 429 Too Many Requests

- Slow down your calls or increase the limit through the system brain:

```bash
php cli.php "set security.rate_limit 120 --system"
```

- Review the security log: `php cli.php "log AUTH 20"`.

---

## Schema Validation Fails

- Run `schema show <slug>` to inspect the fieldset.
- Lint the schema locally: `schema lint {json}`.
- Remember that partial updates merge with the existing payload before validation.

---

## Export Missing Entities

- Check preset filters (whitelists, blacklists, regex matchers).
- Verify the entity is active (use `list versions` to confirm status).
- Ensure the export command includes the correct project scope.

---

## Scheduler Tasks Not Running

- Confirm the task exists: `scheduler list`.
- Inspect the scheduler log: `scheduler log`.
- Trigger manually: `php cli.php "cron"`.
- For REST calls, visit `https://example.test/api.php?action=cron` (no token required).

---

## Quick Reference

| Task | Command |
|------|---------|
| List projects | `list projects` |
| Create project | `project create <slug>` |
| Save entity | `save <project> <entity> {json}` |
| Show entity | `show <project> <entity[@version]>` |
| Export project | `export <project>` |
| Enable REST | `api serve` |
| Tail logs | `log DEBUG 20` |
| Purge cache | `cache purge` |
| Lock down API | `security lockdown 300` |

Need a full command index? See [`docs/dev/commands.md`](../../dev/commands.md).

---

Still stuck? Collect the error message and share it with the development team. The [Developer Manual](../../dev/MANUAL.md) contains deeper diagnostics guidance.
