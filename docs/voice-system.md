# Voice System

**Author:** Reza Rafiei

## Pipeline
1. Telegram receives voice.
2. Speech-to-text produces transcript.
3. Intent parser returns structured JSON.
4. Entity resolver maps names to IDs.
5. Validator checks required fields and ambiguity.
6. Confirmation is requested for risky mutations.
7. Planner service executes the command.
8. Activity log records the operation.
9. Telegram returns a concise result.

## Intent examples
CREATE_GOAL, UPDATE_GOAL, CREATE_PROJECT, UPDATE_PROJECT, CREATE_MILESTONE, CREATE_TASK, UPDATE_TASK, COMPLETE_TASK, DEFER_TASK, CANCEL_TASK, SCHEDULE_TASK, RESCHEDULE_TASK, ADD_REMINDER, LOG_TIME, LOG_PROGRESS, LOG_BLOCKER, LOG_FAILURE, DAILY_REVIEW, WEEKLY_REVIEW, QUERY_PLAN, QUERY_PROGRESS, QUERY_REPORT, QUERY_GOAL.

## Confirmation policy
Require confirmation when an operation can materially change deadlines, cancel work, delete data, reschedule multiple tasks, or create a large batch of records.

## Entity resolution
Resolve exact names first, then aliases, then contextual matches. If confidence is insufficient, ask a clarification question instead of guessing.

## Reliability
Keep the original transcript and parsed intent. Store provider/model metadata and parsing confidence. Never execute arbitrary text as a database command.