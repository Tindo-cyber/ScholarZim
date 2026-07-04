# ScholarZim environment variables (production)

```bash
SPRING_PROFILES_ACTIVE=prod

# Database
SCHOLARZIM_DB_URL=jdbc:mysql://host:3306/scholarzim
SCHOLARZIM_DB_USER=scholarzim
SCHOLARZIM_DB_PASSWORD=change-me

# Application
SCHOLARZIM_APP_BASE_URL=https://scholarzim.co.zw
SCHOLARZIM_MAIL_FROM=noreply@scholarzim.co.zw

# Mail server
SPRING_MAIL_HOST=smtp.example.com
SPRING_MAIL_PORT=587
SPRING_MAIL_USERNAME=
SPRING_MAIL_PASSWORD=
```

Map these in `application-prod.properties` (already bound) or override via your deployment platform.

## Docker demo

```bash
cd scholarzim
docker compose up --build
```

Starts MySQL, Mailhog, and the ScholarZim app on http://localhost:8080 with the `demo` profile.

## Uploads backup

Back up the `uploads/` directory (or `scholarzim.upload.dir`) alongside database backups. Documents are no longer served from a public URL.

## Launch checklist

- [ ] `spring.profiles.active=prod`
- [ ] `scholarzim.demo.seed=false`
- [ ] Database credentials from secrets (not committed)
- [ ] Swagger/OpenAPI disabled (`springdoc.*.enabled=false`)
- [ ] Actuator limited to `health`
- [ ] Mail delivery verified
- [ ] HTTPS termination in front of the app
- [ ] `uploads/` directory on persistent storage with backups
- [ ] CI passing on `main`
- [ ] Document downloads tested (`/applications/{id}/document`)
