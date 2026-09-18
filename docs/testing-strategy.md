# Testing Strategy

**Author:** Reza Rafiei

## Required layers
- Unit tests for domain rules and calculations.
- Feature tests for planner workflows.
- Integration tests for repositories and provider adapters.
- Contract tests for AI intent schemas.
- Telegram command tests.
- Regression tests for scheduling and progress calculations.

## Critical invariants
- Weighted progress is mathematically correct.
- Dependencies cannot create invalid execution order.
- Completed tasks cannot be silently rescheduled.
- Actual duration is never confused with estimate.
- AI cannot directly bypass application validation.
- Unauthorized users cannot access another context/workspace.

## Quality gate
A feature is not complete until its acceptance criteria, tests and documentation are updated.