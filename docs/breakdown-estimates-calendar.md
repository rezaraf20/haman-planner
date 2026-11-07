# Task breakdown, estimates from history, calendar month/agenda

## Break a task into steps
Task menu → "Break into steps" (also the "n of m subtasks" chip on a task row).
- `GET /api/tasks/{task}/breakdown` — current subtasks and whether AI is available.
- `POST /api/tasks/{task}/breakdown` — AI **proposal only** (2–8 steps). Needs `ai_planner` and one `ai_requests` unit (refunded on provider failure). The answer is validated: tags stripped, duplicates and a step equal to the task itself dropped, minutes outside 5–480 become empty. Logged in `ai_interactions` (intent `TASK_BREAKDOWN`). When the user has enough history, the suggested minutes are calibrated with their measured estimate factor. Nothing is saved. 503 with a readable message when AI is off or fails; the modal still works for adding steps by hand.
- `POST /api/tasks/{task}/subtasks` {steps: [{title, estimated_minutes}], update_parent_estimate} — creates the confirmed steps as subtasks (all or nothing, max 12) with the parent's area, goal, project, milestone and importance, through the normal task path (plan limits, activity log, priority engine). The parent's estimate changes only when the user ticks the box. Closed parents are rejected.
- Task lists include `subtasks_total`, `subtasks_done` and `parent {id, title}`.

## Estimate from history
Task form → "Suggest from history" under the estimate field. `POST /api/estimate` {title, task_id?, project_id?, tag_ids?, estimated_minutes?}. Read-only.
1. `similar` — at least 3 of the user's completed tasks (last 180 days, with actual time) share title words / project / tags → median actual minutes, with range and examples.
2. `factor` — the task has an estimate and the user's estimate accuracy is measured (enough samples) → estimate × factor.
3. Otherwise no suggestion (`not_enough_history` or `estimate_matches_history`).
The value is put into the form only when the user clicks "Use"; saving is still theirs.

## Calendar
Views: day, week, **month**, **agenda** (choice remembered in the browser).
- Month follows the user's calendar (Jalali months for Persian, Gregorian for English), shows scheduled work, open deadlines (⏰) and a capacity bar per working day; tapping a day opens it.
- Agenda lists the next 14 days: blocks, busy events and unscheduled deadlines, with scheduled vs. free time per day.
- `GET /api/schedule?...&deadlines=1` adds open tasks due in the range (max range still 42 days).
- Block resize (drag the bottom edge in day/week) already existed and was re-verified.
