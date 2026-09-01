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

# Demo seeding, and the two conditions it needs.
#
# The seeder creates admin@, provider@ and student@scholarzim.co.zw with a
# password published in the README, and it uses updateOrCreate - so a restart
# does not skip existing accounts, it resets them back to those credentials.
# Against a production database on a public URL that is an administrative
# takeover waiting to be typed in, and it would happen on every deploy.
#
# So the flag alone is not enough: production refuses regardless of it. That
# way a misconfigured dashboard variable, a copied blueprint, or a stray
# SCHOLARZIM_DEMO_SEED=true in the environment cannot seed a live instance.
# DatabaseSeeder repeats the check for anyone who runs `php artisan db:seed`
# by hand, where this script is not involved at all.
if [ "${SCHOLARZIM_DEMO_SEED:-false}" = "true" ]; then
    if [ "${APP_ENV:-production}" = "production" ]; then
        echo "SCHOLARZIM_DEMO_SEED is set but APP_ENV=production - refusing to seed demo accounts."
    else
        php artisan db:seed --force --no-interaction
    fi
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
