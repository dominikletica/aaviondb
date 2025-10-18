# ExportAgent Module (DRAFT)

> Status: Draft – placeholder for export subsystem documentation.

## Responsibilities
- Provide `export {project} [entity[,entity[@version|#hash]]]` commands.
- Generate JSON slices for brains, projects, or targeted entities.
- Manage export presets/destinations and emit telemetry.

## Outstanding Tasks
- [ ] Detail parser rules for optional selectors and wildcards.
- [ ] Describe export file layout + integrity guarantees.
- [ ] Plan integration with SchedulerAgent for asynchronous exports.
