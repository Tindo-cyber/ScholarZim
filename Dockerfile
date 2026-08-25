# Single image serving the ScholarZim Laravel app: nginx in front of PHP-FPM,
# with the scheduler running alongside so the daily reminder jobs still fire.

# ── 1. Composer dependencies ───────────────────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /app
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
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ── 3. Runtime ─────────────────────────────────────────────────────────────
FROM php:8.3-fpm-alpine

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
RUN chmod +x /usr/local/bin/entrypoint

# storage/ and bootstrap/cache must be writable by the FPM worker; storage/app
# holds uploaded documents and is normally a mounted volume in production.
RUN mkdir -p storage/framework/{cache/data,sessions,views} storage/logs storage/app \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
