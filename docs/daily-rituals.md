# Morning planning and evening shutdown

Both rituals are optional. They are offered on the Today page on working days only (morning: from the start of the workday for 4 hours; evening: from one hour before the end of the workday), can be skipped for the day, and can be turned off in Settings → Planning (`preferences.rituals_enabled`). They can always be opened from the button in the Today header.

## Data
Table `day_rituals` (migration `2026_11_07_000047`, additive): one row per user, date and kind (`morning` | `evening`), `status` completed | skipped, `energy` / `mood` (1–5), `intention`, `wins`, `notes`, `top_task_ids` (≤ 3), `tomorrow_task_ids` (≤ 3), `moves` (what the user chose for each task), `summary` (evening, computed by the server). Owned through `BelongsToPlannerUser`.

## Morning (`GET/POST /api/rituals/morning`)
1. Check-in: energy and an optional intention; yesterday's completed count and logged time.
2. Leftovers: open tasks planned or due before today. For each: today, tomorrow, a date, or leave it.
3. Top 3: up to three tasks. Last night's picks come first, then today's work, then the ranking used by "what now".
4. Capacity: free time today vs. the estimate of work due/planned today (including tasks moved to today in step 2). Optionally opens Haman's day planner after saving.

The chosen top 3 replace the ranked list in the Today "top 3" widget for that day.

## Evening (`GET/POST /api/rituals/evening`)
1. Your day: completed tasks, logged time, completed focus sessions, habits, top-3 follow-through.
2. Unfinished: open tasks due or planned today, plus unfinished top-3 picks. Tomorrow / a date / leave it, with an optional reason from the existing failure reasons.
3. Reflect: mood, what went well, a note.
4. Tomorrow: up to three tasks, suggested the next morning.

## Rules
- Nothing changes until the final button. Moves only change `planned_start` (and `planned_end`, keeping its length); deadlines are never changed. Moves are logged as `scheduled` / `rescheduled`; reasons go through `PlannerService::logFailure` (activity `failure_logged`), so they appear in failure patterns.
- Only the user's own open tasks are accepted (422 otherwise); dates before today are rejected.
- Skipping never overwrites a completed ritual. `POST /api/rituals/{morning|evening}/skip`.
- `GET /api/rituals?days=14` lists recent rituals and the current prompt.

## Weekly review
`metrics_json.rituals`: mornings, evenings, skipped, average energy and mood, top-3 picked / done the same day / rate, and the week's wins. A recommendation (`smaller_top3`) appears when at least 6 top-3 picks were made and fewer than half were finished the same day. The free-text wins are shown to the user but never sent to the AI summary (only their count).

## Not included
No Telegram flow for the rituals yet (the bot is unchanged).
