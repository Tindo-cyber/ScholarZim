# ScholarZim — Final Year Project Submission

## What this is

ScholarZim is a scholarship and academic opportunity management platform for Zimbabwean students and verified providers. It supports applicant registration, verified provider onboarding, scholarship applications, ScholarFit matching, and administrative oversight.

## Quick demo (viva)

```bash
cd ScholarZim
docker compose up --build
```

Open http://localhost:8000 and follow [docs/demo-script.md](docs/demo-script.md).

**Demo password:** `ChangeMe123` (all seeded accounts)

| Role     | Email                     |
|----------|---------------------------|
| Admin    | admin@scholarzim.co.zw    |
| Provider | provider@scholarzim.co.zw |
| Student  | student@scholarzim.co.zw  |

## Run locally (development)

```bash
cd ScholarZim
composer install
npm install && npm run build   # ScholarZim's own CSS/JS
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve              # http://localhost:8000
php artisan queue:work         # in a second terminal: mail is queued
```

The site still works without the npm step — the source assets are served unminified — but
the build is what produces the hashed, minified bundle.

MySQL and MailHog can be borrowed from the Docker stack rather than installed natively:

```bash
docker compose up -d mysql mailhog
```

The daily jobs run off the scheduler. In the container that tick is supervised
automatically, alongside a queue worker; locally, run them on demand:

```bash
php artisan schedule:run
php artisan scholarzim:deadline-reminders            # or invoke a job directly
php artisan scholarzim:archive-expired-opportunities
```

## Run tests

```bash
cd ScholarZim
php artisan test
```

Expect 111 tests / 389 assertions passing.

## Documentation index

| Document | Path |
|----------|------|
| Architecture | [docs/architecture.md](docs/architecture.md) |
| Demo script | [docs/demo-script.md](docs/demo-script.md) |
| Security | [docs/security.md](docs/security.md) |
| User guide | [docs/user-guide.md](docs/user-guide.md) |
| Evaluation | [docs/evaluation.md](docs/evaluation.md) |
| Manual QA | [docs/manual-qa-checklist.md](docs/manual-qa-checklist.md) |
| Deployment | [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) |

## Technology stack

PHP 8.1+ (developed against 8.4) · Laravel 10 · Blade · Bootstrap 5 · Vite · MySQL 8 · Eloquent migrations · PHPUnit · Composer · npm

Reports are generated with dompdf (PDF) and PhpSpreadsheet (Excel). Transactional email
goes out through the Mailgun HTTP API via Symfony's Mailgun transport, with credentials read
from the environment.

## Repository layout

```
ScholarZim/
├── app/                 # Controllers, models, services, console commands
├── resources/views/     # Blade templates (pages, components, emails, reports)
├── routes/web.php       # All application routes
├── database/            # Migrations and the demo seeder
├── tests/               # Feature and unit tests
├── docker/              # nginx, php-fpm, supervisor and entrypoint config
├── Dockerfile           # Composer install → nginx + PHP-FPM runtime
├── docker-compose.yml   # Local stack (app + MySQL + MailHog)
├── .github/workflows/   # CI (tests + migration smoke) and dependency audit
└── SUBMISSION.md        # This file
```

## Production

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). Set `APP_ENV=production` with environment variables for database and mail; `APP_KEY` must be set explicitly so sessions survive a restart.

## Author notes

- Demo data is disabled in production (`SCHOLARZIM_DEMO_SEED=false`).
- SMS notifications log the message rather than dispatching it — the delivery path is wired end to end and awaits a gateway. In-app and email notifications are functional.
- Email is queued. Nothing is delivered without a worker (`php artisan queue:work`); the Docker image supervises one.
- This codebase is a port of an earlier Spring Boot implementation. The schema, column names, and business rules carried over unchanged.
