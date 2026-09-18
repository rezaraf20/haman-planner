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