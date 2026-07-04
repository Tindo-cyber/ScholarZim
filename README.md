# ScholarZim

A scholarship and academic opportunity management platform for Zimbabwean students and verified providers.

## Stack

- Java 21, Spring Boot 3.5
- Thymeleaf + Bootstrap 5 (canonical UI at **http://localhost:8080**)
- MySQL
- Flyway migrations

## Run locally

```bash
cd scholarzim
docker compose up -d          # MySQL + Mailhog (optional but recommended)
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
