#!/bin/sh
set -e

cd /var/www/html

# APP_KEY is required before anything touches the session or encrypter. In
# production it should come from the environment; this only covers the case
# where the platform has not been given one yet.
if [ -z "${APP_KEY}" ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    echo "APP_KEY is not set — generating an ephemeral one (sessions will not survive a restart)."
    php artisan key:generate --force --no-interaction || true
fi

# Wait for the database before migrating; the container often starts first.
if [ "${DB_CONNECTION:-mysql}" = "mysql" ]; then
    tries=0
    until php artisan db:monitor --databases=mysql >/dev/null 2>&1 || [ "$tries" -ge 30 ]; do
        tries=$((tries + 1))
        echo "Waiting for the database (${tries}/30)..."
        sleep 2
    done
fi

if [ "${SCHOLARZIM_RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

if [ "${SCHOLARZIM_DEMO_SEED:-false}" = "true" ]; then
    php artisan db:seed --force --no-interaction
fi

php artisan storage:link --no-interaction 2>/dev/null || true

# Cached config/routes/views are what make a production boot cheap. Skipped in
# local development so edits take effect without a rebuild.
if [ "${APP_ENV:-production}" != "local" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
