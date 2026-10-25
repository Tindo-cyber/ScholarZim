# ScholarZim production deployment

ScholarZim is a **Laravel 12 monolith** served by nginx + PHP-FPM in a single container. Deploy **one** web service + MySQL.

The image also supervises `php artisan schedule:run`, which is what keeps the daily deadline and profile reminder jobs firing — there is no separate worker to deploy.

---

## Recommended for FYP: Render (free / low-cost)

`render.yaml` in the repo root describes the service. Set these in the Render dashboard:

```bash
APP_ENV=production
APP_DEBUG=false

# Generate once locally with `php artisan key:generate --show` and paste the
# value here. Without a stable key every deploy invalidates all sessions.
APP_KEY=base64:...

APP_URL=https://scholarzim.onrender.com
SESSION_SECURE_COOKIE=true
SESSION_EXPIRE_ON_CLOSE=true

# Managed MySQL (see "Aiven MySQL" below)
DB_CONNECTION=mysql
DB_HOST=...
DB_PORT=3306
DB_DATABASE=defaultdb
DB_USERNAME=...
DB_PASSWORD=...

# Render blocks outbound SMTP on its free tier, so MAIL_MAILER=smtp will not
# deliver anything there — use Mailgun's HTTP API instead. Get the domain and
# API key from the Mailgun control panel.
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=...
MAILGUN_SECRET=...
MAILGUN_ENDPOINT=api.mailgun.net
MAIL_FROM_ADDRESS=noreply@scholarzim.co.zw
MAIL_FROM_NAME=ScholarZim

# FYP demo: populate sample scholarships and users on startup.
# Set to false for real production.
SCHOLARZIM_DEMO_SEED=true
```

Migrations run automatically on container start (`docker/entrypoint.sh`). Set `SCHOLARZIM_RUN_MIGRATIONS=false` to suppress that if you prefer to migrate by hand.

`2025_01_01_000020_add_registration_details_to_provider_profiles` (country of registration and
related verification fields for provider organisations) is additive: nullable columns and one index,
no rows rewritten and nothing backfilled, so it is safe to run against existing data and needs no
new environment variables. Existing providers keep working; their country shows as *Not recorded*
until an administrator records it from `/admin/providers/{id}` - see `docs/international-providers.md`.
Rolling it back (`php artisan migrate:rollback --step=1`) drops only those columns, and with them any
countries recorded since.

The health check is `/` — the public landing page. Laravel exposes no dedicated actuator-style endpoint.

### Aiven MySQL (recommended with Render)

Aiven requires TLS and verifies against its own CA, so five connection variables
and one certificate are needed. Take the first five from the service's *Connection
information* page in the Aiven console:

| Variable | Where it comes from |
|----------|---------------------|
| `DB_CONNECTION` | `mysql` (already set in `render.yaml`) |
| `DB_HOST` | Aiven service host, e.g. `mysql-….aivencloud.com` |
| `DB_PORT` | Aiven's **per-service** port — not 3306 |
| `DB_DATABASE` | `defaultdb` unless you created another |
| `DB_USERNAME` | `avnadmin` unless you created another |
| `DB_PASSWORD` | Aiven service password — dashboard only, never a file |
| `MYSQL_ATTR_SSL_CA` | Path to the CA, set in `render.yaml` to `/etc/secrets/aiven-ca.pem` |

`DB_PORT` deserves a moment: Aiven assigns a different port per service, and a
wrong one fails as a connection timeout that says nothing about ports. It has no
default in `render.yaml` for that reason — set it explicitly.

**The certificate.** Download `ca.pem` from the Aiven console ("CA certificate"),
then in the Render dashboard go to **Environment → Secret Files**, add a file named
`aiven-ca.pem`, and paste the PEM in. Render mounts secret files at
`/etc/secrets/<filename>` at runtime, which is exactly what `MYSQL_ATTR_SSL_CA`
already points at. Nothing is committed and nothing is baked into the image.

For local development against Aiven, put the same file at
`storage/certs/aiven-ca.pem` (gitignored, and excluded from the Docker build
context) and set `MYSQL_ATTR_SSL_CA=storage/certs/aiven-ca.pem` in `.env`. A
relative path is resolved against the project root, so it works under both
`php artisan` and php-fpm.

Leave `MYSQL_ATTR_SSL_CA` unset for a local MySQL: with no value the SSL attribute
is never passed to the driver and the connection behaves as it always has. With a
value, a missing or wrong certificate is **refused** rather than downgraded to
plaintext — a forgotten secret file fails loudly at boot instead of quietly
sending credentials in the clear.

