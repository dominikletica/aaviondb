# File Structure

> **Status:** Maintained  
> **Last updated:** 2025-10-20

```
aavionDB/
├── system/
│   ├── core.php                 # Framework façade
│   ├── Core/                    # Core services (bootstrap, registry, events, logging, modules…)
│   ├── Storage/                 # Brain persistence layer
│   ├── modules/                 # System modules (auto-discovered)
│   ├── assets/                  # Shared assets (optional, consumed by external UIs)
│   ├── templates/               # Shared templates/snippets (optional)
│   └── storage/
│       ├── system.brain         # Internal system brain (JSON)
│       └── logs/                # Monolog output (aaviondb.log, auth.log, rotated archives)
├── user/
│   ├── modules/                 # User-defined modules
│   ├── storage/                 # User brains (JSON)
│   ├── presets/
│   │   └── export/              # Optional preset import/export staging (presets persist in system brain)
│   ├── cache/                   # Cached artefacts (tagged, TTL-governed)
│   ├── exports/                 # Generated exports (optional persisted copies)
│   └── backups/                 # Brain backups created via `brain backup`
├── docs/
│   ├── README.md                # Documentation landing page (links to user/dev manuals)
│   ├── user/                    # User-facing handbook (simple language, workflows)
│   └── dev/                     # Developer reference (partials, class maps)
├── .codex/
│   ├── AGENTS.md                # Collaboration guidelines and reminder checklist
│   └── NOTES.md                 # Session log + TODO overview
├── config.example.php           # Configuration template (copy to config.php)
├── config.php                   # Optional runtime overrides (ignored by VCS)
├── api.php                      # REST/PHP entry point
├── cli.php                      # CLI entry point
├── aaviondb.php                 # PHP entry point for embedded integrations
├── CHANGELOG.md                 # Release notes
└── README.md                    # Lightweight project overview with pointers into docs/
```

`PathLocator` ensures these directories exist during bootstrap. Adjust `.gitignore` when introducing new runtime folders (e.g., log archives, cache tags, export presets). Keep directories under version control when they hold documentation or templates; runtime artefacts (cache, exports, backups) stay excluded.
