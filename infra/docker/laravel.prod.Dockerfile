# ServiceProof — product core, production image.
#
# The development image runs `php artisan serve`, installs Composer packages
# at boot and migrates on every start. That is right for a laptop and wrong
# for a server: one request at a time, no opcache, and a deploy that races
# itself. This image builds the dependencies once, caches the framework, and
# serves through php-fpm behind a local nginx.
#
# Only nginx listens, on 8000, so the shared reverse proxy talks to this
# container exactly as it talks to the Next.js one.

# ── 1. Composer dependencies, resolved once ──────────────────────────
FROM composer:2 AS vendor

WORKDIR /app
COPY apps/api/composer.json apps/api/composer.lock* ./

# --no-scripts: artisan is not available yet at this stage.
RUN composer install \
        --no-dev --no-scripts --no-autoloader \
        --prefer-dist --no-interaction --no-progress

# ── 2. Runtime ───────────────────────────────────────────────────────
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        nginx supervisor postgresql-dev libzip-dev icu-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql zip intl opcache bcmath \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS \
    && rm -rf /var/cache/apk/*

# Opcache settings for a long-lived process. validate_timestamps=0 means the
# container must be rebuilt to pick up code changes — which is the point.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini \
    && { \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=32M'; \
        echo 'post_max_size=32M'; \
        echo 'expose_php=off'; \
    } > /usr/local/etc/php/conf.d/serviceproof.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
# The build context is the repository root — the image needs files from both
# apps/api and infra/production — so the application is copied by name.
# `COPY . .` here would drop the entire monorepo into the document root.
COPY apps/api/ .

# The autoloader needs the application code, so it is generated here rather
# than in the vendor stage.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY infra/production/nginx-api.conf /etc/nginx/nginx.conf
COPY infra/production/supervisord.conf /etc/supervisord.conf
COPY infra/production/api-entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r 'exit(@fsockopen("127.0.0.1", 8000) ? 0 : 1);'

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
