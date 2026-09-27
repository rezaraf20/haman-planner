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
