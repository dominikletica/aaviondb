# 📚 AavionDB User Manual

> This handbook explains how to work with AavionDB from a user perspective.  
> It avoids technical jargon where possible and links to the developer reference whenever you want to dive deeper.

---

## 📘 How to Use This Manual

Each section focuses on a typical task. Read them in order when you are new to the framework or jump directly to the topic you need.

1. [Getting Started](sections/getting-started.md) – Install, initialise, and run your first command.  
2. [Core Concepts](sections/core-concepts.md) – Understand brains, projects, entities, and versions.  
3. [Project Management](sections/projects.md) – Create, update, and clean up projects.  
4. [Entity Lifecycle](sections/entities.md) – Save data, track versions, and restore older states.  
5. [Schemas & Validation](sections/schemas.md) – Define fieldsets, lint JSON Schema, and attach them to entities.  
6. [Exports & Presets](sections/exports.md) – Produce context packages for LLMs and other tools.  
7. [Automation & Scheduler](sections/automation.md) – Run commands on a schedule via CLI or REST.  
8. [Security & Access](sections/security.md) – Control API tokens, rate limits, and lockdowns.  
9. [Configuration & Diagnostics](sections/configuration.md) – Tweak settings, read config values, and inspect runtime status.  
10. [Performance & Cache](sections/performance.md) – Keep the system fast with smart caching.  
11. [Troubleshooting & FAQ](sections/troubleshooting.md) – Solve common issues quickly.

If you need a list of available commands, see [Command Cheat Sheet](sections/troubleshooting.md#quick-reference) or the technical reference in [`../dev/commands.md`](../dev/commands.md).

---

## 🔗 Helpful Shortcuts

- [README.md](../README.md) – Manuals start page, product overview and quick start.
- [Developer Manual](../dev/MANUAL.md) – Deep technical reference for contributors.
- [Commands Reference](../dev/commands.md) – Overview of all available commands.

---

## 🧭 Keeping the Manual Up to Date

- Update this user manual whenever a command behaviour changes or a new feature becomes available to end users.
- Use clear, friendly language. Document each command with at least one example (CLI, REST, or PHP).
- Cross-reference the developer manual if a topic requires deeper technical context.

Need more detail about how the framework works internally? Head over to the [Developer Manual](../dev/MANUAL.md).
