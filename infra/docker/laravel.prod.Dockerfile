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

# ── 1. The platform, defined once ────────────────────────────────────
#
# The version must match the one composer.lock was resolved against. A lock
# produced on 8.4 can pin packages that refuse to load on 8.3, and Composer
# writes a platform_check.php that enforces it — the build fails at
# dump-autoload rather than warning.
#
# Override at build time if your lock targets another version:
#   docker compose build --build-arg PHP_VERSION=8.3 api
#
# Both later stages build FROM this one, so the PHP that resolves the
# dependencies is byte-for-byte the PHP that runs them. Installing on one
# version and running on another is the failure this structure removes.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-fpm-alpine AS base

RUN apk add --no-cache \
        nginx supervisor postgresql-dev libzip-dev icu-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql zip intl opcache bcmath \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS \
    && rm -rf /var/cache/apk/* /tmp/pear

# ── 2. Composer dependencies, resolved on the runtime platform ───────
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY apps/api/composer.json apps/api/composer.lock* ./

# --no-scripts: artisan is not available yet at this stage.
RUN composer install \
        --no-dev --no-scripts --no-autoloader \
        --prefer-dist --no-interaction --no-progress

# ── 3. Runtime ───────────────────────────────────────────────────────
FROM base AS runtime

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
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# One directory per line, deliberately.
#
# Docker runs RUN through /bin/sh, which on Alpine is busybox ash — and ash
# does not expand braces. `mkdir -p storage/framework/{cache,sessions}` there
# creates a single directory literally named "{cache,sessions}", the build
# succeeds, and Laravel fails at runtime looking for a cache path that does
# not exist. Bash-isms in a Dockerfile fail quietly, which is the worst way.
RUN mkdir -p \
        storage/app/public \
        storage/app/private \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
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
