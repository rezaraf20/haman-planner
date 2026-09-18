# Observability

Copyright (c) 2026 Reza Rafiei. All rights reserved.

## Signals

Monitor:
- HTTP 5xx rate
- readiness failures
- Telegram delivery failures
- AI provider failures
- speech transcription failures
- reminder backlog
- failed jobs
- database connectivity
- scheduler execution

## Logs

Application logs should be structured and retained according to the deployment environment. Never log API keys, bearer tokens, Telegram bot tokens, authorization headers, raw voice credentials, or complete AI provider request headers.

Activity logs record important planner mutations with before/after state.

## Health checks

Use:
- `GET /api/health` for process-level health.
- `GET /api/ready` for database readiness.

## Incident response

1. Check readiness.
2. Check recent application errors.
3. Check scheduler and reminder state.
4. Check provider availability.
5. Preserve relevant request IDs/log context.
6. Rotate exposed secrets immediately.
