# ScholarZim

A scholarship and academic opportunity management platform for Zimbabwean students and verified providers.

## Repository layout

```
ScholarZim/
├── backend/              Spring Boot API + Thymeleaf pages
│   ├── src/main/java/    application code (com.scholarzim)
│   ├── src/main/resources/
│   │   ├── templates/    Thymeleaf views (shrinking as pages move to React)
│   │   ├── static/       shared CSS/JS/images; React build lands in static/app
│   │   └── db/migration/ Flyway migrations
│   ├── database/         schema + seed SQL
│   ├── docs/             architecture, security, QA, demo script
│   └── pom.xml
├── frontend/             React + TypeScript + Vite
│   ├── src/
│   │   ├── pages/        route components
│   │   ├── components/   shared UI
│   │   ├── lib/          API client, hooks, formatting
│   │   └── styles/       React-specific CSS (tokens come from backend)
│   ├── index.html
│   └── vite.config.ts
├── Dockerfile            3 stages: React build → Maven package → JRE runtime
├── docker-compose.yml    local stack (app + MySQL + MailHog)
└── render.yaml           Render deployment
```

## Stack

**Backend** — Java 21, Spring Boot 3.5, Spring Security, Spring Data JPA, MySQL,
Flyway, Caffeine cache, Bucket4j rate limiting, springdoc-openapi.

**Frontend** — React 19, TypeScript, Vite, React Router.

## Architecture

The app is **mid-migration from Thymeleaf to React**. Both UIs run side by side:

- Thymeleaf still owns `/`, `/login`, `/dashboard`, and the rest of the existing pages.
- React owns everything under **`/app`** and grows as pages are ported.
- Both are served by the same Spring container on the same origin, so the
  existing session cookie authenticates React's API calls with no CORS setup
  and no token storage. React reads the CSRF token from the `XSRF-TOKEN`
  cookie and echoes it back as `X-XSRF-TOKEN` on writes.
- `frontend/` builds into `backend/src/main/resources/static/app/`, which is
  generated output and **not committed**.

## Run locally

### Option A — fully containerized (recommended)

Requires Docker and Docker Compose. Run from the repository root:

```bash
cp .env.example .env          # first time only; defaults work as-is
docker compose up -d --build
```

Open **http://localhost:8080** (Thymeleaf) and **http://localhost:8080/app** (React).

This starts three services — `app` (Spring Boot, with the React bundle baked in),
`mysql` (MySQL 8, persistent volume), `mailhog` (catches outgoing email at
http://localhost:8025) — networked together, with the app connecting to
`mysql:3306` internally rather than `localhost`.

Common commands:

```bash
docker compose stop                    # stop containers, keep data
docker compose up -d                   # start again, data intact
docker compose up -d --build           # rebuild after a code change
docker compose logs -f                 # all services
docker compose logs -f app             # just the app
docker compose logs -f mysql           # just the database
docker compose down                    # remove containers, KEEP the data volume
```

⚠️ `docker compose down -v` additionally **deletes the `mysql_data` volume —
all database data is lost**. Only run it if you intentionally want a clean
slate.

Inspecting the database from the host (e.g. MySQL Workbench):
connect to `127.0.0.1:3307` (or whatever `MYSQL_HOST_PORT` is set to in
`.env`) with the credentials from `.env`. A native/local MySQL install
commonly already owns port 3306, which is why the container defaults to 3307
instead.

Backup / restore (run from the repository root, with the stack up):

```bash
# Backup
docker compose exec mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" scholarzim' > backend/database/backups/backup.sql

# Restore
docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" scholarzim' < backend/database/backups/backup.sql
```

Uploaded files (CVs, verification documents, etc.) live in `backend/uploads/`
on the host and are bind-mounted into the container, so they persist across
rebuilds/recreates with no extra steps and are visible directly on disk.

### Option B — backend on host, only MySQL/MailHog in Docker

```bash
docker compose up -d mysql mailhog
cd backend
mvn spring-boot:run -Dspring-boot.run.profiles=demo
```

Open **http://localhost:8080**

### Frontend development

With the backend running on port 8080, start Vite's dev server for hot reload:

```bash
cd frontend
npm install          # first time only
npm run dev
```

Open **http://localhost:5173/app**. Vite proxies `/api`, `/login`, and the
shared assets through to Spring on 8080, so sessions and CSRF behave exactly as
they do in production.

To build the React bundle into the backend (what the Docker build does):

```bash
cd frontend
npm run build        # outputs to backend/src/main/resources/static/app/
```

Running the backend without ever building the frontend is fine — the Thymeleaf
pages work as normal and `/app` simply returns 404.

## Quick demo accounts (demo profile)

Password for all: `Password123!`

| Role | Email |
|------|-------|
| Admin | admin@scholarzim.co.zw |
| Applicant | tanaka.moyo@student.co.zw |
| Provider | scholarships@uk.gov.zw |

Full viva script: [backend/docs/demo-script.md](backend/docs/demo-script.md)

See [SUBMISSION.md](SUBMISSION.md) for the complete submission index.

See [DEPLOYMENT.md](backend/DEPLOYMENT.md) for production environment variables and launch checklist.

## Profiles

- **demo** — seeded demo data for viva/presentations (`mvn spring-boot:run -Dspring-boot.run.profiles=demo`)
- **default / dev** — local development; demo seeder may run
- **prod** — set `spring.profiles.active=prod`; demo seeder disabled; configure DB and mail via environment variables
