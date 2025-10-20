# Getting Started

This guide walks you through the first steps with AavionDB. All commands work the same in the CLI, over REST, and from PHP.

---

## 1. Requirements

- PHP 8.1 or newer
- Composer (for installing dependencies)
- File system access to the repository

---

## 2. Installation

1. Clone or download the repository into your project.
2. Install dependencies:

```bash
composer install
```

3. Run the setup command (creates the default brain and system folders):

```bash
php api.php setup
```

---

## 3. Verify the Installation

Check the framework status to confirm the active brain and available modules:

```bash
php cli.php "status"
```

Example output:

```json
{
  "status": "ok",
  "action": "status",
  "data": {
    "framework": "AavionDB",
    "version": "dev",
    "active_brain": "default",
    "modules": ["brain", "project", "entity", "..."]
  }
}
```

---

## 4. Your First Project

Create a project with title and description:

```bash
php cli.php "project create storyverse title=\"Story Verse\" description=\"Shared world bible\""
```

List all projects in the active brain:

```bash
php cli.php "list projects"
```

REST example:

```bash
curl -H "Authorization: Bearer <token>" \
  "https://example.test/api.php?action=list&scope=projects"
```

PHP example:

```php
require __DIR__ . '/aaviondb.php';

$response = AavionDB::run('list', ['scope' => 'projects']);
```

---

## 5. Create Your First Entity

Save an entity with initial content:

```bash
php cli.php 'save storyverse hero {"name":"Aria","role":"Pilot"}'
```

Show the entity:

```bash
php cli.php "show storyverse hero"
```

---

## 6. Next Steps

- Continue with [Core Concepts](core-concepts.md) to learn how brains, projects, and entities interact.
- Read [Entity Lifecycle](entities.md) for versioning, partial updates, and schema validation.
- Configure exports via [Exports & Presets](exports.md) to share your data with LLMs.

You are now ready to work with AavionDB! Keep this manual nearby as a quick reference.
