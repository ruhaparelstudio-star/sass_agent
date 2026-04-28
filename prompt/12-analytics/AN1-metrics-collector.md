# AN1 — Metrics Collector

## Claude Code Execution Contract

You are working as a senior Laravel modular monolith engineer and AI workflow-system engineer.

Read ONLY:
1. `CLAUDE.md`
2. `PART_A_PRD.md`
3. `PHASE_CONTEXT_MATRIX.md`
4. This current phase file
5. Existing code files directly related to this phase

Do NOT read the full PRD/TDD unless explicitly instructed.
Do NOT implement other lanes.
Do NOT implement future cards.
Do NOT create speculative architecture.

Execution mode:
1. Summarize this phase goal.
2. List exact files/modules expected to be touched.
3. List database tables/migrations involved.
4. List DTOs/enums/services/policies involved.
5. Identify tests first.
6. Show boundary risks and anti-drift checks.
7. WAIT for approval.
8. Write/update tests first.
9. Implement minimal code to pass tests.
10. Run targeted tests.
11. Run broader tests only if shared code changed.
12. End with the required Phase Report from `CLAUDE.md`.

Global product rules:
- This is not a CRUD app.
- This is a controlled AI workflow engine.
- LLM output is never final authority.
- Controllers must be thin.
- Tenant isolation is mandatory.
- Validators and policy layers must not be bypassed.
- No tenant-specific hardcoded package names.

## Goal

Create analytics snapshot model and metric aggregation services.

## Scope

- Read-only metrics where possible
- Do not mutate conversation decisions
- Tenant dashboard tenant-scoped
- Superadmin can view aggregate

## Tables

- `analytics_snapshots`

## Suggested Modules

- `Analytics`
- `Audit`

## Tests First

- Lead count correct
- Handoff count correct
- Booking action count correct
- Token usage grouped by tenant

## Exit Criteria

Metrics are reliable and tenant-safe.
