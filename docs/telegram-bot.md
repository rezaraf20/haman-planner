# Telegram Bot

**Author:** Reza Rafiei

## Commands
/start — initialize account/context
/today — today's plan
/tasks — active tasks
/goals — goals
/projects — projects
/inbox — unprocessed items
/review — daily review
/week — weekly overview
/report — analytics report
/add — create item
/remind — create reminder
/search — search planner

## Text and voice
Text commands and natural-language messages use the same Planner Application Service. Voice messages enter through the speech pipeline but converge on the same intent contract.

## UX rules
- Keep responses concise.
- Show the action taken and relevant entity.
- Never hide validation errors.
- For ambiguous entities, ask one focused clarification.
- For high-impact mutations, request confirmation.
- Provide undo/reversal where technically safe.

## Privacy
Telegram is an external transport. Do not place secrets in messages or logs. Minimize sensitive payload retention.