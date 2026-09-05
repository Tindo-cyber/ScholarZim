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

The seeder (`database/seeders/DatabaseSeeder.php`) creates these on every `--seed` run. The six
applicants deliberately span the education pathway `App\Services\ScholarFit\EducationPathway`
enforces — Primary through Masters — so every rule below has a real profile behind it rather
than a constructed one.

| Role | Email | State | Use in demo |
|------|-------|-------|--------------|
| Admin | `admin@scholarzim.co.zw` | ACTIVE, super admin | Provider verification, moderation, audit log, reports, ScholarFit weights |
| Provider (active) | `provider@scholarzim.co.zw` | ACTIVE, verified | Post listings, review applications |
| Provider (pending) | `trust@scholarzim.co.zw` | PENDING | Live admin verification |
| Applicant, Undergraduate | `student@scholarzim.co.zw` | ACTIVE, full document set incl. transcript | Full apply flow, ScholarFit high match |
| Applicant, A-Level (incomplete) | `chipo.ncube@scholarzim.co.zw` | ACTIVE, no documents at all | Completeness checklist, a pathway block, a certificate block |
| Applicant, Primary | `kudzai.marufu@scholarzim.co.zw` | ACTIVE, guardian details on file | Guardian-assisted Form 1 pathway, everything else blocked |
| Applicant, O-Level | `farai.sibanda@scholarzim.co.zw` | ACTIVE, results certificate | O-Level reaching A-Level and a qualifying Undergraduate award |
| Applicant, A-Level | `tanaka.chirwa@scholarzim.co.zw` | ACTIVE, results certificate | A-Level meeting a listing's explicit minimum-level floor |
| Applicant, Masters | `blessing.moyana@scholarzim.co.zw` | ACTIVE, transcript on file | Postgraduate academic evidence, Masters-targeted award |

Six listings are published, one is sitting in the moderation queue
("Bulawayo Mining Skills Scholarship"), and three applications already exist in different
states — see Step 4. Password for every account: `ChangeMe123`.

---

## Step 1 — Public catalogue (2 min)

**Talking point:** Discovery works without an account.

1. Visit `/` — landing page, live platform statistics
2. Visit `/scholarships` — browse, search by keyword, filter by field/level
3. Sort by award value — listings with no stated value sort last either way, not as zero
4. Open a listing's detail page

---

## Step 2 — The education pathway and progressive profile (4 min)

**Talking point:** Current education level, target level, pathway, scholarship-specific rules
and ScholarFit's match score are five separate things — see
`App\Services\ScholarFit\EducationPathway` and `EligibilityEvaluator`. ScholarFit never prints a
percentage next to a listing an applicant cannot actually apply to.

1. Log in as **`kudzai.marufu@scholarzim.co.zw`** (Primary) and open `/applicant/profile` — the
   form shows only what a Primary pupil's profile needs: no field of study, no academic-results
   box, no document upload card, and a **Guardian details** card instead, already filled in.
   Change the education level dropdown to **Undergraduate** in another tab (do not save) to show
   the same form growing a field-of-study, transcript and year-of-study section live — this is
   client-side progressive disclosure; the server enforces the same rules independently
2. Open **My matches** — only **"Chinhoyi Form 1 Transition Bursary"** appears. Open
   **"Zimbabwe Tech Futures Undergraduate Bursary"** directly instead: no percentage is shown at
   all, just **"You cannot apply to this scholarship"** and the reason — Primary cannot reach
   Undergraduate in one step, regardless of how the listing is configured
3. Log out, log in as **`farai.sibanda@scholarzim.co.zw`** (O-Level). Open **My matches**:
   **"Rural Schools A-Level Support Fund"** and **"Zimbabwe Tech Futures Undergraduate Bursary"**
   both score normally — O-Level may reach A-Level, and this particular Undergraduate listing
   accepts O-Level applicants directly. Open **"Midlands Engineering Excellence Award"**
   instead (also Undergraduate-targeted): **not eligible** — that specific provider has set a
   minimum qualifying level of A-Level, a rule narrower than the general pathway
4. Log out, log in as **`tanaka.chirwa@scholarzim.co.zw`** (A-Level) and open the same
   **"Midlands Engineering Excellence Award"** — she meets the floor exactly, and scores normally
5. Log out, log in as **`chipo.ncube@scholarzim.co.zw`** (A-Level, no documents) — the
   completeness badge reads **In progress**. Open **"Harare Health Sciences Postgraduate Grant"**
   (Masters-targeted): not eligible, for two independent reasons at once — A-Level cannot reach
   Masters, *and* that provider requires proof of academic results on file. Point out the reason
   list names both

**Talking point:** province and locality are separate, and a blank locality is never held
against a student. Open **"Rural Schools A-Level Support Fund"** as `farai.sibanda@scholarzim.co.zw`
(Manicaland, matches) and as `student@scholarzim.co.zw` (Harare, does not) — same rule, two
outcomes, and neither account has ever been asked for a country.

---

## Step 3 — Submitting and tracking an application (3 min)

**Talking point:** The pathway and eligibility rules shown in Step 2 are enforced by
`ApplicationService::submit()` itself, not only by the recommendations list — a direct POST to
the apply route is refused exactly the same way the UI already showed it would be.

1. As **`student@scholarzim.co.zw`**, track his existing application at `/my-applications` —
   **"Midlands Engineering Excellence Award"** sits at **Pending** (Undergraduate comfortably
   clears that listing's A-Level floor)
2. Open **"Zimbabwe Tech Futures Undergraduate Bursary"**, which he has not applied to, and
   apply — personal statement; a supporting document is optional since his profile already
   carries a transcript
3. Try applying to the same listing again — blocked, one application per student per
   scholarship
4. Log out, log in as **`kudzai.marufu@scholarzim.co.zw`** and open
   **"Chinhoyi Form 1 Transition Bursary"** — no documents are required at Primary level; apply
   with one click via the listing card's **Apply** button
5. Log out, log in as **`chipo.ncube@scholarzim.co.zw`**, and open `/my-applications` — she
   already has one of each remaining outcome: **"Rural Schools A-Level Support Fund"** is
   **Accepted**, with the provider's written reason shown; **"Agribusiness Innovation Research
   Grant"** is **Rejected**, reason shown (an A-Level applicant on a PhD-targeted award — the same
   pathway rule from Step 2, seen as history rather than live). Point out that both are final:
   neither can be reopened, and reapplying is only possible after a **Withdrawal**.

---

## Step 4 — Provider review and decision (3 min)

**Talking point:** A provider sees only their own applicants, and a decision requires a
written reason.

1. Log out → log in as **`provider@scholarzim.co.zw`**
2. Open `/provider/applications` — the pending application from Step 3 is there
3. Open it: applicant profile, ScholarFit score, and (if on file) the applicant's results
   certificate or transcript — whichever their education level actually uses — are all visible
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
