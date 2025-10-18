# UI & Web Console (DRAFT)

> **Status:** Draft  
> **Last updated:** 0.1.0-dev

## Planned Features
- Interactive console mirroring CLI commands.  
- Tree view for projects/entities/versions.  
- Markdown rendering and documentation panels.  
- Live event/log viewer (hooked into Monolog + event bus).  
- Light/Dark themes, responsive layout.

## Integration Notes
- Assets under `system/assets/`; templates under `system/templates/`.  
- UI should authenticate using bootstrap key initially, then prompt the user to create personal keys.  
- Configuration option to choose execution mode per action:
  - Internal PHP (`AavionDB::command()`) for low-latency operations.  
  - REST request (with stored token) for debugging API flows.

## Future Enhancements
- Guided onboarding (after bootstrap) explaining `auth grant`, `api serve`, `api stop`.  
- Visual diffing for entity versions.  
- Plugin support for custom dashboards from user modules.
