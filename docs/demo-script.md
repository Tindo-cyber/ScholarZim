# ScholarZim Viva Demo Script

**Duration:** 12–15 minutes
**Password for every demo account:** `ChangeMe123`

This script was rewritten against the current codebase rather than edited in place, because the
previous version described features that no longer exist (saved-search alerts, an
"information requested" application status, two-factor authentication) and demo accounts that
were never seeded. Every step below has been run against the real seeded data before being
written down.

## Before you start

1. `composer install && npm install && npm run build`
2. `cp .env.example .env && php artisan key:generate`
3. Either:
   - **Docker:** `docker compose up --build` — this also overrides `MAIL_MAILER` to `smtp` and
     points it at the bundled MailHog, so the password-reset demo below has somewhere to land
     without a Mailgun key.
   - **Without Docker:** set `MAIL_MAILER=log` in `.env` for the run, so a verification or
     reset link can be read from `storage/logs/laravel.log` instead of needing a mail server.
4. `php artisan migrate --seed`
5. `php artisan serve`, then open `http://localhost:8000`

Run the test suite beforehand: `php artisan test`. See [evaluation.md](evaluation.md) for the
last-verified pass count — do not quote a number here, it goes stale the moment the suite grows.

---

## Demo accounts

The seeder (`database/seeders/DatabaseSeeder.php`) creates these on every `--seed` run:

| Role | Email | State | Use in demo |
|------|-------|-------|--------------|
| Admin | `admin@scholarzim.co.zw` | ACTIVE, super admin | Provider verification, moderation, audit log, reports, ScholarFit weights |
| Provider (active) | `provider@scholarzim.co.zw` | ACTIVE, verified | Post listings, review applications |
| Provider (pending) | `trust@scholarzim.co.zw` | PENDING | Live admin verification |
| Applicant (complete profile) | `student@scholarzim.co.zw` | ACTIVE, full document set | Full apply flow, ScholarFit high match |
| Applicant (incomplete profile) | `chipo.ncube@scholarzim.co.zw` | ACTIVE, no results certificate | Completeness checklist, a listing that requires a certificate |

Five listings are published, one is sitting in the moderation queue
("Bulawayo Mining Skills Scholarship"), and three applications already exist in different
states — see Step 4.

---

## Step 1 — Public catalogue (2 min)

**Talking point:** Discovery works without an account.

1. Visit `/` — landing page, live platform statistics
2. Visit `/scholarships` — browse, search by keyword, filter by field/level
3. Sort by award value — listings with no stated value sort last either way, not as zero
4. Open a listing's detail page

---

## Step 2 — Applicant profile and ScholarFit (3 min)

**Talking point:** The certificate affects your match score and can be required by a specific
provider; it does not block you from applying anywhere.

1. Log in as **`chipo.ncube@scholarzim.co.zw`**
2. Open `/applicant/profile` — the completeness badge reads **In progress**, and the checklist
   names the missing results certificate, CV and recommendation letter
3. Open **My matches** (`/applicant/recommendations`) — every listing shows a match
   percentage and an explanation of the dimensions behind it, *except* one:
   **"Harare Health Sciences Postgraduate Grant"** reads **Requirements not met**, because that
   provider has marked the listing as requiring a certificate on file
4. Log out, log back in as **`student@scholarzim.co.zw`** (complete profile) — the same
   listing now scores normally, and **"Zimbabwe Tech Futures Undergraduate Bursary"** is his
   strongest match
5. Point out **"Rural Schools A-Level Support Fund"**: it reads Requirements not met for
   `student@scholarzim.co.zw` (province-restricted to Manicaland, he is in Harare) and scores
   normally for `chipo.ncube@scholarzim.co.zw` (she is in Manicaland) — the same rule, two
   different outcomes, driven entirely by the profile

**Talking point for both accounts:** applying is open regardless of profile completeness —
click **Apply** on any listing as either account to show there is no redirect or block.

---

## Step 3 — Submitting and tracking an application (2 min)

1. Still as **`student@scholarzim.co.zw`**, track his existing application at
   `/my-applications` — **"Midlands Engineering Excellence Award"** sits at **Pending**
2. Open any listing he has not applied to yet (everything except Midlands Engineering is
   free) and apply — personal statement; a supporting document is optional
