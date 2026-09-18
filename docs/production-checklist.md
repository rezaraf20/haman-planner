# Production Checklist

Copyright (c) 2026 Reza Rafiei. All rights reserved.

- [ ] HTTPS configured.
- [ ] APP_KEY generated and stored outside Git.
- [ ] Strong APP_API_TOKEN configured.
- [ ] PostgreSQL is private.
- [ ] Database backups configured and restore tested.
- [ ] Telegram webhook secret configured.
- [ ] AI/STT keys stored as deployment secrets.
- [ ] Scheduler runs every minute.
- [ ] Storage and bootstrap/cache permissions verified.
- [ ] /api/health and /api/ready monitored.
- [ ] Logs do not contain secrets.
- [ ] Rate limits verified.
- [ ] CI green on the release commit.
- [ ] Smoke test: create, schedule, complete task.
- [ ] Smoke test: reminder delivery.
- [ ] Proprietary LICENSE retained.
