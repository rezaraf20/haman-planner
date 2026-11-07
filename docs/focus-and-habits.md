# Focus Mode and Habits

Status: **implemented and tested** (`tests/Feature/FocusAndHabitsTest.php`).

## Focus Mode (Pomodoro)

- Web: sidebar → *Focus* (`#focus`). Presets 25/5 and 50/10, or custom lengths (Focus settings).
  Long break after N completed sessions (default 4). Start, pause, resume, finish, cancel.
  Distraction-free full-screen view; a small timer pill stays in the top bar on every page.
- Start a session for a task from the task's "⋯" menu or from *Today* → "Start focus".
- Table `focus_sessions`. Time is computed from server timestamps minus paused time, so reloads,
  second tabs and closed laptops never lose or double-count time.
- **One time-tracking system:** finishing a session linked to a task writes one execution log via
  `PlannerService::logTime` (same as the bot and the "Log work" form) and adds to the task's actual
  minutes. Finishing twice is a no-op; sessions under one minute and cancelled sessions log nothing.
- Only one active session per user; a retried start (`client_uuid`) returns the same session.
- `planner:focus-cleanup` (hourly) closes sessions left running > 12 h as *interrupted* without
  logging time against the task.
- Settings live in `users.preferences` (`focus_minutes`, `focus_break_minutes`,
  `focus_long_break_minutes`, `focus_long_break_every`, `focus_sound`, `focus_notify`).
- API: `GET /api/focus`, `POST /api/focus/start`, `POST /api/focus/{id}/{pause|resume|finish|cancel}`,
  `GET /api/focus/sessions`, `GET /api/focus/stats?days=7`, `POST /api/focus/settings`.

## Habits

- Web: sidebar → *Habits* (`#habits`); widget on *Today*; Telegram bot → "🌱 Today's habits".
- Tables `habits` and `habit_entries` (one entry per habit per local day). Independent of recurring tasks.
- Types: build / reduce; binary (done / not done) or numeric (target for build, daily maximum for reduce).
- Schedules: every day, specific weekdays, N times per week, every N days; start date; pause/resume
  (paused days are stored as ranges and are never "missed"); archive/restore; delete (with history).
- Encouraging rules: today is "pending", never "missed"; unscheduled and paused days never break a
  streak; weekly habits are judged per week and an unfinished week never breaks the streak.
- Stats: current/longest streak, this-week and 30-day consistency, 12-week heatmap (per habit and
  overall), the weekdays that slip most (last 8 weeks), notes.
- All dates are the user's local calendar days (timezone-aware); weeks start Saturday for Persian
  and Monday for English users.
- Plan limit `active_habits` (free: 3, paid: unlimited) — editable in Admin → Plans.
- API: `GET /api/habits` (overview), `POST /api/habits`, `GET|PUT|DELETE /api/habits/{id}`,
  `POST /api/habits/{id}/check` (`completed` | `value` | `add`, optional `date`, `note`),
  `POST /api/habits/{id}/{pause|resume|archive|unarchive}`, `GET /api/habits?archived=1`.

## Product analytics

One-time events `first_habit`, `first_habit_checkin`, `first_focus_session` appear in the Admin
funnel (no habit or task content is recorded).
