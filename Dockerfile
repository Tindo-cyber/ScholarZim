# Single image serving the ScholarZim Laravel app: nginx in front of PHP-FPM,
# with the scheduler running alongside so the daily reminder jobs still fire.

# ── 1. Composer dependencies ───────────────────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /app
# phpoffice/phpspreadsheet requires ext-gd, which the composer image does not
# ship. Without it `composer install` stops at the platform check and the image
# never builds - so the extension is installed here purely to let the dependency
# resolution run; the runtime stage installs its own copy further down.
RUN apk add --no-cache freetype-dev libjpeg-turbo-dev libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd
# Install against the manifests alone first, so a code-only change does not
# re-resolve the dependency tree on every rebuild.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev --no-scripts --no-autoloader \
        --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --optimize --no-dev

# ── 2. Front-end bundle ────────────────────────────────────────────────────
# ScholarZim's own CSS and JS are content-hashed by Vite, so a deploy cannot
# serve a stale stylesheet out of a browser cache. Only the build output is
# carried into the runtime image; node itself never ships.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ── 3. Runtime ─────────────────────────────────────────────────────────────
# 8.4, not 8.3: the locked symfony/* components require PHP >= 8.4.1, and
# composer's generated platform_check.php aborts the app on boot rather than
# limping along, so an 8.3 runtime produces an image that builds and then
# refuses to serve a single request.
FROM php:8.4-fpm-alpine

RUN apk add --no-cache nginx supervisor tzdata icu-dev libzip-dev libpng-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg 2>/dev/null || true \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring bcmath intl zip gd opcache \
    && apk del icu-dev libzip-dev libpng-dev oniguruma-dev \
    && apk add --no-cache icu-libs libzip libpng

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/scholarzim.ini
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
# nginx is started through this, so it cannot answer the platform's first
# request before php-fpm has bound its socket. See docker/supervisord.conf.
COPY docker/wait-for-fpm.sh /usr/local/bin/wait-for-fpm
RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/wait-for-fpm

# storage/ and bootstrap/cache must be writable by the FPM worker; storage/app
# holds uploaded documents and is normally a mounted volume in production.
#
# The paths are written out in full rather than as a brace list. Docker runs
# each RUN through `/bin/sh -c`, which on Alpine is busybox ash, and busybox
# does not expand braces - so `storage/framework/{cache/data,sessions,views}`
# created one literal directory called `{cache/data,sessions,views}` and none of
# the three that were meant. It looked correct in the Dockerfile and was wrong in
# the image.
#
# Two of the three self-heal and hid the mistake: Laravel's FileStore and
# BladeCompiler both call makeDirectory() before their first write, so the cache
# and compiled-view directories get created on demand. FileSessionHandler does
# not - its constructor only records the path - so storage/framework/sessions
# stayed missing and every session write failed against a directory that was
# never there.
RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
