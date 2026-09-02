#!/bin/sh
set -e

cd /var/www/html

# APP_KEY is required before anything touches the session or encrypter. In
# production it should come from the environment; this only covers the case
# where the platform has not been given one yet.
#
# The key is generated into the environment, not written to a file. The obvious
# `key:generate --force` does not work here: it edits .env, and there is no .env
# in the image (.dockerignore excludes it), so it failed, the `|| true` swallowed
# the failure, and the container carried on with no key at all - after printing a
# line claiming it had generated one. Every request then died on
# MissingAppKeyException, including the health check, which is at least loud;
# what was not loud was the log saying the opposite of what had happened.
#
# Exporting is enough to reach everything that matters: config:cache below bakes
# the value into the cached config, and php-fpm is configured with clear_env=no
# so workers inherit it either way.
if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    APP_KEY="$(php artisan key:generate --show --no-interaction)"
    export APP_KEY

    echo "APP_KEY was not provided; generated an ephemeral one for this container."
    echo "  Sessions end at the next restart, and separate instances would not share it."
    echo "  Set APP_KEY in the platform environment for anything that outlives a deploy."
fi

# Put the database CA somewhere the web worker can actually read it.
#
# Render mounts Secret Files as root-only (-rw------- root root), and everything
# in this script runs as root - so `migrate` opens the CA happily and the deploy
# looks healthy. php-fpm then serves requests as www-data, which cannot read it,
# and every request that touches the database dies on
#
#     PDOException: failed loading cafile stream: `/etc/secrets/aiven-ca.pem'
#     SQLSTATE[HY000] [2002] Cannot connect to MySQL using SSL
#
# The failure is invisible from the deploy log: migrations report success, the
# health check does not touch the database and returns 200, the platform calls
# the service live - and every real page is a 500.
#
# So the certificate is copied to a path owned by www-data and mode 400, and the
# app is pointed at the copy. This is not a weakening of TLS: it is the same CA,
# still verified. A CA certificate is a public document - it is what the server
# presents to prove itself - so a copy readable by the one account that needs it
# discloses nothing. The credential is DB_PASSWORD, which is untouched.
#
# Done before config:cache below, so the cached config carries the new path.
if [ -n "${MYSQL_ATTR_SSL_CA:-}" ] && [ -f "${MYSQL_ATTR_SSL_CA}" ]; then
    readable_ca=/var/www/html/storage/certs/db-ca.pem

    mkdir -p "$(dirname "${readable_ca}")"
    cp "${MYSQL_ATTR_SSL_CA}" "${readable_ca}"
    chown www-data:www-data "${readable_ca}"
    chmod 400 "${readable_ca}"

    if [ "${MYSQL_ATTR_SSL_CA}" != "${readable_ca}" ]; then
        echo "Database CA copied from ${MYSQL_ATTR_SSL_CA} to ${readable_ca} for the web worker."
    fi

    MYSQL_ATTR_SSL_CA="${readable_ca}"
    export MYSQL_ATTR_SSL_CA
elif [ -n "${MYSQL_ATTR_SSL_CA:-}" ]; then
    # Named but absent is worth saying out loud: the connection will be refused
    # rather than downgraded, and the reason should not have to be guessed.
    echo "WARNING: MYSQL_ATTR_SSL_CA is set to ${MYSQL_ATTR_SSL_CA} but no such file exists."
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
