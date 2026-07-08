# ScholarZim production deployment

ScholarZim is a **Spring Boot + Thymeleaf monolith** (no separate Next.js frontend). Deploy **one** web service + MySQL.

## Recommended for FYP: Render (free / low-cost)

1. Push `main` to GitHub (`Tindo-cyber/ScholarZim`).
2. On [render.com](https://render.com): **New → MySQL** → copy host, port, database, user, password.
3. **New → Web Service** → connect the repo:
   - Leave **Root Directory** empty (repo root), **or** set it to `scholarzim`
   - **Runtime:** Docker
   - Dockerfile is at repo root (`Dockerfile`) and also at `scholarzim/Dockerfile`
   - Health check path: `/actuator/health`
   - The app binds to Render’s `PORT` (see `server.port` in `application-prod.properties`)
4. Set environment variables:

```bash
SPRING_PROFILES_ACTIVE=prod
SCHOLARZIM_DB_URL=jdbc:mysql://HOST:PORT/DATABASE?useSSL=true&allowPublicKeyRetrieval=true
SCHOLARZIM_DB_USER=...
SCHOLARZIM_DB_PASSWORD=...
SCHOLARZIM_APP_BASE_URL=https://YOUR-SERVICE.onrender.com
SCHOLARZIM_MAIL_FROM=noreply@scholarzim.com
SCHOLARZIM_SESSION_COOKIE_SECURE=true
SPRING_MAIL_HOST=
SPRING_MAIL_PORT=587
SPRING_MAIL_USERNAME=
SPRING_MAIL_PASSWORD=
# FYP demo: populate sample scholarships and users on startup (set SCHOLARZIM_DEMO_SEED=false for real production)
SCHOLARZIM_DEMO_SEED=true
```

Demo accounts after seeding (password for all: `Password123!`):

| Role | Email |
|------|-------|
| Student | `tanaka.moyo@student.co.zw` |
| Provider | `scholarships@uk.gov.zw` |
| Admin | `admin@scholarzim.co.zw` |

5. Deploy → open the Render HTTPS URL. Free Web Services sleep when idle (first hit can take ~1 minute).
6. Optional later: buy a domain and attach it under Render → Custom Domains; update `SCHOLARZIM_APP_BASE_URL`.

Do **not** deploy [`scholarzim-web/`](../scholarzim-web/DEPRECATED.md) (deprecated Next.js). Do **not** point production at local MySQL. Flyway migrates on startup (V1 creates the base schema). By default prod has `scholarzim.demo.seed=false`; for FYP demos set `SCHOLARZIM_DEMO_SEED=true` in Render (see env block above).

**If a deploy failed on Flyway** (e.g. `Failed to open the referenced table 'users'`), the Aiven DB may have a partial `flyway_schema_history`. In the Aiven console → your service → **Query statistics** or connect with a MySQL client and run:

```sql
DROP TABLE IF EXISTS flyway_schema_history;
DROP TABLE IF EXISTS password_reset_tokens;
DROP TABLE IF EXISTS saved_scholarships;
DROP TABLE IF EXISTS tenants;
```

Then push the latest code (includes `V1__initial_schema.sql`) and redeploy on Render.

---

## Alternative: DigitalOcean Droplet + Docker + Nginx

Deploy target: **https://www.scholarzim.com** (also redirect bare `scholarzim.com` → `www`).

### What you need before starting

| Item | Notes |
|------|--------|
| Domain | `scholarzim.com` registered; you can edit DNS |
| Server | Ubuntu 22.04/24.04 VPS (2 GB RAM recommended), public IP |
| SSH access | Root or sudo user |
| SMTP | Real mail host for password resets (Mailgun / SendGrid / SES / host mail) |
| DNS TTL | Prefer a low TTL (300s) while cutting over |

Local bug-fix changes must be **committed and pushed to `main`** before you build on the server.

---

## 1. DNS (at your registrar)

Create these records for `scholarzim.com`:

| Type | Name | Value |
|------|------|--------|
| **A** | `@` | Your VPS public IPv4 |
| **A** | `www` | Same VPS public IPv4 |

Optional: **AAAA** for IPv6 if your VPS has it.

Wait until both resolve:

```bash
nslookup www.scholarzim.com
nslookup scholarzim.com
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
cd /opt/ScholarZim/scholarzim
sudo cp .env.prod.example .env.prod
sudo nano .env.prod   # set strong DB passwords + real SMTP
```

Required in `.env.prod`:

```bash
SPRING_PROFILES_ACTIVE is set in compose (prod)

SCHOLARZIM_DB_USER=scholarzim
SCHOLARZIM_DB_PASSWORD=<strong-password>
MYSQL_ROOT_PASSWORD=<strong-root-password>

SCHOLARZIM_APP_BASE_URL=https://www.scholarzim.com
SCHOLARZIM_MAIL_FROM=noreply@scholarzim.com

SPRING_MAIL_HOST=...
SPRING_MAIL_PORT=587
SPRING_MAIL_USERNAME=...
SPRING_MAIL_PASSWORD=...
```

Never commit `.env.prod`.

---

## 4. Start the app stack

```bash
cd /opt/ScholarZim/scholarzim
sudo docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
sudo docker compose -f docker-compose.prod.yml ps
curl -s http://127.0.0.1:8080/actuator/health
```

Expect `"status":"UP"`. App listens on localhost only; Nginx publicly terminates HTTPS.

---

## 5. Nginx + HTTPS

```bash
sudo cp deploy/nginx-scholarzim.conf /etc/nginx/sites-available/scholarzim.com
sudo ln -sf /etc/nginx/sites-available/scholarzim.com /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

Issue certificates (after DNS points at this server):

```bash
sudo certbot --nginx -d www.scholarzim.com -d scholarzim.com
```

Certbot will enable TLS and renewals via `certbot.timer`.

---

## 6. Go-live checks

- [ ] https://www.scholarzim.com loads the landing page  
- [ ] https://scholarzim.com redirects to www  
- [ ] Register / login works  
- [ ] Password-reset email arrives (SMTP)  
- [ ] File uploads persist after `docker compose restart`  
- [ ] `scholarzim.demo.seed` is **false** for real production, or `SCHOLARZIM_DEMO_SEED=true` only for FYP demo  
- [ ] Swagger disabled at `/swagger-ui.html`  
- [ ] `/actuator/health` returns UP (do not expose other actuators)

---

## 7. Updates (redeploy)

```bash
cd /opt/ScholarZim
sudo git pull origin main
cd scholarzim
sudo docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

Back up MySQL and `/var/lib/docker/volumes/*upload_prod*` regularly.

---

## Environment reference

```bash
SPRING_PROFILES_ACTIVE=prod
SCHOLARZIM_DB_URL=jdbc:mysql://mysql:3306/scholarzim
SCHOLARZIM_DB_USER=scholarzim
SCHOLARZIM_DB_PASSWORD=...
SCHOLARZIM_APP_BASE_URL=https://www.scholarzim.com
SCHOLARZIM_MAIL_FROM=noreply@scholarzim.com
SPRING_MAIL_HOST=...
SPRING_MAIL_PORT=587
SPRING_MAIL_USERNAME=...
SPRING_MAIL_PASSWORD=...
SCHOLARZIM_SESSION_COOKIE_SECURE=true
```

These map to `application-prod.properties`.

---

## Docker demo (local only — not production)

```bash
cd scholarzim
docker compose up --build
```

Uses the `demo` profile on http://localhost:8080 with Mailhog.

## Uploads backup

Back up the uploads volume (or `scholarzim.upload.dir`) with database backups. Documents are not served from a public URL.

## Launch checklist (summary)

- [ ] `spring.profiles.active=prod`
- [ ] `scholarzim.demo.seed=false` (or `SCHOLARZIM_DEMO_SEED=true` for FYP demo only)
- [ ] Secrets only in `.env.prod` / host env
- [ ] Swagger off, actuator = health only
- [ ] Mail verified
- [ ] HTTPS on www.scholarzim.com
- [ ] Persistent uploads + DB backups
- [ ] CI green on `main`
