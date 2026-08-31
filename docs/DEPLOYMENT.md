# ScholarZim production deployment

ScholarZim is a **Laravel 10 monolith** served by nginx + PHP-FPM in a single container. Deploy **one** web service + MySQL.

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

Aiven requires TLS. Laravel's MySQL driver negotiates it automatically for Aiven's default configuration, so only the host, port, database, user and password need setting. If the provider requires a CA bundle, add `MYSQL_ATTR_SSL_CA` pointing at the PEM file and reference it from `config/database.php`.

### Render uploads disk (required for certificates)

Uploaded documents live on the private `local` disk at `storage/app`. Without a persistent disk they vanish on every deploy, which breaks provider verification and applicant results certificates.

`render.yaml` already declares the disk:

```yaml
disk:
  name: scholarzim-uploads
  mountPath: /var/www/html/storage/app
  sizeGB: 1
```

### Troubleshooting a failed deploy

| Symptom | Fix |
|---------|-----|
| `No application encryption key has been specified` | `APP_KEY` is unset. Generate one locally and set it in the dashboard. |
| 500 with no detail | Expected in production — `APP_DEBUG=false` hides traces. Read the container logs; the app logs to stderr. |
| `SQLSTATE[HY000] [2002] Connection refused` | Database host/port wrong, or the managed database has not finished provisioning. The entrypoint retries for 60 seconds before giving up. |
| Migration fails midway | Inspect the `migrations` table, fix the cause, then redeploy. `php artisan migrate:status` shows exactly what ran. |
| Uploads disappear after deploy | The persistent disk is not mounted at `/var/www/html/storage/app`. |
| Stale config or routes after a change | The entrypoint warms `config:cache`, `route:cache` and `view:cache`. Redeploy to rebuild them; never edit cached config in place. |

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
