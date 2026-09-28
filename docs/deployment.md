# Haman Planner Deployment

Copyright (c) 2026 Reza Rafiei. All rights reserved.

## Docker

1. Copy `.env.example` to `.env`.
2. Generate a real `APP_KEY` and set a strong `APP_API_TOKEN`.
3. Set Telegram/AI/STT credentials only for features you enable.
4. Docker Compose forces `DB_HOST=db` and uses the same `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` values for the app and PostgreSQL service.
5. Run: `docker compose up -d --build`.
6. Verify: `curl http://127.0.0.1:8000/api/health` and `curl http://127.0.0.1:8000/api/ready`.

The container runs migrations and seeders before starting Laravel.

## API

Health: GET /api/health

Protected endpoints require an Authorization Bearer token using APP_API_TOKEN.

## Production

Use HTTPS behind Nginx or another reverse proxy. Do not expose PostgreSQL publicly. Keep .env outside version control, rotate API/AI/Telegram secrets if exposed, and maintain encrypted database backups.

## Native Laravel deployment

Requirements: PHP 8.2+, Composer 2, PostgreSQL, and a web server configured to serve the public/ directory.

Commands:
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --force

Set the web document root to public/ and make storage/ and bootstrap/cache/ writable by the web user.


## Telegram and scheduler

Set `TELEGRAM_BOT_TOKEN` and a random `TELEGRAM_WEBHOOK_SECRET` in the production environment. Configure Telegram's webhook to send the secret header and use the HTTPS API endpoint.

Run Laravel's scheduler every minute in production:

```cron
* * * * * cd /var/www/haman-planner && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler executes `planner:reminders`, which dispatches due Telegram reminders.


## Upgrading an existing installation (SaaS release)

All migrations in this release only **add** tables, columns and indexes; no data is converted or removed.
Existing users keep their data, get `locale=fa`, `timezone=Asia/Tehran` and are marked as already onboarded.

```bash
cd /path/to/haman-planner
git pull                                   # or fetch the release bundle
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.prod.yml run --rm app php artisan db:seed --class=PlanSeeder --force
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear
```

Never use `migrate:fresh`, `migrate:reset`, `db:wipe` or `docker compose down -v` on production — they delete data.
Take a database backup before upgrading (see backup-restore.md).

After the upgrade:

1. Open `/admin` → *System* and check the database, queue and scheduler heartbeat.
2. Review plans and prices in `/admin` → *Plans* (seeded prices are placeholders).
3. Set `LEGAL_ENTITY_NAME` and `SUPPORT_EMAIL`, and replace the Privacy/Terms placeholders after legal review.
4. Payments stay off until `ZARINPAL_ENABLED`/`STRIPE_ENABLED` and their credentials are set. See saas.md §4.

The scheduler runs `planner:reminders` every minute and `billing:lifecycle` hourly, and writes a heartbeat
shown on the System page. The Telegram webhook URL does not change.


## Upgrading to the smart-planning / production release

What changes:

- New migrations `2026_09_29_000033` … `000041` only **add** tables, columns and indexes (recurring tasks, time
  blocks, calendar, plan proposals, attachments, webhook events, notification deliveries, activity days) and switch
  the four new plan features on for existing plans. No existing row is converted or deleted.
- New scheduled commands: `planner:recurring` (hourly), `calendar:sync` (15 min), `planner:lifecycle-emails` (hourly).
  They run in the existing `scheduler` container.
- `docker-compose.prod.yml` adds the named volume `planner_storage` mounted at `storage/app` (uploaded attachments).
- Security headers now include a Content-Security-Policy, and session cookies become `Secure` when `APP_URL` starts
  with `https://`. Make sure users open the site over HTTPS (set `SESSION_SECURE_COOKIE=false` only if you must
  serve plain HTTP).

Steps:

```bash
cd /opt/haman-planner
# 1. Backup first (see backup-restore.md), e.g.:
docker exec haman_postgres pg_dump -U haman_planner -Fc haman_planner > ~/haman_planner_$(date +%F_%H%M).dump
# 2. Update the code
git pull
# 3. Optional new settings in .env (all can stay empty): see .env.example
#    STRIPE_WEBHOOK_SECRET, GOOGLE_CALENDAR_CLIENT_ID/SECRET, FORCE_HTTPS, CSP_REPORT_ONLY, ATTACHMENTS_MAX_KB …
# 4. Rebuild and start (the app container runs migrate --force and db:seed --force on start)
docker compose -f docker-compose.prod.yml up -d --build
# 5. Check
docker compose -f docker-compose.prod.yml logs --tail=50 app
docker compose -f docker-compose.prod.yml exec app php artisan migrate:status | tail -12
curl -s http://127.0.0.1:8010/api/ready
```

After the upgrade:

1. Admin → *System*: database, queue, scheduler, storage, Stripe/Zarinpal/Google Calendar rows.
2. Optional — Stripe auto-renewal: add the webhook endpoint and signing secret (saas.md §4). Until then Stripe keeps
   working exactly as before (one payment per period).
3. Optional — Google Calendar: create an OAuth client with redirect URI
   `https://<APP_URL>/settings/calendar/google/callback` and enter it in Admin → Integrations.
4. Open the site in a browser and check the console for CSP messages; if anything custom is blocked, set
   `CSP_REPORT_ONLY=true` temporarily and report it.

Rollback: the previous release keeps working with the new (additive) schema, so `git checkout <previous>` and
rebuilding is enough; migrations do not need to be rolled back. (Stripe subscriptions created in auto-renew mode
would then no longer receive webhook updates — remove the webhook secret first if you roll back.) Never run `migrate:fresh`, `db:wipe` or
`docker compose down -v`.
