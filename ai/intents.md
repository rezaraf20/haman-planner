# AI Intent Contract

**Author:** Reza Rafiei

## Contract
The model produces structured intent data; application code validates and executes it.

Example:
{
  "intent": "CREATE_TASK",
  "confidence": 0.96,
  "entities": {
    "title": "Prepare Haman Planner architecture",
    "project": "Haman Planner",
    "deadline": null
  },
  "requires_confirmation": false
}

## Rules
- JSON only at the provider boundary.
- Unknown fields are ignored or rejected according to schema version.
- Confidence does not replace application validation.
- IDs are resolved by the application, not invented by the model.
- Missing required fields cause clarification.
- Every executed intent receives an audit record.

## Mutation coverage

Supported mutation intents include:
- Goals: `CREATE_GOAL`, `UPDATE_GOAL`
- Projects: `CREATE_PROJECT`, `UPDATE_PROJECT`
- Milestones: `CREATE_MILESTONE`, `UPDATE_MILESTONE`
- Tasks: `CREATE_TASK`, `UPDATE_TASK`, `COMPLETE_TASK`, `DEFER_TASK`, `CANCEL_TASK`
- Scheduling: `SCHEDULE_TASK`, `RESCHEDULE_TASK`
- Reminders: `ADD_REMINDER`
- Execution logging: `LOG_TIME`, `LOG_PROGRESS`, `LOG_BLOCKER`, `LOG_FAILURE`

All mutation intents require explicit confirmation before persistence. Entity references are resolved and frozen before confirmation so approval cannot silently target a different record.
