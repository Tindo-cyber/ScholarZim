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

### While an application is open

- If the provider asks a question or wants more documents, it appears on the application
  page with a reply box. Answer there — the provider is told straight away.
- Invited to an interview? **Add to calendar** downloads a `.ics` file that works on a
  phone with no account signed in. You are also reminded the day before.
- Changed your mind? **Withdraw application** tells the provider the place is free. You can
  apply again later while the scholarship is still open.

### Saved scholarships and search alerts

- Save opportunities from the browse page for later review (`/applicant/saved`).
- Filter the list to what you are actually looking for, then press **Alert me about this
  search** and give it a name. Once a day we check for newly published scholarships that
  match, and tell you about them (`/applicant/saved-searches`).
- You are only told about listings published *after* you saved the search, so you never get
  the back catalogue in one go. Up to ten saved searches per account.

### Understanding your match score

- The percentage is how well your profile fits the listing across six weighted dimensions.
  Each one you miss costs points, and the panel says which.
- **"You are not eligible"** is different. Some providers set hard rules — a minimum points
  figure, an age limit, a citizenship or province requirement, a results certificate on
  file. Failing one means you cannot be considered, so no percentage is shown and the
  listing is left out of your recommendations.
- If we simply do not have the information to check a rule, we ask for it rather than ruling
  you out. Filling in your date of birth and citizenship lets us check age and citizenship
  rules for you.
- Everything holding a score back links straight to the profile field that fixes it.

### Account

- **Settings** → `/account/security` — password, two-factor authentication, email
  preferences, API tokens, data export, and account deletion.
- **Two-factor authentication** adds a code from an authenticator app on top of your
  password. Save the recovery codes shown during setup: each works once, and they are the
  only way back in if you lose your phone.
- **Sign out all other sessions** ends every session except the one you are using — useful
  after signing in on a library or lab computer.
- **Messages** → notification inbox for application updates, deadline reminders, and search
  alerts.

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

### Describing an award

- **What the award is worth** — the value, currency, and how many awards are on offer. This
  is the first thing a student compares, and it is what value sorting and the minimum-award
  filter read. A listing with no stated value still appears in search, but is excluded from
  both.
- **Your own application page** — an optional link, shown alongside the ScholarZim
  application.
- **Hard eligibility rules** — minimum A-Level points, an age ceiling, required citizenship
  or province, and whether a results certificate must be on file. These **disqualify**
  rather than score down: a student who fails one is told they are not eligible and the
  listing is left out of their recommendations. Leave a rule blank unless it genuinely is
  one; guidance belongs in the description, where it informs rather than blocks.

### Reviewing applications

- Set **Information requested** or **Documents requested** with a question, and the
  applicant gets a reply box on their own page. The application stays in your queue and the
  status stays where you put it — you decide when their answer moves it on.
- Tick several applications in the inbox and use the bar underneath to move them all at
  once. Approving, declining, or asking for anything still requires a written message, and
  each applicant is notified individually. Interviews are scheduled one at a time, since
  each needs its own date.

### Dashboard and analytics

- `/provider/dashboard` — overview of opportunities and pending applications.
- `/provider/analytics` — your funnel from views to saves to applications to awards, a view
  trend, per-listing performance, and who is applying by field and level. Your own visits to
  your listings are not counted.

---

## Administrator

### Dashboard

- `/admin/dashboard` — platform statistics, user management, pending provider queue.

### Scholarship moderation

- New listings queue on the dashboard. Tick several and use the bar underneath to approve
  or decline them together; a decline still needs a reason, which the provider is shown
  verbatim.
- A listing that looks like an existing one — same title, or same awarding body and closing
  date — is flagged as a **possible duplicate**. It is a prompt to look, not a refusal: two
  intakes of the same annual bursary are a legitimate pair of rows, and only a person can
  tell that apart from a double submission. Open the preview to see what it matched.

### Provider verification

1. Open pending providers list.
2. Review registration details and download certificate.
3. **Approve** to activate account, or **Reject** with a reason.

### User management

- Suspend, activate, or delete user accounts from the admin dashboard.

### Audit log

- Review security and compliance events (logins, certificate views, application actions).

### ScholarFit weights

- `/admin/scholarfit` sets how much each of the six dimensions contributes to a match score.
- They must total exactly 100, since every score is shown to students as a percentage — the
  running total on the page turns red and blocks saving until it does.
- A worked example on the same page shows what the numbers do before you commit to them.
- **Reset to defaults** restores the weighting the platform ships with.
- These weights do not control hard eligibility. Those rules are set per listing by the
  provider, and a student who fails one scores nothing whatever the weights say.

### Reports

- Export analytics and user reports (PDF/Excel) from admin tools where available.

---

## Public visitor

- Browse scholarships at `/scholarships` without an account.
- View opportunity details, award values, and deadlines. Sort by deadline or award value,
  and narrow with the filters; each active filter appears as a chip you can remove on its
  own.
- Register when ready to apply.
- Developers: `/developers` documents the read-only JSON API.

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
