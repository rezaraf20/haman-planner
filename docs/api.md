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
