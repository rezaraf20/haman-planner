# Haman Planner Deployment

Copyright (c) 2026 Reza Rafiei. All rights reserved.

## Docker

1. Copy .env.example to .env.
2. Set APP_KEY, APP_URL, database credentials, and a strong APP_API_TOKEN.
3. Set AI_API_KEY only if AI features are enabled.
4. Run: docker compose up -d --build

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
