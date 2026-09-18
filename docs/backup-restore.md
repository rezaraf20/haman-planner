# Backup and Restore

Copyright (c) 2026 Reza Rafiei. All rights reserved.

Haman Planner stores operational data in PostgreSQL. Backups are part of production readiness.

## Backup

Run from a trusted host with PostgreSQL client tools:

`pg_dump --format=custom --no-owner --file=haman-planner-$(date +%F).dump "$DATABASE_URL"`

Keep backups encrypted, outside the application host when possible, and use a retention policy.

Recommended baseline:
- daily backups
- at least 7 daily copies
- at least 4 weekly copies
- periodic off-site copy
- quarterly restore verification

Never commit dumps, credentials, or .env files to Git.

## Restore

Stop application writes, create a clean database, and restore:

`pg_restore --clean --if-exists --no-owner --dbname="$DATABASE_URL" haman-planner-YYYY-MM-DD.dump`

Run:

`php artisan migrate:status`

Then verify `GET /api/ready` and perform a functional task/reminder smoke test.

## Recovery rule

A backup is not considered valid until a restore has been successfully tested.
