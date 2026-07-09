# ScholarZim User Guide

Screenshots for printed reports live in [docs/screenshots/](screenshots/) — capture during demo rehearsal (see README there).

## Applicant (student)

### Getting started

1. Register at `/register` with your email and password.
2. Complete your **academic profile** at `/applicant/profile`.
3. Upload your **results certificate (PDF)** — required before applying to scholarships.
4. Browse scholarships at `/scholarships` or your dashboard recommendations.

![Applicant profile](../docs/screenshots/04-applicant-profile.png)

### Applying

- Click **Apply** on a scholarship. If your results certificate is missing, you will be redirected to your profile.
- Complete the application wizard (personal statement; optional supporting document).
- Track status at `/my-applications`.

![My applications](../docs/screenshots/06-my-applications.png)

### Saved scholarships

- Save opportunities from the browse page for later review (`/applicant/saved`).

### Account

- **Settings** → `/account/security` — appearance and data export.
- **Messages** → notification inbox for application updates and deadline reminders.

---

## Provider (organisation)

### Registration

1. Register at `/register/provider`.
2. Provide organisation type, registration number, and **registration certificate (PDF)**.
3. Wait for admin approval — you cannot publish active scholarships while `PENDING_APPROVAL`.

### After approval

- Create opportunities at `/opportunities/create`.
- Review applications at `/provider/applications`.
- View applicant academic summary and download **results certificate** for your opportunities only.

### Dashboard

- `/provider/dashboard` — overview of opportunities and pending applications.

---

## Administrator

### Dashboard

- `/admin/dashboard` — platform statistics, user management, pending provider queue.

### Provider verification

1. Open pending providers list.
2. Review registration details and download certificate.
3. **Approve** to activate account, or **Reject** with a reason.

### User management

- Suspend, activate, or delete user accounts from the admin dashboard.

### Audit log

- Review security and compliance events (logins, certificate views, application actions).

### Reports

- Export analytics and user reports (PDF/Excel) from admin tools where available.

---

## Public visitor

- Browse scholarships at `/scholarships` without an account.
- View opportunity details and deadlines.
- Register when ready to apply.

![Landing page](../docs/screenshots/01-landing.png)

---

## Password reset (email)

1. On the login page, click **Forgot password**.
2. Enter your account email and submit.
3. Check your inbox — with Docker demo stack, open **Mailhog** at http://localhost:8025.
4. Click the reset link in the email (valid for 1 hour).

If delivery fails after retries, an `EMAIL_DELIVERY_FAILED` event is written to the audit log.

![Forgot password](../docs/screenshots/10-forgot-password.png)

## Demo accounts

See [demo-script.md](demo-script.md) for viva/demo login credentials (demo profile only).
