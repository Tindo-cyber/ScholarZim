# Flyway migrations

ScholarZim uses `spring.flyway.baseline-on-migrate=true` because the core schema was originally created by Hibernate.

- **V2** — platform extensions (password reset, saved scholarships)
- **V3** — security and compliance columns
- **V4** — provider verification (`provider_profiles` table)
- **V5** — applicant results certificate columns on `applicant_profiles`

For a **fresh production database**, run the app once with a documented schema export or use Hibernate `validate` only after the base tables exist.

Development uses `spring.jpa.hibernate.ddl-auto=update`; production uses `validate` via the `prod` profile.
