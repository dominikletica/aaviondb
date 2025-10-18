# File Structure (DRAFT)

> **Status:** Draft  
> **Last updated:** 0.1.0-dev

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
│       └── logs/                # Monolog output (aaviondb.log, auth.log,…)
├── user/
│   ├── modules/                 # User-defined modules
│   ├── storage/                 # User brains (JSON)
│   ├── exports/                 # Generated exports for LLMs
│   └── cache/                   # Temporary runtime/cache data
├── docs/                        # Documentation (`README.md`, manual, specs)
├── config.example.php           # Configuration template (copy to config.php)
├── api.php                      # REST/PHP entry point
├── cli.php                      # CLI entry point
└── README.md                    # Project overview
```

`PathLocator` ensures these directories exist during bootstrap. Adjust `.gitignore` as new runtime folders are introduced (e.g., log rotation directories, temporary exports).
