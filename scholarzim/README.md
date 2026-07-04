# ScholarZim — Module README

Zimbabwe's scholarship management platform — Spring Boot monolith with Thymeleaf UI, MySQL, and REST API.

## Quick start

```bash
# Infrastructure (MySQL + Mailhog)
docker compose up -d

# Run with demo data (recommended for viva)
mvn spring-boot:run -Dspring-boot.run.profiles=demo
```

Open **http://localhost:8080**

## One-command demo (Docker)

```bash
docker compose up --build
```

Starts MySQL, Mailhog, and the ScholarZim application on port 8080.

## Demo accounts

Password for all: `Password123!`

| Role | Email |
|------|-------|
| Admin | admin@scholarzim.co.zw |
| Provider | scholarships@uk.gov.zw |
| Applicant (with cert) | tanaka.moyo@student.co.zw |
| Applicant (no cert) | simba.ndlovu@student.co.zw |
| Pending provider | pending.provider@org.co.zw |

See [docs/demo-script.md](docs/demo-script.md) for the full viva walkthrough.

## Tests

```bash
mvn clean test
```

71 automated tests (unit, MockMvc integration, Flyway smoke in CI).

## Documentation

| Document | Description |
|----------|-------------|
| [docs/architecture.md](docs/architecture.md) | System design and flows |
| [docs/demo-script.md](docs/demo-script.md) | Viva presentation script |
| [docs/security.md](docs/security.md) | Security controls |
| [docs/user-guide.md](docs/user-guide.md) | Role-based user guide |
| [docs/evaluation.md](docs/evaluation.md) | Test and QA evidence |
| [docs/manual-qa-checklist.md](docs/manual-qa-checklist.md) | Manual regression checklist |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Production environment variables |

## Profiles

| Profile | Use |
|---------|-----|
| `demo` | Seeded demo data for presentations |
| `prod` | Production — no demo seed, env-based secrets |
| `test` | Automated tests (H2) |

## Stack

Java 21 · Spring Boot 3.5 · Thymeleaf · MySQL 8 · Flyway · Spring Security · OpenAPI

The UI is served entirely by this module. The former Next.js app in `../scholarzim-web/` is **deprecated**.