Verify the connection without touching data:

```bash
php artisan tinker
>>> DB::connection()->getPdo();                               # connects, or throws
>>> DB::select('SHOW STATUS LIKE "Ssl_cipher"');              # non-empty Value = TLS is on
>>> DB::select('SELECT VERSION() AS v, DATABASE() AS db');
```

An empty `Ssl_cipher` means the connection is plaintext — check that
`MYSQL_ATTR_SSL_CA` is set and the file exists at that path.

### Where uploaded documents live

**Production (Render, `render.yaml`) stores every upload in a private Cloudflare R2 bucket**, not on the container.
`render.yaml` sets `FILESYSTEM_DISK=s3` and the `AWS_*` variables that point the S3 driver at R2
(`AWS_ENDPOINT`, `AWS_BUCKET`, `AWS_DEFAULT_REGION=us-east-1`, `AWS_USE_PATH_STYLE_ENDPOINT=true`;
`AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` are `sync: false` secrets you enter in the Render dashboard).
`App\Services\FileStorageService` reads the default disk from `config('filesystems.default')`, so no code names `s3`.
The bucket has no public access: documents are only ever served through the application, after an authorisation check.

The container filesystem on `plan: free` is ephemeral (wiped on every deploy and after the ~15-minute idle spin-down),
which is why production must **not** run with `FILESYSTEM_DISK=local`: every `*_path` column and `document_files` row
would keep pointing at files that no longer exist. The `local` disk (`storage/app`, or `FILESYSTEM_ROOT`) is what local
development, the test suite and the VPS Docker Compose stack use.

Two further points that matter when documents seem to be "missing":

- **Each upload records the disk it was written to** (`document_files.disk`). A file written before the move to R2 is
  recorded against `local`. The application checks and serves a document from its *recorded* disk first, then the
  configured default, so a legacy record is not reported missing merely because the default disk changed.
- **A document counts as uploaded only if its file can be opened.** A path in the database is not enough: the profile,
  the apply page, the submission check (`ApplicationService::submit`, which one-click Apply also uses) and the provider's
  review page all read the same availability check (`ApplicantProfile::documentChecklist()` →
  `FileStorageService::status()`). The check has three answers: *available*, *missing* (the disk answered and there is no
  such object) and *unreachable* (the disk could not be asked - bad credentials, wrong bucket, endpoint down). An
  unreachable store is reported as a storage problem (HTTP 503 on download, "document storage is not responding" on
  apply), never as a missing file, and nothing is submitted on a requirement that could not be confirmed.

#### Diagnosing "The file could not be found"

1. Look in the application log for `Could not check whether a stored file exists` - it carries the disk, driver and the
   full exception chain (the S3 error code such as `AccessDenied` / `NoSuchBucket`; never credentials). If that line is
   present the problem is the store (credentials, bucket, endpoint), not the document.
2. Sign in as an administrator and use `/admin/storage-diagnostics/write-test`: it does a real write/exists/delete round
   trip with the configured credentials and returns fingerprints (never values) of the keys.
3. If the store answers and the object is simply absent, the document is genuinely missing. The usual causes are
   (a) demo records: `DatabaseSeeder` used to write document *paths* for the demo accounts with no files behind them
   (it now writes small placeholder PDFs to the configured disk when it runs outside the test suite; re-run it, or
   upload real documents), and (b) uploads made while the container's local disk was the target, before R2 was
   configured - those objects were lost with the container and cannot be recovered from the database.
4. Genuinely missing documents cannot be reconstructed. The applicant re-uploads: for a *pending* application the
   confirmation page ("A document needs your attention") replaces that document on the application itself, so the
   provider can open it; for a new application the apply page asks for the document again. Decided and withdrawn
   applications are final and are not rewritten.

Do **not** make the bucket public or serve R2 URLs directly to "fix" a missing document: applicant documents are
private by design.

### Cloudflare R2 (S3) storage - verified on the real service by the maintainer (2026-10-18)

`FILESYSTEM_DISK=s3` is wired for a private R2 bucket (see `docs/security.md`). The automated tests use the local disk
and fakes; the maintainer has confirmed it works against real R2. The checks below are for repeating on any new
bucket or credentials:

1. **Upload** - an applicant uploads a results certificate and an ID; the objects appear in the bucket
   under the expected keys, and `document_files` rows point at them.
