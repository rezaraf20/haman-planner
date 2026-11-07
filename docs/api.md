# Haman Planner API

Copyright (c) 2026 Reza Rafiei. All rights reserved.

All protected endpoints require:

`Authorization: Bearer <APP_API_TOKEN>`

## Public

- `GET /api/health` — process health.
- `GET /api/ready` — application and database readiness.
- `POST /api/telegram/webhook` — Telegram webhook; production requires `X-Telegram-Bot-Api-Secret-Token`.

## Tasks

- `GET /api/tasks`
- `POST /api/tasks`
- `GET /api/tasks/{task}`
- `PATCH /api/tasks/{task}`
- `DELETE /api/tasks/{task}`

Task creation/update supports title, description, hierarchy IDs, status, priority, importance, weight, progress, estimated/actual minutes, planned start/end, deadline, energy and focus levels.

## Planning

- `GET /api/planner/today`
- `GET /api/planner/analytics`

## Dependencies and execution

- `POST /api/tasks/{task}/dependencies`
- `DELETE /api/tasks/{task}/dependencies/{dependency}`
- `POST /api/tasks/{task}/execution-logs`

## Reminders

- `GET /api/reminders`
- `POST /api/reminders`
- `POST /api/reminders/{reminder}/cancel`
- `DELETE /api/reminders/{reminder}`

## Focus, habits, rituals, tags, breakdown

- Focus: `GET /api/focus`, `POST /api/focus/start`, `POST /api/focus/{session}/pause|resume|finish|cancel`, `POST /api/focus/settings`, `GET /api/focus/sessions`, `GET /api/focus/stats` — see docs/focus-and-habits.md
- Habits: `GET/POST /api/habits`, `GET/PUT/DELETE /api/habits/{habit}`, `POST /api/habits/{habit}/check`, `POST /api/habits/{habit}/{action}`
- Today page: `GET /api/planning/today`, `POST /api/planning/layout`
- Rituals: `GET /api/rituals`, `GET|POST /api/rituals/morning`, `GET|POST /api/rituals/evening`, `POST /api/rituals/{morning|evening}/skip` — see docs/daily-rituals.md
- Tags and filters: `GET/POST /api/tags`, `PUT/DELETE /api/tags/{tag}`, `GET/POST /api/filters`, `PUT/DELETE /api/filters/{id}`, `POST /api/filters/parse`; `GET /api/tasks?smart=…|filter=…|tag=…` — see docs/tags-and-filters.md
- Breakdown and estimates: `GET|POST /api/tasks/{task}/breakdown`, `POST /api/tasks/{task}/subtasks`, `POST /api/estimate` — see docs/breakdown-estimates-calendar.md
- Calendar: `GET /api/schedule?from&to[&deadlines=1]` (max 42 days)

## Commands and reviews

- `POST /api/commands`
- `GET /api/reviews`
- `POST /api/reviews/generate`

## Rate limits

Protected API endpoints: 120 requests/minute per limiter key.

Telegram webhook: 30 requests/minute.

## Error contract

Validation errors use Laravel's standard HTTP 422 JSON response. Authentication failures return HTTP 401. Readiness failures return HTTP 503.

AI and Telegram failures must never expose provider secrets or raw credentials to clients.
