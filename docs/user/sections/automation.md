# Automation & Scheduler

Automate repetitive tasks by scheduling commands. The scheduler lives inside the system brain and can run through the CLI or REST.

---

## Scheduler Basics

- Tasks are stored under `scheduler.*` keys in the system brain.
- Each task has a slug and a command string (exactly what you would type in the CLI).
- The `cron` command executes all registered tasks in order.

---

## Add a Task

```bash
php cli.php 'scheduler add nightly-export "export storyverse description=\"Nightly context dump\""'
```

Edit the command later:

```bash
php cli.php 'scheduler edit nightly-export "export storyverse usage=\"Nightly context\" --debug"'
```

---

## List & Remove Tasks

```bash
php cli.php "scheduler list"
php cli.php "scheduler remove nightly-export"
```

The list output shows slug, command, and last run time.

---

## Run Scheduled Commands

### CLI

```bash
php cli.php "cron"
```

### REST (no token required)

```bash
curl "https://example.test/api.php?action=cron"
```

Each run is recorded in the scheduler log.

---

## Inspect Scheduler Logs

```bash
php cli.php "scheduler log"
```

Shows executed commands, success state, runtime, and error messages (if any).

---

## Best Practices

- Keep commands idempotent; they may run while data changes.
- Combine with presets for consistent export slices.
- Use system-level caching to avoid regenerating heavy exports too often.

---

Next up: [Security & Access](security.md).
