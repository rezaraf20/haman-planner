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

## Growth features in the bot
Implemented in `App\Services\Telegram\Concerns\BotGrowthFeatures` (strings in `lang/*/botx.php`), using the same services as the web app.

| Menu / button | Callback | What it does |
|---|---|---|
| 🌱 Habits | `hb`, `hbt:id`, `hba:id` | Today's habits, one-tap check-in |
| ⏱ Focus | `fc`, `fcs:task:min`, `fcp`, `fcr`, `fcf`, `fcx` | Start (optionally on a task), pause, resume, finish (logs time), cancel |
| 🌅 / 🌙 Rituals | `rit`, `rm`, `re`, `rme:n`, `rem:n`, `rmm:id:t\|n\|k`, `rmv:id:n\|k`, `rmp:id`, `rep:id`, `rmi`, `rew`, `rms`, `res`, `rsk:kind` | Morning planning and evening shutdown. Choices are kept in the chat state and saved only by `rms` / `res`. Only `planned_start` changes; deadlines never |
| 🔎 Smart lists (Tasks menu) | `sl`, `slv:preset:page`, `slt:tag:page` | Presets and the user's tags |
| Task card: 🧩 Steps | `bd:id`, `bdai:id`, `bdok:id`, `bdadd:id` | Subtasks; AI proposal created only by `bdok`; or send steps as text, one per line ("Write copy - 30") |
| Task card: ⏳ Estimate | `est:id`, `estok:id:min` | Suggestion from history; applied only by the button (5–1440 min) |

The task card also shows tags, the parent task and subtask progress.

### Scheduled messages (`planner:bot-nudges`, every minute)
- **Focus time is up** — only for sessions started from the bot (their `client_uuid` starts with `b07b07b0`), once per session.
- **Ritual reminders** — off by default; the user turns them on in Settings → Planning ("also send these reminders to Telegram"). Sent at most once per day per ritual, only on working days and in the same windows the Today page uses, and not after the ritual was done or skipped.

## Privacy
Telegram is an external transport. Do not place secrets in messages or logs. Minimize sensitive payload retention.