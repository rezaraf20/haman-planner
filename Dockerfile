FROM php:8.3-cli

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader

COPY . .

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chmod -R ug+rw storage bootstrap/cache

EXPOSE 8000
CMD ["php", "artisan", "serve", "--no-reload", "--host=0.0.0.0", "--port=8000"]
