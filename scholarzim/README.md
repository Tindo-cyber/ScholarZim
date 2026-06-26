# ScholarZim

Zimbabwe's scholarship operating system — Spring Boot backend with public catalog, application workflow, ScholarFit matching, admin oversight, REST API, and Next.js frontend.

## Quick start

### Backend (Spring Boot)

```bash
cd scholarzim
docker compose up -d
mvn spring-boot:run
```

Open http://localhost:8080 — API docs at http://localhost:8080/swagger-ui.html

### Frontend (Next.js)

```bash
cd scholarzim-web
npm install
npm run dev
```

Open http://localhost:3000

## Demo accounts

| Role | Email | Password |
|------|-------|----------|
| Applicant | tanaka.moyo@student.co.zw | Password123! |
| Provider | scholarships@uk.gov.zw | Password123! |
| Admin | admin@scholarzim.co.zw | Password123! |

## Features

- Public catalog, application wizard, ScholarFit v2 matching
- Password reset, rate limiting, Flyway, 2FA, audit log
- REST API + OpenAPI, Next.js public frontend
- Mobile responsive UI, PWA manifest and service worker
- SMS/email deadline reminders, saved scholarships, full-text search

## Production

```bash
mvn spring-boot:run -Dspring-boot.run.profiles=prod
```

Mailhog UI (local): http://localhost:8025
