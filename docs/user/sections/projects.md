# Project Management

Projects help you organise entities into focused collections. This chapter explains everyday tasks and includes practical examples.

---

## Create a Project

```bash
php cli.php 'project create storyverse title="Story Verse" description="World bible for our sci-fi series."'
```

REST:

```bash
curl -X POST -H "Authorization: Bearer <token>" \
  -d 'command=project create storyverse title="Story Verse" description="World bible..."' \
  https://example.test/api.php
```

PHP:

```php
AavionDB::run('project create', [
    'slug' => 'storyverse',
    'title' => 'Story Verse',
    'description' => 'World bible for our sci-fi series.',
]);
```

---

## List Projects

```bash
php cli.php "list projects"
```

Returns a table of project slugs, active state, entity counts, and last change timestamps.

---

## Update Metadata

```bash
php cli.php 'project update storyverse title="Story Verse 2.0" description="Updated scope"'
```

Use this to refresh LLM hints or keep documentation in sync.

---

## Remove or Delete Projects

- **Archive:** `project remove <slug>` marks the project inactive. All data stays on disk.
- **Delete:** `project delete <slug> [purge_commits=1]` removes the project permanently. Set `purge_commits=1` to drop commit history as well.

Always create a backup (`brain backup`) before deleting data permanently.

---

## Inspect a Project

```bash
php cli.php "project info storyverse"
```

Includes entity counts, versions, and metadata summaries.

---

## Clean Up Old Versions

Use the brain cleanup command to purge archived versions:

```bash
php cli.php "brain cleanup storyverse --keep=5"
```

- Deletes inactive versions for every entity in `storyverse`.
- Keeps the latest five revisions per entity.
- Add an entity slug to target a single entity.
- Preview the effect first: `php cli.php "brain cleanup storyverse --keep=5 --dry-run"` (nothing is deleted).
- Need to tidy commit indexes or metadata afterwards? Run `php cli.php "brain compact storyverse"` to rebuild commit lookups, or `php cli.php "brain repair storyverse"` to realign active versions, statuses, and timestamps (both accept `--dry-run`).
- Before large changes, create a snapshot: `php cli.php "brain backup storyverse label=nightly --compress=1"`. List backups with `brain backups`, prune old ones via `brain backup prune storyverse --keep=5`, and restore with `brain restore storyverse--nightly-20251020_083000.brain.gz storyverse --overwrite=1`.

---

## Working with Multiple Projects

- Export commands accept CSV lists: `export storyverse,lore`
- Scheduler tasks can run project-specific commands on a schedule.

---

Next, learn how to manage the data inside your projects: [Entity Lifecycle](entities.md).
