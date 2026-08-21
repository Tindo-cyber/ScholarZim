# ScholarZim — Final Year Project Submission

## What this is

ScholarZim is a scholarship and academic opportunity management platform for Zimbabwean students and verified providers. It supports applicant registration, verified provider onboarding, scholarship applications, ScholarFit matching, and administrative oversight.

## Quick demo (viva)

```bash
cd ScholarZim
docker compose up --build
```

Open http://localhost:8080 and follow [backend/docs/demo-script.md](backend/docs/demo-script.md).

**Demo password:** `Password123!` (all seeded accounts)

## Run locally (development)

```bash
cd ScholarZim
docker compose up -d mysql mailhog
cd backend
mvn spring-boot:run -Dspring-boot.run.profiles=demo
```

For React development with hot reload (backend must be running on 8080):

```bash
cd frontend
npm install
npm run dev                   # http://localhost:5173/app
```

## Run tests

```bash
cd ScholarZim/backend
mvn clean test
```

Expect 181 tests, BUILD SUCCESS.

## Documentation index

| Document | Path |
|----------|------|
| Architecture | [backend/docs/architecture.md](backend/docs/architecture.md) |
| Demo script | [backend/docs/demo-script.md](backend/docs/demo-script.md) |
| Security | [backend/docs/security.md](backend/docs/security.md) |
| User guide | [backend/docs/user-guide.md](backend/docs/user-guide.md) |
| Evaluation | [backend/docs/evaluation.md](backend/docs/evaluation.md) |
| Manual QA | [backend/docs/manual-qa-checklist.md](backend/docs/manual-qa-checklist.md) |
| Deployment | [backend/DEPLOYMENT.md](backend/DEPLOYMENT.md) |

## Technology stack

Java 21 · Spring Boot 3.5 · Thymeleaf · React 19 · TypeScript · Vite · MySQL 8 · Flyway · Spring Security · Maven

## Repository layout

```
ScholarZim/
├── backend/             # Spring Boot API + Thymeleaf pages
├── frontend/            # React + TypeScript + Vite
├── Dockerfile           # React build → Maven package → JRE runtime
├── docker-compose.yml   # Local stack (app + MySQL + MailHog)
├── .github/workflows/   # CI (frontend build + tests + Flyway smoke)
└── SUBMISSION.md        # This file
```

## Production

See [backend/DEPLOYMENT.md](backend/DEPLOYMENT.md). Use `spring.profiles.active=prod` with environment variables for database and mail.

## Author notes

- Demo data is disabled in production (`scholarzim.demo.seed=false`).
- SMS notifications are planned future work; in-app and email notifications are functional.
- REST API exposes public catalog and applicant endpoints; full admin/provider API is MVC-only.
