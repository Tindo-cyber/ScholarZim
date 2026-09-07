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

### Uploaded files are not persistent on the free plan

Uploaded documents live on the private `local` filesystem disk
(`config/filesystems.php`, `App\Services\FileStorageService`), which by default
resolves to `storage/app` inside the container. Render only attaches persistent
disks to **paid** instance types, and this service is `plan: free`, so
`render.yaml` declares no disk — see the comment block in that file.

Everything the disk writes is therefore lost on every deploy and restart, and a
free instance also spins down after roughly 15 minutes idle and returns with a
fresh filesystem. That is applicant documents, results certificates, transcripts
and provider registration certificates. Rows in `document_files` (and the
`*_path` columns on `applicant_profiles`, `provider_profiles`, `applications`)
will still point at paths whose files are gone; the app reports a missing file
rather than erroring — `FileStorageService::exists()` returns false and nothing
crashes.

**To restore persistence:** the disk's mount path no longer has to be
`storage/app` — the app reads `FILESYSTEM_ROOT` to find the disk (see
`config/filesystems.php`), so any mount path works as long as the env var names
it. Move to a paid instance type, add the disk, and set the env var together:

```yaml
plan: starter
envVars:
  - key: FILESYSTEM_ROOT
    value: /var/data/scholarzim
disk:
  name: scholarzim-uploads
  mountPath: /var/data/scholarzim
  sizeGB: 1
```

`docker/entrypoint.sh` creates `FILESYSTEM_ROOT` and chowns it to the web
worker on every boot when it is set, so a freshly attached (root-owned) disk
is writable immediately — no manual `chmod`/`chown` on the box.

Existing database rows need nothing done to them: every stored path
(`document_files.path`, `applicant_profiles.results_certificate_path`, etc.) is
relative, not absolute, so it resolves under whichever root the disk points at.
What does need doing, if there are files worth keeping from a prior deploy, is
moving the *bytes* — the disk starts empty, and Render gives no way to reach a
free-plan container's filesystem after it has already recycled. In practice
this means: attach the disk **before** the first real upload you need to keep,
not after. If you ever need to move an existing populated root (e.g.
migrating off a VPS's `storage/app` onto a newly attached disk), copy the
directory tree across with the service stopped or in maintenance mode - `rsync
-a` or `cp -a` the old root's contents into the new one, preserving the
relative paths exactly - then set `FILESYSTEM_ROOT` and restart. Never delete
the old copy until a spot-check confirms a few real documents open correctly
through the app from the new location.

### Troubleshooting a failed deploy

| Symptom | Fix |
|---------|-----|
| `No application encryption key has been specified` | `APP_KEY` is unset. Generate one locally and set it in the dashboard. |
| 500 with no detail | Expected in production — `APP_DEBUG=false` hides traces. Read the container logs; the app logs to stderr. |
| `SQLSTATE[HY000] [2002] Connection refused` | Database host/port wrong, or the managed database has not finished provisioning. The entrypoint retries for 60 seconds before giving up. |
| Migration fails midway | Inspect the `migrations` table, fix the cause, then redeploy. `php artisan migrate:status` shows exactly what ran. |
| Uploads disappear after deploy | Expected on `plan: free` — there is no persistent disk. See "Uploaded files are not persistent" above. |
| Uploads fail with a permission error after attaching a disk | `FILESYSTEM_ROOT` is set but the entrypoint never ran as root against it (e.g. a manual restart bypassing the image's normal boot). Redeploy so `docker/entrypoint.sh` chowns the mount to `www-data` again. |
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
APP_TIMEZONE=Africa/Harare
SESSION_SECURE_COOKIE=true
LOG_CHANNEL=stderr
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=scholarzim
DB_USERNAME=scholarzim
DB_PASSWORD=...

# Where private documents are actually written (config/filesystems.php's
# "local" disk - results certificates, transcripts, application attachments,
# provider certificates). Unset, it defaults to storage/app inside the
# container, which is what the Docker Compose volumes below already mount -
# leave it unset for both the VPS stack and Render's free plan. Set it only
# when the disk is mounted somewhere else, e.g. a Render Persistent Disk at
# /var/data/scholarzim - see "Uploaded files are not persistent" above.
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
- [ ] HTTPS on www.scholarzim.co.zw, `SESSION_SECURE_COOKIE=true`
- [ ] Persistent uploads (Render disk or Docker volume) + DB backups
- [ ] CI green on `main`
