FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libicu-dev \
    && docker-php-ext-install pdo pdo_pgsql zip intl opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .

RUN printf '%s\n' \
    '#!/usr/bin/env bash' \
    'set -e' \
    'cd /var/www/html' \
    'if [ ! -d vendor ]; then composer install --no-interaction --prefer-dist; fi' \
    'if [ -z "${APP_KEY}" ] && ! grep -q "^APP_KEY=base64" .env 2>/dev/null; then php artisan key:generate --force || true; fi' \
    'php artisan migrate --force || true' \
    'php artisan serve --host=0.0.0.0 --port=8000' \
    > /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000
CMD ["/usr/local/bin/entrypoint.sh"]
