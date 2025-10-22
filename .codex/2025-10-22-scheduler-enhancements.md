# Scheduler Enhancements – Concept (2025-10-22)

## Goals
- Allow each scheduled task to define when it should run (`cron` expressions) instead of executing on every `cron` invocation.
- Track execution outcomes (`last_run`, success/failure, retry counter) so overdue jobs can be picked up reliably without unbounded history.
- Prioritise jobs (High → Standard → Low) while preserving deterministic ordering.
- Offer an optional preview/dry-run mode to see which jobs would run without executing commands.
- Keep the feature set lightweight and compatible with the existing CLI-based scheduler (`cron` command).

## Key Constraints
- No external dependencies should be required beyond what we already ship; cron evaluation must be handled inside the module.
- Scheduler should remain CLI/API agnostic: commands are arbitrary CLI strings executed via the existing dispatcher.
- Task metadata lives in the system brain (`BrainRepository`) so state is persisted across runs.
- Logging continues to write full command output into the existing scheduler log; we simply store summary metrics (`last_run`, `last_exit`, etc.) alongside each job so scheduling logic does not need to parse log files and we avoid unbounded state in the task record.

## Data Model Extensions

```jsonc
{
  "slug": "nightly-cleanup",
  "command": "export aurora --preset=context-jsonl --save=1",
  "priority": "high",          // high | standard | low (default standard)
  "cron": "0 3 * * *",         // standard 5-field cron syntax
  "max_retries": 2,            // default 2
  "retry_count": 0,            // incremented on failure, reset on success
  "last_run": "2025-10-22T02:59:48Z",   // ISO timestamp of last attempt
  "last_success": "2025-10-22T02:59:48Z",
  "last_error": null,          // timestamp of last failure
  "last_exit": 0,              // integer exit code
  "last_message": "Completed in 4.2s",  // optional human-readable summary
  "created_at": "...",
  "updated_at": "..."
}
```

### Storage notes
- `priority` stored as lowercase string for readability.
- `cron` validated and normalised on write. Invalid expressions rejected with helpful error messages.
- `retry_count` reset to `0` on success; if a run fails and `retry_count` >= `max_retries`, we leave the task for the next scheduled window (no disablement). Users can raise `max_retries` or inspect logs.

## Cron Evaluation Strategy

We implement a minimal cron evaluator that supports the standard five fields (minute, hour, day-of-month, month, day-of-week):

1. Parse expression into allowed sets per field (support `*`, numeric ranges `1-5`, lists `1,5,10`, and step syntax `*/15`).
2. When `cron` is invoked, compute the most recent scheduled time <= now.  
   - If `last_run` is `null` (new task), treat it as overdue and run immediately.
   - If `last_run` < computed scheduled time, job is due (possibly overdue).
3. After executing, set `last_run` to current time, update `last_success`/`last_error`.
4. We do not persist the entire schedule history; `last_run` is sufficient to deduce overdue runs.

### Overdue Handling Example
- Task cron: `15 * * * *` (15 minutes past each hour)
- Actual cron invocation: at `10:22`, `last_run` was `09:45`. Highest scheduled time <= now is `10:15` → job is overdue → run once (no catch-up loop; we assume single attempt per invocation to avoid cascaded backlog).

## Execution Order
1. Load all enabled tasks.
2. Evaluate due status; skip tasks not due yet.
3. Sort due tasks by:
   - Priority weight (`high` > `standard` > `low`)
   - `last_run` ascending (older tasks first)
   - Slug alphabetical as final tie-breaker.
4. Execute sequentially using existing command runner.

### Failure & Retry
- On failure, increment `retry_count` (capped at `max_retries` to avoid overflow) and set `last_error`, `last_exit`, `last_message`.
- Task remains due on the next cron invocation even if the cron window has not yet advanced (retry on next run regardless of cron schedule, fulfilling the user request).
- Once a retry succeeds, reset `retry_count` and update `last_success`.

## CLI/API Updates

```
scheduler add <slug> <command> [--cron="*/15 * * * *"] [--priority=high|standard|low] [--retries=2]
scheduler edit <slug> [command] [--cron=...] [--priority=...] [--retries=...]
scheduler list [--due] [--priority=...]
scheduler log [slug] [--limit=20]   // unchanged (shows last runs)
scheduler preview [--window=60]     // optional new command
```

- `scheduler list` output enhanced with columns: `cron`, `priority`, `next_due_at`, `retry_status`, `last_exit`.
- `scheduler add/edit` validate cron expression and priority.
- `scheduler preview` (dry-run) computes due tasks (optionally within `window` minutes) and returns an ordered list without executing commands.

## `cron` Command Flow

1. Record invocation timestamp.
2. Fetch due tasks via `SchedulerService::dueTasks(now)`.
3. Iterate in priority order:
   - Execute command.
   - Capture exit code, runtime, stdout/stderr summary.
   - Update task record (timestamps, retry counter, last message).
4. Provide aggregated summary at the end (e.g. `3 tasks executed (2 success, 1 retry queued)`).
5. Any uncaught exception/timeouts in a job mark it as failed; the scheduler continues with remaining tasks.

### Timeout Handling
- Reuse existing command runner infrastructure; if the process itself times out/exits non-zero, treat as failure.
- Since the scheduler runs sequentially, there is no partial state to clean up; failures merely increment `retry_count`.

## Dry-Run / Preview Considerations
- Instead of per-command dry-run support, the `scheduler preview` command simulates scheduling without execution:
  - Returns tasks that *would* run now (or within an optional window).
  - Displays their calculated `next_due_at`, priority, retries left, and any warning (e.g. “retry pending despite cron window not reached”).
- This meets the “preview” need without enforcing dry-run logic inside every module command.

## UIAgent / Studio Integration (Future Hook)
- UIAgent can wrap `scheduler add/edit/list/preview` to translate messages or provide GUI-specific formatting.
- No module-level hook changes required; scheduler exposes structured data for UIAgent to reformat.

## Testing Strategy
1. Unit tests for cron parser/evaluator (boundary cases, steps, ranges, invalid expressions).
2. Scheduler service tests:
   - Due/overdue detection.
   - Priority ordering.
   - Retry escalation and reset logic.
3. CLI integration tests (e.g. `scheduler add` + `schedule preview` + `cron` execution pipeline).

## Implementation Plan (High-Level)
1. Extend `BrainRepository` to store new scheduler fields (cron, priority, retries, timestamps).
2. Build cron parsing/evaluation utility (shared between CLI preview and runtime).
3. Update SchedulerAgent CLI commands (add/edit/list/preview) and documentation.
4. Modify `cron` command implementation to respect priority/cron/retry flow.
5. Add tests + docs (README, developer manual, NOTES).
