# Environment Notes

## Paths & Interpreters
- php: /opt/homebrew/bin/php
- python3: /usr/bin/python3
- composer: /opt/homebrew/bin/composer
- node: /opt/homebrew/bin/node
- npm: /opt/homebrew/bin/npm

## Project Directories
- Repository root: /Users/letica/Library/Mobile Documents/com~apple~CloudDocs/Repository/aaviondb
- System modules:  system/modules
- User documentation: docs/
- Default export output: user/exports

## Helpful CLI patterns
- List presets: php cli.php "preset list"
- Show preset: php cli.php "preset show <slug>"
- Export manually: php cli.php "export project entity1,entity2@5"
- Lint PHP file: php -l <path>

## References
- Composer dependencies: composer.json (composer.lock if generated)
- Node dependencies: package.json / package-lock.json (not present)
- Roadmap notes: .codex/NOTES.md, .codex/2025-10-22-roadmap5-prep.md

## Toolbox
Reusable scripts should live in `.codex/toolbox/`. Add helpers here (e.g., preset validators, layout renderers) to avoid re-writing shell snippets.
