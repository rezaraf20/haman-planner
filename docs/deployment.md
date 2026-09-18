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
