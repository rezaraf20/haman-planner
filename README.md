# Haman Planner

**Personal & Business Operating System**

A planning and execution system designed by **Reza Rafiei** to manage goals, projects, milestones, tasks, schedules, execution, reviews, analytics, and AI-assisted planning.

## Vision
**Goal → Project → Milestone → Task → Plan → Execute → Measure → Review → Improve**

## Principles
- Goals before tasks
- Importance is distinct from contribution weight
- Deadlines are distinct from calendar scheduling
- Measure planned vs actual effort
- Record why work failed or slipped
- AI recommendations must use real planner data
- Personal and business contexts remain separable

## Author
**Reza Rafiei**

## License
Proprietary. See [LICENSE](LICENSE).


## Current MVP capabilities

- Goal, project, milestone and task CRUD
- Weighted progress and priority calculation
- Dependency checks and dependency-aware daily planning
- Daily capacity planning with a 20% buffer
- Task execution/time logging
- Failure and blocker logging
- Daily/weekly/monthly analytics and reviews
- Telegram text and voice input
- AI intent parsing through OpenAI-compatible providers
- Explicit confirmation before planner mutations
- Entity resolution with ambiguity protection
- Task scheduling and rescheduling
- Telegram reminders with scheduled dispatch and retry backoff
- Browser dashboard at `/planner`
- Task filtering/search, notes, decisions and planner recommendations
- Grounded AI planner recommendations from stored planner data
- Request correlation IDs and idempotency protection
- Queue worker and scheduler deployment topology
- Protected API with bearer token and rate limiting
- Telegram webhook secret validation
- Database readiness endpoint (`/api/ready`)
- Real completion timestamps and schedule-variance analytics
- Audit logging for important mutations

## Production checklist

Before production, configure:
1. PostgreSQL and encrypted backups.
2. `APP_KEY` and a strong `APP_API_TOKEN`.
3. `TELEGRAM_BOT_TOKEN` and `TELEGRAM_WEBHOOK_SECRET`.
4. AI and speech provider credentials only when required.
5. HTTPS and a reverse proxy.
6. Laravel scheduler every minute.
7. Queue workers if asynchronous jobs are enabled.
8. Monitoring and log retention.

Never commit secrets to the repository.