3. Try applying to the same listing again — blocked, one application per student per
   scholarship
4. Log out, log in as **`chipo.ncube@scholarzim.co.zw`**, and open `/my-applications` — she
   already has one of each remaining outcome: **"Rural Schools A-Level Support Fund"** is
   **Accepted**, with the provider's written reason shown; **"Agribusiness Innovation Research
   Grant"** is **Rejected**, reason shown. Point out that both are final: neither can be
   reopened, and reapplying is only possible after a **Withdrawal**.

---

## Step 4 — Provider review and decision (3 min)

**Talking point:** A provider sees only their own applicants, and a decision requires a
written reason.

1. Log out → log in as **`provider@scholarzim.co.zw`**
2. Open `/provider/applications` — the pending application from Step 3 is there
3. Open it: applicant profile, ScholarFit score, and (if on file) the results certificate are
   all visible
4. Try **Accept** or **Reject** with the reason left blank — refused
5. Submit a real reason → the application leaves the pending queue; the applicant's
   notification and status update are immediate

---

## Step 5 — Admin verification and moderation (3 min)

**Talking point:** Publishing and account activation both wait on an administrator.

1. Log out → log in as **`admin@scholarzim.co.zw`**
2. Open `/admin/dashboard` — the pending provider (`trust@scholarzim.co.zw`) and the pending
   listing are both visible
3. Approve or reject the pending provider — a notification is written either way, and the
   account's publishing routes only open once approved
4. Open the pending listing and approve it — it becomes visible on the public catalogue
5. Open the **Audit log** — the seeded approval, registration and decision events are already
   there from setup, alongside whatever this session's logins and actions just added

---

## Step 6 — Tuning ScholarFit (2 min)

**Talking point:** The weights are a configuration an administrator can change, not a constant
compiled into the code.

1. As the admin, open **ScholarFit weights**
2. Move a slider — the total turns red and Save is disabled until the weights sum to 100
3. Lower the weight on field of study, save, and reload a student's recommendations — the
   ranking changes
4. Press **Reset to defaults** to restore the shipped weighting

---

## Step 7 — Reports and quality evidence (2 min)

1. As the admin, open **Reports** and download a PDF and an Excel export — confirm the file
   opens and the row count matches what is in the database
2. In a terminal: `php artisan test` — confirm it passes; do not quote a specific count from
   memory, read it off the run
3. Mention CI on GitHub: the test suite plus a migrate/rollback smoke run against MySQL 8, and
   a weekly `composer audit`
4. Point to [manual-qa-checklist.md](manual-qa-checklist.md) and [evaluation.md](evaluation.md)

---

## Step 8 — Security highlights (2 min)

Cover briefly (see [security.md](security.md)):

- Session authentication only — no second factor, no API tokens; this was a deliberate scope
  decision, not an oversight, when the project was narrowed to its five objectives
- bcrypt password hashing, role-based route access, per-record ownership checks
- Private documents served through an authenticated download route, never a public path
- Rate limiting on login; CSRF on every form; secure session cookies in production
- Try opening another role's application or document by guessing its URL — refused, and
  covered by `AuthorizationTest`

---

## What this script deliberately does not include

Scoped out of the platform on 2026-08-31, along with the reasoning: two-factor authentication,
saved-search email alerts, bulk application decisions, an "information requested" intermediate
status, and SMS notifications. None of these exist in the current codebase. If asked about any
of them, the honest answer is that they were considered and cut to keep the platform to its
five stated objectives — not that they are broken or half-built.

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Database connection refused | `docker compose up -d mysql` and wait for it to report healthy |
| Empty scholarship list | Confirm `--seed` ran: `php artisan migrate --seed` |
| Login fails | Check the account status — a pending provider is not the same as an inactive one; both can still sign in, but publishing is gated |
| No email arrives | `MAIL_MAILER=log` writes to `storage/logs/laravel.log`; with Docker, check MailHog at `http://localhost:8025`; mail is queued, so also confirm a worker is running (`php artisan queue:work`) |
| Page loads unstyled | Run `npm install && npm run build`, or accept the unminified fallback the app falls back to automatically |
| A listing you just approved is not visible | The catalogue only shows listings that are both `ACTIVE` and `APPROVED` and not past their deadline |
