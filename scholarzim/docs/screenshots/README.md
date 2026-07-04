# ScholarZim Screenshots

Capture these during demo rehearsal (`mvn spring-boot:run -Dspring-boot.run.profiles=demo`). Save PNG files in this folder and reference them from the user guide.

| File | Page | Notes |
|------|------|-------|
| `01-landing.png` | `/` | Hero, stats strip, search card |
| `02-scholarships.png` | `/scholarships` | Browse list with filters |
| `03-scholarship-detail.png` | `/scholarships/{id}` | Opportunity detail + Apply button |
| `04-applicant-profile.png` | `/applicant/profile` | Profile form + results certificate upload |
| `05-apply-gate.png` | Redirect from Apply | Warning banner when certificate missing |
| `06-my-applications.png` | `/my-applications` | Application status list |
| `07-provider-applications.png` | `/provider/applications` | Provider review queue |
| `08-admin-dashboard.png` | `/admin/dashboard` | Pending providers + audit log |
| `09-mailhog-reset.png` | Mailhog UI `:8025` | Password reset email in inbox |
| `10-forgot-password.png` | `/forgot-password` | Request reset form |

## Capture tips

- Use 1280×720 or 1440×900 viewport for consistency.
- Light mode is preferred for printed reports; capture one dark-mode screenshot optionally for the settings section.
- Blur or redact any personal data if using a non-demo database.

## Mailhog (Docker)

When running `docker compose up`, open http://localhost:8025 after triggering **Forgot password** to screenshot the reset email.
