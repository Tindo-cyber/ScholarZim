# ScholarZim

A scholarship and academic opportunity management platform for Zimbabwean students and verified providers.

## Stack

- Java 21, Spring Boot 3.5
- Thymeleaf + Bootstrap 5 (canonical UI at **http://localhost:8080**)
- MySQL
- Flyway migrations

## Run locally

### Option A — fully containerized (recommended)

Requires Docker and Docker Compose.

```bash
cd scholarzim
cp .env.example .env          # first time only; defaults work as-is
docker compose up -d --build
```

Open **http://localhost:8080**. This starts three services — `app` (Spring Boot),
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

Backup / restore (run from `scholarzim/`, with the stack up):

```bash
# Backup
docker compose exec mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" scholarzim' > database/backups/backup.sql

# Restore
docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" scholarzim' < database/backups/backup.sql
```

Uploaded files (CVs, verification documents, etc.) live in `scholarzim/uploads/`
on the host and are bind-mounted into the container, so they persist across
rebuilds/recreates with no extra steps and are visible directly on disk.

### Option B — app on host, only MySQL/Mailhog in Docker

```bash
cd scholarzim
docker compose up -d mysql mailhog
mvn spring-boot:run -Dspring-boot.run.profiles=demo
```

Open **http://localhost:8080**

### Quick demo accounts (demo profile)

Password for all: `Password123!`

| Role | Email |
|------|-------|
| Admin | admin@scholarzim.co.zw |
| Applicant | tanaka.moyo@student.co.zw |
| Provider | scholarships@uk.gov.zw |

Full viva script: [scholarzim/docs/demo-script.md](scholarzim/docs/demo-script.md)

See [SUBMISSION.md](SUBMISSION.md) for the complete submission index.

See [DEPLOYMENT.md](scholarzim/DEPLOYMENT.md) for production environment variables and launch checklist.

## Frontend note

The UI is served entirely by the Spring Boot app (`scholarzim/`). The former Next.js app in `scholarzim-web/` is **deprecated** — see `scholarzim-web/DEPRECATED.md`.

## Profiles

- **demo** — seeded demo data for viva/presentations (`mvn spring-boot:run -Dspring-boot.run.profiles=demo`)
- **default / dev** — local development; demo seeder may run
- **prod** — set `spring.profiles.active=prod`; demo seeder disabled; configure DB and mail via environment variables
