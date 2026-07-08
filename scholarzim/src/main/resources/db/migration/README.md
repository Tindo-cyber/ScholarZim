# Flyway migrations

- **V1** — core schema (`roles`, `users`, `opportunities`, `applications`, etc.)
- **V2** — platform extensions (password reset, saved scholarships, tenants)
- **V3** — security and compliance columns (`users.totp_*`)
- **V4** — provider verification (`provider_profiles` table)
- **V5** — applicant results certificate columns on `applicant_profiles`

`spring.flyway.baseline-on-migrate=true` keeps older dev databases (Hibernate-created schema, no V1 in history) working: Flyway baselines at v1 and applies V2–V5 only.

Development uses `spring.jpa.hibernate.ddl-auto=update`; production uses `validate` via the `prod` profile.

If a **fresh production migrate fails** partway through, drop `flyway_schema_history` and any tables Flyway created, then redeploy.
