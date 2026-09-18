# API Specification

**Author:** Reza Rafiei

## Resource groups
/auth
/areas
/goals
/projects
/milestones
/tasks
/dependencies
/plans
/schedule
/execution
/reminders
/reviews
/analytics
/ai

## Conventions
- JSON request/response.
- ISO-8601 timestamps.
- Explicit timezone handling.
- Stable resource IDs.
- Pagination for collections.
- Validation errors use structured fields.
- Mutations return the resulting resource and audit identifier where applicable.

## Authentication
Use token/session authentication appropriate to the deployment. Authorization must be enforced server-side for every protected resource.

## Idempotency
External commands that may be retried should support an idempotency key to prevent duplicate task creation, reminders or logs.