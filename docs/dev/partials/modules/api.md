# ApiAgent Module (DRAFT)

> Status: Draft – placeholder for REST interface orchestration details.

## Responsibilities
- Control REST availability (`api serve/stop/status/reset`).
- Validate readiness (active tokens, bootstrap state) before enabling.
- Surface REST diagnostics (rate, last request, auth state).

## Outstanding Tasks
- [ ] Document command syntax + expected outputs.
- [ ] Describe coordination with `AuthManager`/`BrainRepository`.
- [ ] Capture failure handling (e.g. API already enabled, missing tokens).

