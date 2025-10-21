# SchedulerAgent Module

> **Status:** Implemented – scheduled command management and cron execution.  
> **Last updated:** 2025-10-20

## Responsibilities
- Persist scheduler configuration inside the system brain (task slug + command string).
- Provide CRUD-style commands for tasks (`scheduler add/edit/remove/list`) and expose execution logs.
- Offer a `cron` entry point (CLI/REST) that runs all registered commands without requiring authentication.

## Commands
- `scheduler list` – Lists all tasks with metadata (created/updated timestamps, last run status/message).
- `scheduler add <slug> <command>` – Creates a new task; command must be a full CLI statement handled by `AavionDB::command`.
- `scheduler edit <slug> <command>` – Updates the stored command for an existing task.
- `scheduler remove <slug>` – Deletes a task from the scheduler.
- `scheduler log [limit=20]` – Displays recent cron runs (timestamp, duration, per-task result snapshot).
- `cron` – Executes all tasks sequentially, logging outcome for each command.

## Call Flow
- `system/modules/scheduler/module.php` instantiates `AavionDB\Modules\Scheduler\SchedulerAgent` and calls `register()`.  
- `SchedulerAgent::registerParser()` handles both `scheduler ...` and the standalone `cron` verb, parsing task slugs/commands and merging optional parameters.  
- Command handlers map to repository calls:  
  - `schedulerAddCommand()` → `BrainRepository::createSchedulerTask()`  
  - `schedulerEditCommand()` → `updateSchedulerTask()`  
  - `schedulerRemoveCommand()` → `deleteSchedulerTask()`  
  - `schedulerListCommand()` → `listSchedulerTasks()`  
  - `schedulerLogCommand()` → `listSchedulerLog()` (with optional limit).  
  - `cronCommand()` → iterates stored tasks, calls `AavionDB::command()` for each, records run metadata via `recordSchedulerRun()` / `updateSchedulerTaskRun()`, and assembles the response.  
- `cronCommand()` skips authentication in REST (`api.php` short-circuits on `action=cron`) and logs per-task success/failure using the module logger.

## Key Classes & Collaborators
- `AavionDB\Modules\Scheduler\SchedulerAgent` – parser + command registrar.  
- `AavionDB\Storage\BrainRepository` – persistence and logging for scheduler tasks.  
- `AavionDB\AavionDB` facade – executes stored commands during cron runs.  
- `AavionDB\Core\Modules\ModuleContext` – provides command registry, logger, and repository access.  
- `AavionDB\Core\CommandResponse` – response envelope shared across commands and cron execution.

## Implementation Notes
- Module lives in `system/modules/scheduler`. Scheduler data is stored in the system brain (`scheduler.tasks`, `scheduler.log`).
- `BrainRepository` helpers ensure atomic updates (`createSchedulerTask`, `updateSchedulerTask`, `deleteSchedulerTask`, `listSchedulerTasks`, `recordSchedulerRun`, `updateSchedulerTaskRun`, `listSchedulerLog`).
- `cron` uses `AavionDB::command()` for each task, captures status/message, and updates task metadata (`last_run_at`, `last_status`, `last_message`).
- Logs retain the most recent 100 runs by default; `brain cleanup` with `--keep` can coexist for version retention.
- Global parser shortcuts (`scheduler`, `cron`) are registered so top-level commands route correctly across CLI, REST, and internal executions.

## Examples

### CLI
```bash
php cli.php "scheduler add nightly 'export demo --preset=nightly'"
php cli.php "cron"
php cli.php "scheduler log"
```

### REST (unauthenticated cron)
```bash
curl "https://example.test/api.php?action=cron"
```

### PHP
```php
AavionDB::run('scheduler add', ['slug' => 'nightly', 'command' => 'export demo']);
AavionDB::run('cron');
```

## Error Handling
- Duplicate slugs raise `Scheduler task "<slug>" already exists.`
- Unknown slugs for edit/remove throw `Scheduler task "<slug>" does not exist.` (propagated via `status=error`).
- Cron execution captures unexpected exceptions per task and records them in the log entry (status `error`, message set to exception text).

## Outstanding Tasks
- [ ] Extend with retention policies / dry-run mode (e.g., preview tasks without execution).
- [ ] Consider time-based scheduling (cron expressions) or priority queues.
- [ ] Add PHPUnit coverage for add/edit/remove/cron/log flows.
