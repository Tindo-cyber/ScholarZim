# ScholarZim Viva Demo Script

**Duration:** 12–15 minutes  
**Password for all demo accounts:** `ChangeMe123`

## Before you start

1. Start infrastructure: `cd ScholarZim && docker compose up -d mysql mailhog`
2. Run the app: `php artisan migrate --seed && php artisan serve`  
   Or full stack: `docker compose up --build` (includes app service)
3. Open http://localhost:8000
4. Optional: MailHog UI at http://localhost:8025 for the password-reset email demo

Run tests beforehand: `php artisan test` (29 tests should pass).

---

## Optional — Password reset email (1 min)

**Talking point:** Transactional email with retry and audit on failure.

1. Ensure Mailhog is running (`docker compose up -d` includes it on port 8025)
2. Visit `/forgot-password` while logged out
3. Enter **simba.ndlovu@student.co.zw** → submit
4. Open http://localhost:8025 — show reset email with link
5. Mention: `EmailServiceImpl` retries up to 3 times; failures log `EMAIL_DELIVERY_FAILED` in audit log

---

## Demo accounts

| Role | Email | State | Use in demo |
|------|-------|-------|-------------|
| Admin | admin@scholarzim.co.zw | ACTIVE | Approve providers, audit log |
| Provider (active) | scholarships@uk.gov.zw | ACTIVE | Review applications, download results cert |
| Provider (pending) | pending.provider@org.co.zw | PENDING_APPROVAL | Live admin approval |
| Applicant (ready) | tanaka.moyo@student.co.zw | ACTIVE + results cert | Full apply flow |
| Applicant (no cert) | simba.ndlovu@student.co.zw | ACTIVE, profile without PDF | Apply gate redirect |

---

## Step 1 — Public catalog (2 min)

**Talking point:** Open access to scholarship discovery without registration.

1. Visit `/` — landing page
2. Visit `/scholarships` — browse list, use search/filter if shown
3. Open any scholarship detail — show deadline, provider, funding type
4. Use the **Sort by** control — deadline, then award value — and note the removable filter
   chips and the live result count above them

---

## Step 2 — Applicant apply gate (3 min)

**Talking point:** Trust and verification — applicants must upload verified results before applying.

1. Log out if needed
2. Login as **simba.ndlovu@student.co.zw**
3. Browse to a scholarship → click **Apply**
4. Show redirect to `/applicant/profile?resultsRequired=1` with warning banner
5. Upload a PDF on profile (any small PDF ≤ 5 MB)
6. Return to scholarship → apply wizard opens
7. Submit application → confirmation page

**Fallback:** If upload fails, switch to **tanaka.moyo@student.co.zw** (pre-seeded with certificate).

---

## Step 3 — Provider review (3 min)

**Talking point:** Providers only see applicant data for their own opportunities.

1. Logout → login as **scholarships@uk.gov.zw**
2. Open provider applications list (`/provider/applications`)
3. Open an application review — show academic profile card
4. Click **View results certificate** — inline PDF (200 OK)
5. Mention: unrelated provider receives 403 (covered by automated tests)

---

## Step 4 — Admin verification (3 min)

**Talking point:** Platform gatekeeping — only verified organisations publish scholarships.

1. Logout → login as **admin@scholarzim.co.zw**
2. Open `/admin/dashboard` — pending providers section
3. Show **pending.provider@org.co.zw**
4. Approve provider → flash success message
5. Download provider registration certificate from admin link
6. Open **Audit log** — show recent LOGIN, APPROVE, VIEW events

**Optional:** Reject a test provider if time permits.

---

## Step 5 — What the award is worth, and who may apply (3 min)

**Talking point:** The two things a student compares first, and the difference between a
weighting and a rule.

1. Still as **provider@scholarzim.co.zw**, open **Post a scholarship**
2. Scroll to **What the award is worth** — enter a value, currency, and number of awards
3. Scroll to **Hard eligibility rules** — set a minimum points figure well above 14 (the
   demo student states 14 points at A-Level) and note the warning: *these disqualify, they
   do not merely score down*
4. Submit, then approve it as the admin
5. Log in as **student@scholarzim.co.zw** and open the listing: the fit panel shows **"You
   are not eligible"** with the reason, not a mid-range percentage
6. Edit the listing to drop the rule, and show the same listing scoring normally

**Talking point:** Browse `/scholarships` and sort by **Award value (highest)**. Listings
that state no value sort last under both value orderings — a missing figure is not a
zero-value award.

---

## Step 6 — Alerts, withdrawal, and a question (3 min)

**Talking point:** The parts of the workflow that keep people coming back.

1. As the student, filter the scholarship list, then press **Alert me about this search**
   and name it
   everything already published counts as seen
3. Approve a new matching listing as the admin, run the command again — one alert; run it
   a third time — nothing, because the high-water mark has moved
4. As the provider, open an application and set it to **Information requested** with a
   question
5. As the student, open the application: the question and a reply box are on the page. Send
   an answer, and show it back on the provider's review screen
6. As the student, withdraw a different application, and show it can be applied to again

---

## Step 7 — Tuning the algorithm (2 min)

**Talking point:** The weights are a design decision the platform can revisit, not a
constant compiled into the code.

1. As the admin, open **ScholarFit weights**
2. Move a slider — the running total turns red and Save is disabled until it reads 100
3. Set field of study to a much lower weight, save, and reload a student's matches: the
   ranking has changed
4. Press **Reset to defaults** to restore the shipped weighting

---

## Step 8 — Quality evidence (2 min)

**Talking point:** Automated verification of business rules.

1. In terminal: `php artisan test` — 111 tests, green
2. Mention CI on GitHub: feature tests + a migrate/rollback smoke run against MySQL 8
3. Point to `docs/manual-qa-checklist.md` and `docs/evaluation.md`

---

## Step 9 — Security highlights (2 min)

Cover briefly (see [security.md](security.md)):

- bcrypt passwords, role-based URL access
- Secured file downloads (not public `/uploads/**`)
- Rate limiting on login and provider registration
- CSRF on forms and applicant APIs; secure session cookies in production

Worth demonstrating live if there is time:

   authenticator app, and confirm it
2. Sign out and back in: the correct password now lands on a challenge page, **not** on the
   dashboard — no session is granted until the code is right
3. Use a recovery code instead, and show the remaining count drop by one
4. Point out **Sign out all other sessions** and the audit entries the whole exchange wrote

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Database connection refused | `docker compose up -d mysql` and wait for healthy status |
| Empty scholarship list | Use `demo` profile so seeder runs; or delete DB volume and restart |
| Login fails | Check account status (pending provider cannot login as active) |
| Certificate download 404 | Re-run demo profile to recreate stub PDFs on disk |
| No email arrives | Mail is queued — run `php artisan queue:work`, or check `php artisan queue:failed` |
| Page loads unstyled | Run `npm install && npm run build`, or accept the unminified fallback |
| Alert job sends nothing | Expected on the first run: only listings published *after* the search was saved count |