2. **Replacement** - upload a new file over the old one; the profile shows the new file and the old object
   is removed (or at least no longer reachable from the app).
3. **Retrieval** - the applicant opens their own document through the app; the bucket itself has no public
   access (a direct object URL is refused).
4. **Provider download** - a provider with an application opens the submitted document (snapshot) and gets
   the bytes; a provider without an application is refused.
5. **Missing object** - delete one object by hand; the app shows "File unavailable" instead of erroring.
6. **Audit** - each download writes an audit entry.

### Troubleshooting a failed deploy

| Symptom | Fix |
|---------|-----|
| `No application encryption key has been specified` | `APP_KEY` is unset. Generate one locally and set it in the dashboard. |
| 500 with no detail | Expected in production — `APP_DEBUG=false` hides traces. Read the container logs; the app logs to stderr. |
| `SQLSTATE[HY000] [2002] Connection refused` | Database host/port wrong, or the managed database has not finished provisioning. The entrypoint retries for 60 seconds before giving up. |
| Migration fails midway | Inspect the `migrations` table, fix the cause, then redeploy. `php artisan migrate:status` shows exactly what ran. |
| Uploads disappear after deploy | `FILESYSTEM_DISK` is not `s3` (or the `AWS_*` secrets are missing), so uploads went to the container's ephemeral disk. Restore the R2 settings from `render.yaml`; see "Where uploaded documents live" above. Objects already lost cannot be recovered - applicants re-upload. |
| Uploads fail with a permission error on a mounted `FILESYSTEM_ROOT` (VPS/local only) | The entrypoint never ran as root against it (e.g. a manual restart bypassing the image's normal boot). Redeploy so `docker/entrypoint.sh` chowns the mount to `www-data` again. Not applicable to R2. |
| `SQLSTATE[HY000] [2002] Cannot connect to MySQL using SSL` | `MYSQL_ATTR_SSL_CA` points at a file that is missing or is not a valid CA. On Render check the Secret File is named `aiven-ca.pem`; locally check the path resolves from the project root. |
| Connection times out against Aiven | `DB_PORT` is almost certainly still 3306. Aiven assigns a per-service port. |
| Stale config or routes after a change | The entrypoint warms `config:cache`, `route:cache` and `view:cache`. Redeploy to rebuild them; never edit cached config in place. |

### Diagnosing email in production

Production mail is the Mailgun **HTTP API** (Render blocks outbound SMTP on the free tier),
and messages are **queued in the database** and drained by the Supervisor `queue` worker.
That gives four places a message can stop, and from inside the application they all look the
same: the request succeeds and no email arrives.

The exact path, with no SMTP anywhere in it:

```
EmailService -> queued ScholarZimMail -> queue worker
             -> MailgunApiService -> https://api.mailgun.net/v3/{domain}/messages -> recipient
```

`ScholarZimMail::send()` is overridden, so the queue worker submits over HTTPS rather than
handing the message to Laravel's mail transport. Both the queued path and `sendNow()` converge
there, which is what guarantees exactly one Mailgun submission per email.

Run this first, from a shell on the running instance:

```bash
php artisan mail:check
```

It prints the resolved configuration, then performs a read-only `GET /v3/domains/{domain}`
against Mailgun. It never prints the secret, and it never sends anything. Exit code is `0`
only if the checks pass, so it is safe to use in a deploy gate.

To prove the whole path end to end, including submission:

```bash
php artisan mail:check --send=you@real-address.com
```

Use a **real external address**. The `@scholarzim.co.zw` demo accounts have no inbound routes
in Mailgun, so mail to them is accepted and then discarded.

| Symptom | Likely cause |
|---------|--------------|
| `mail:check` says `MAILGUN_SECRET: NOT CONFIGURED` | `render.yaml` marks it `sync: false`, so it must be set in the Render dashboard by hand. The boot log also warns about this. |
| `401 Unauthorized` | The key is wrong, mistyped or has been rotated. This is what reaches the app log as `Unable to send an email: Forbidden (code 401)`. |
| `403 Forbidden` | The key authenticates but cannot read this domain — a sending-only key, or one from another account/subaccount. |
| `404 Not Found` | `MAILGUN_DOMAIN` is not a domain on this account. An EU-region domain also 404s here: set `MAILGUN_ENDPOINT=api.eu.mailgun.net`. |
| `mail:check` passes but nothing arrives | Submission works; the failure is later. Check **Sending → Logs** in Mailgun for `delivered` vs dropped/bounced/suppressed, then the recipient's spam folder. |
| `mail:check --send` works but application emails do not | The queue, not the mailer. Check the Supervisor `queue` program is running and inspect the `jobs` and `failed_jobs` tables. |
| `429` in the logs | Rate limited by Mailgun. No configuration change needed — queued mail retries with backoff. |
| `400` / `422` in the logs | Mailgun rejected the message itself, usually a malformed recipient address. |

Permanently failed messages are retried three times with a growing backoff (60s, 5m, 15m),
then written to `failed_jobs`. `ScholarZimMail::failed()` also logs `Email permanently failed
after all retries` at error level with the recipients and the transport error, so a lost
message is traceable without unserialising the job payload.

---

## Alternative: DigitalOcean Droplet + Docker + Nginx

Deploy target: **https://www.scholarzim.co.zw** (also redirect bare `scholarzim.co.zw` → `www`).

### What you need before starting

| Item | Notes |
|------|--------|
| Domain | `scholarzim.co.zw` registered; you can edit DNS |
| Server | Ubuntu 22.04/24.04 VPS (2 GB RAM recommended), public IP |
| SSH access | Root or sudo user |
| Mail account | SMTP or a transactional-email API for password resets / verification |
| DNS TTL | Prefer a low TTL (300s) while cutting over |

Local bug-fix changes must be **committed and pushed to `main`** before you build on the server.

---

## 1. DNS (at your registrar)

Create these records for `scholarzim.co.zw`:

| Type | Name | Value |
|------|------|--------|
| **A** | `@` | Your VPS public IPv4 |
| **A** | `www` | Same VPS public IPv4 |

Optional: **AAAA** for IPv6 if your VPS has it.

Wait until both resolve:

```bash
nslookup www.scholarzim.co.zw
nslookup scholarzim.co.zw
```

---

## 2. Server bootstrap (Ubuntu)

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y docker.io docker-compose-v2 nginx certbot python3-certbot-nginx git ufw
sudo usermod -aG docker $USER
# log out and back in so docker works without sudo
```

Firewall:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

---

## 3. Clone and configure secrets

```bash
cd /opt
sudo git clone https://github.com/Tindo-cyber/ScholarZim.git
cd /opt/ScholarZim
sudo cp .env.prod.example .env.prod
sudo nano .env.prod   # set APP_KEY, strong DB passwords, real mail credentials
```

Generate the application key on any machine with PHP, then paste it in:

```bash
php artisan key:generate --show
```

Required in `.env.prod`:

```bash
APP_KEY=base64:...
APP_URL=https://www.scholarzim.co.zw

DB_DATABASE=scholarzim
DB_USERNAME=scholarzim
DB_PASSWORD=<strong-password>
MYSQL_ROOT_PASSWORD=<strong-root-password>

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=noreply@scholarzim.co.zw
MAIL_FROM_NAME=ScholarZim
```

Never commit `.env.prod`.

---

## 4. Start the app stack

```bash
cd /opt/ScholarZim
sudo docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
sudo docker compose -f docker-compose.prod.yml ps
curl -sI http://127.0.0.1:8080/ | head -1
```

Expect `HTTP/1.1 200 OK`. The app listens on localhost only; Nginx publicly terminates HTTPS.

---

## 5. Nginx + HTTPS

```bash
sudo cp deploy/nginx-scholarzim.conf /etc/nginx/sites-available/scholarzim.co.zw
sudo ln -sf /etc/nginx/sites-available/scholarzim.co.zw /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

Issue certificates (after DNS points at this server):

```bash
sudo certbot --nginx -d www.scholarzim.co.zw -d scholarzim.co.zw
```

Certbot will enable TLS and renewals via `certbot.timer`.

The host proxy forwards `X-Forwarded-Proto`, which `TrustProxies` needs so Laravel generates `https://` URLs and honours `SESSION_SECURE_COOKIE`.

---

## 6. Go-live checks

- [ ] https://www.scholarzim.co.zw loads the landing page
- [ ] https://scholarzim.co.zw redirects to www
- [ ] Register / login works
- [ ] Password-reset email arrives
- [ ] File uploads persist after `docker compose restart`
- [ ] `SCHOLARZIM_DEMO_SEED=false` for real production, or `true` only for the FYP demo
- [ ] `APP_DEBUG=false` — trigger a 500 and confirm no stack trace is shown
- [ ] Reminder jobs are scheduled: `docker compose exec app php artisan schedule:list`

---

## 7. Updates (redeploy)

```bash
cd /opt/ScholarZim
sudo git pull origin main
sudo docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

Back up MySQL and the `upload_prod_data` volume regularly.

---

## Processes inside the image

Supervisor runs three programs, not one:

| Program | Why it matters |
|---------|----------------|
| nginx + PHP-FPM | Serves requests |
| `schedule:run` tick | Fires the two daily jobs (deadline reminders, and archiving expired listings) |
| `queue:work` | Delivers queued mail and notifications |

If mail is being written but never arriving, the worker is the first thing to check:

```bash
docker compose exec app php artisan queue:failed
docker compose logs app | grep queue
```

The worker recycles hourly (`--max-time=3600`), which is how queued code picks up a deploy
without a manual bounce.

## Front-end build

The image builds ScholarZim's own CSS and JS in a separate `node:20-alpine` stage and copies
only `public/build` into the runtime image, so node never ships to production. Nothing needs
to be run by hand.

If a deploy ever loses that directory the site still renders: with no manifest it falls back
to serving the source assets unminified through `SourceAssetController`, rather than
returning a 500 on every page. Unhashed assets are a caching problem, not an outage.

## Health check

`/health` is the probe (`healthCheckPath` in `render.yaml`): one database round trip,
returning 503 when the database is unreachable so the platform actually takes the instance
out of rotation. It replaced `/`, which ran the public statistics queries on every probe.

## Environment reference

```bash
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...
APP_URL=https://www.scholarzim.co.zw
# (no APP_TIMEZONE - the application timezone is fixed at UTC in config/app.php
#  and is not read from the environment; see "Timezone" below)
SESSION_SECURE_COOKIE=true
SESSION_EXPIRE_ON_CLOSE=true
LOG_CHANNEL=stderr
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=scholarzim
DB_USERNAME=scholarzim
DB_PASSWORD=...

# Where the "local" disk writes private documents (results certificates,
# transcripts, application attachments, provider certificates) when
# FILESYSTEM_DISK=local - the VPS Docker Compose stack and local development.
# Unset, it defaults to storage/app inside the container, which the Compose
# volumes below already mount. It has no effect on Render, where
# FILESYSTEM_DISK=s3 sends every upload to Cloudflare R2 (render.yaml) - see
# "Where uploaded documents live" above. Set it only when the local disk is
# mounted somewhere else.
# FILESYSTEM_ROOT=/var/data/scholarzim

# TLS to a managed MySQL. Unset for a plain local/compose MySQL - with no value
# no SSL attribute is passed and nothing changes. Set it and the server is
# verified against this CA; a missing or wrong file is refused, never downgraded.
# Render mounts Secret Files under /etc/secrets/; locally use storage/certs/.
# MYSQL_ATTR_SSL_CA=/etc/secrets/aiven-ca.pem

# Mail and notifications leave the request that triggered them. The image
# supervises a worker; without one, queued mail is written and never sent.
QUEUE_CONNECTION=database

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=noreply@scholarzim.co.zw
MAIL_FROM_NAME=ScholarZim

SCHOLARZIM_RUN_MIGRATIONS=true
SCHOLARZIM_DEMO_SEED=false
```

### Timezone

The application timezone is **UTC**, set directly in `config/app.php`
(`'timezone' => 'UTC'`). It is not read from the environment, so an
`APP_TIMEZONE` variable has no effect and is deliberately not listed above.
Timestamps in the database were written under that setting, so changing it would
change how every existing value is read and displayed (Africa/Harare is UTC+2).
That is a data decision to make deliberately, with a plan for existing rows - not
something to switch on through a deploy variable.

---

## Docker demo (local only — not production)

```bash
cd ScholarZim
docker compose up --build
```

Serves http://localhost:8000 with MailHog on http://localhost:8025, seeded with demo data.

## Uploads backup

Back up the uploads volume (`storage/app`) alongside database backups. Documents are never served from a public URL — nginx denies `/storage/` outright and every download goes through an authorising controller.

## Launch checklist (summary)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `APP_KEY` set explicitly and stable across deploys
- [ ] `SCHOLARZIM_DEMO_SEED=false` (or `true` for the FYP demo only)
- [ ] Secrets only in `.env.prod` / host env
- [ ] Mail verified
- [ ] HTTPS on www.scholarzim.co.zw, `SESSION_SECURE_COOKIE=true`, `SESSION_EXPIRE_ON_CLOSE=true` (closing the browser signs the user out)
- [ ] Persistent uploads (Render disk or Docker volume) + DB backups
- [ ] CI green on `main`
