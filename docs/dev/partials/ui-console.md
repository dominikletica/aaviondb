# UI & Web Console

> **Status:** Maintained – awaiting AavionStudio implementation  
> **Last updated:** 2025-10-20

## Planned Features
- External **AavionStudio** app providing interactive console, dashboards, and diff views.  
- Optional diagnostics-only console inside AavionDB for minimal troubleshooting.

## Integration Notes
- Shared assets/templates (if any) live under `system/assets/` and are consumed by external front-ends.  
- External UI authenticates via REST (Bearer token); bootstrap flows remain CLI-only.  
- The upcoming UiAgent will expose hooks so Studio can trigger commands, subscribe to events, and display diagnostics.

## Future Enhancements
- Live event/log streaming via WebSocket bridge.  
- Schema-aware editors and guided onboarding once AavionStudio is implemented.
