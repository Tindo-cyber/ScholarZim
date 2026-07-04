# ScholarZim — Final Year Project Submission

## What this is

ScholarZim is a scholarship and academic opportunity management platform for Zimbabwean students and verified providers. It supports applicant registration, verified provider onboarding, scholarship applications, ScholarFit matching, and administrative oversight.

## Quick demo (viva)

```bash
cd scholarzim
docker compose up --build
```

Open http://localhost:8080 and follow [scholarzim/docs/demo-script.md](scholarzim/docs/demo-script.md).

**Demo password:** `Password123!` (all seeded accounts)

## Run locally (development)

```bash
cd scholarzim
docker compose up -d          # MySQL + Mailhog
mvn spring-boot:run -Dspring-boot.run.profiles=demo
```

## Run tests

```bash
cd scholarzim
mvn clean test
```

Expect 71 tests, BUILD SUCCESS.

## Documentation index

| Document | Path |
|----------|------|
| Architecture | [scholarzim/docs/architecture.md](scholarzim/docs/architecture.md) |
| Demo script | [scholarzim/docs/demo-script.md](scholarzim/docs/demo-script.md) |
| Security | [scholarzim/docs/security.md](scholarzim/docs/security.md) |
| User guide | [scholarzim/docs/user-guide.md](scholarzim/docs/user-guide.md) |
| Evaluation | [scholarzim/docs/evaluation.md](scholarzim/docs/evaluation.md) |
| Manual QA | [scholarzim/docs/manual-qa-checklist.md](scholarzim/docs/manual-qa-checklist.md) |
| Deployment | [scholarzim/DEPLOYMENT.md](scholarzim/DEPLOYMENT.md) |

## Technology stack

Java 21 · Spring Boot 3.5 · Thymeleaf · MySQL 8 · Flyway · Spring Security · Maven

## Repository layout

```
ScholarZim/
├── scholarzim/          # Main application (canonical)
├── scholarzim-web/      # Deprecated Next.js frontend
├── .github/workflows/   # CI (tests + Flyway smoke)
└── SUBMISSION.md        # This file
```

## Production

See [scholarzim/DEPLOYMENT.md](scholarzim/DEPLOYMENT.md). Use `spring.profiles.active=prod` with environment variables for database and mail.

## Author notes

- Demo data is disabled in production (`scholarzim.demo.seed=false`).
- SMS notifications are planned future work; in-app and email notifications are functional.
- REST API exposes public catalog and applicant endpoints; full admin/provider API is MVC-only.
