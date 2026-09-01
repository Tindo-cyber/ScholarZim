# Database CA certificates

Where a managed MySQL provider's CA certificate goes for **local development**.

Put the Aiven CA here as `aiven-ca.pem` and point the environment at it:

    MYSQL_ATTR_SSL_CA=storage/certs/aiven-ca.pem

The path may be relative — `config/database.php` resolves it against the project
root, so it works from `php artisan` and from php-fpm alike.

Everything in this directory except these two files is gitignored, and the
directory is excluded from the Docker build context, so a certificate dropped
here cannot be committed or baked into an image by accident.

## Production

Nothing here is used in production. On Render the certificate is uploaded as a
**Secret File**, which the runtime mounts at `/etc/secrets/`, and
`MYSQL_ATTR_SSL_CA` is set to that absolute path instead. See docs/DEPLOYMENT.md.

## A note on what is and is not secret

A CA certificate is a public document — it is what the server presents to prove
itself, and publishing it leaks nothing. It is kept out of the repository for
tidiness and because it is per-project configuration, not because it is a
credential. The credential is `DB_PASSWORD`, which never appears in a file that
is committed.
