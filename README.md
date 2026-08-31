# ScholarZim (Laravel 10)

Laravel 10 port of the ScholarZim scholarship platform, previously a Spring Boot
application. The database schema and column names are carried over, so the same MySQL
database can back either application.

## What the platform does

Five objectives, and every feature in the codebase serves at least one of them:

1. **Discovery** — students search, filter and save scholarship opportunities.
2. **ScholarFit** — a recommendation mechanism that matches students to opportunities from
   their profile. It recommends; it never decides.
3. **Applications** — students submit and track applications.
4. **Providers** — verified providers publish opportunities and manage the applications to
   them.
5. **Security and administration** — authentication, roles, provider verification, listing
   moderation, and admin controls.

The core journey, end to end:

> A student searches, filters and saves listings, gets ScholarFit recommendations, and
> applies → the application is **Pending** → the provider **accepts** or **rejects** it with
> a written reason → the student is notified by email and in the app.

## Requirements

- PHP 8.1+ (developed against 8.4)
- Composer 2
- Node 20+ and npm (front-end bundle only)
- MySQL 8 (SQLite works for local runs and the test suite)

## Getting started

```bash
composer install
npm install && npm run build     # ScholarZim's own CSS/JS, hashed by Vite
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Mail is queued, so run a worker alongside `serve` if you want it delivered:

```bash
php artisan queue:work
```

Skipping `npm run build` will not break the site: without a manifest the source
files are served individually and unminified. Nothing about a fresh clone needs
`public/build` or `public/hot` to exist, and neither is committed. See
[Front end](#front-end).

Seeded accounts (all password `ChangeMe123`):

| Role     | Email                        |
|----------|------------------------------|
| Admin    | admin@scholarzim.co.zw       |
| Provider | provider@scholarzim.co.zw    |
| Student  | student@scholarzim.co.zw     |

## Layout of the port

| Concern            | Location                                              |
|--------------------|-------------------------------------------------------|
| Eloquent models    | `app/Models`                                          |
| Business services  | `app/Services`                                        |
| ScholarFit scoring | `app/Services/ScholarFit`                             |
| Constants/enums    | `app/Support`                                         |
| Controllers        | `app/Http/Controllers` (Admin, Applicant, Provider, Auth) |
| Role guards        | `app/Http/Middleware/EnsureUserHasRole.php`           |
| Response hardening | `app/Http/Middleware/SecurityHeaders.php`             |
| Scheduled jobs     | `app/Console/Commands`, wired in `app/Console/Kernel.php` |
| Report exports     | `app/Services/ReportService.php`, `app/Services/ExcelReportService.php` |
| Routes             | `routes/web.php`                                      |
| Layouts            | `resources/views/layouts` (app, public, auth)         |
| Components         | `resources/views/components`                          |
| Views              | `resources/views/{public,auth,applicant,provider,admin,applications,opportunities,notifications,account}` |
| Report templates   | `resources/views/reports`                             |
| Container config   | `docker/` (nginx, php.ini, supervisor, entrypoint)    |
| Runtime settings   | `config/scholarfit.php` + `app/Services/SettingsService.php` |
| Front-end sources  | `resources/css`, `resources/js` (bundled by Vite)     |

## The application workflow

The whole of it, in one line:

> A student applies → the application is **Pending** → the provider **accepts** or
> **rejects** it with a written reason → the student is notified → done.

There are three application statuses and no others: `PENDING`, `ACCEPTED`, `REJECTED`.
**Accepting an application is granting the scholarship** — there is no separate award step
afterwards, nothing further for the provider to click, and nothing for the student to
confirm. Both decisions are final: an accepted application can never become rejected, and a
rejected one can never become accepted.

`WITHDRAWN` exists alongside those three, but it is the applicant's own action rather than
a provider decision, and it is the only status that leaves the listing open to a fresh
application.

| Who | Can set | From |
|-----|---------|------|
| Provider | `ACCEPTED`, `REJECTED` (reason required) | `PENDING` |
| Applicant | `WITHDRAWN` | `PENDING` |
| Applicant | `PENDING` (re-applying) | `WITHDRAWN` |

`app/Support/ApplicationStateMachine.php` is the single place those rules live;
`ApplicationService::decide()` is the only way an application is ever decided. Statuses
written by earlier versions of the platform (`SUBMITTED`, `UNDER_REVIEW`, `SHORTLISTED`,
`INTERVIEW`, `WAITLISTED`, `DOCUMENTS_REQUESTED`, `INFO_REQUESTED`, `APPROVED`, `AWARDED`)
are mapped onto the three live ones by `ApplicationStatus::canonical()` and rewritten in the
database by migration `2024_01_01_000028`. They are kept as `LEGACY_*` constants for
database compatibility only and appear nowhere in the UI or in new business logic.

## Rules worth knowing

- **Moderation gates publication.** A provider submits a listing as `PENDING`; it is
  invisible to the public and unapplyable until an admin approves it. Approval is also
  the moment applicants are notified — announcing earlier would push unreviewed listings
  out by email.
- **Provider accounts start inactive.** A provider can sign in and see their dashboard
  while verification is pending, but the publishing routes require an active account
  (`account.active` middleware).
- **Decisions need a reason.** Accepting or rejecting an application requires written
  feedback; it is shown to the applicant verbatim, in the notification and on their
  application page.
- **One student + one scholarship = one application.** Enforced in
  `ApplicationService::submit()` and by the `uk_applications_user_opportunity` unique key.
  Pending, accepted and rejected all block a second attempt; only a withdrawal reopens the
  listing, and re-applying reuses the same row rather than inserting a second one.
- **Uploads never sit in `public/`.** Files go to the private disk and are served through
  `FileDownloadController`, so every read is authorised and sensitive reads are audited.
- **Email preferences gate email only.** In-app notifications are always written; the
  three category toggles decide what gets emailed.
- **ScholarFit recommends; the provider decides.** The match percentage answers "how well
  does this scholarship fit this student's profile?" and nothing else. It never decides
  who gets a scholarship — that is the provider's Accept/Reject, made by a person with a
  written reason.
- **Stated requirements zero the match.** A requirement the listing states and the profile
  does not meet drops the listing out of that student's recommendations rather than
  reducing its score, because a high percentage next to "you do not meet this rule" would
  be a lie. A requirement the provider did not set is never held against anyone.
- **Withdrawal is the applicant's own decision**, so it does not lock them out: a withdrawn
  application can be replaced with a fresh one while the listing is still open. The row
  stays either way, so the provider sees the seat released rather than the record vanishing.
- **One application decision at a time.** There is no bulk accept or reject: a decision
  carries a written reason that a student reads verbatim, so it is made on one application
  by a person looking at it. Administrators can still moderate *listings* in bulk, which
  carries no per-student message.

## Admin reports

`/admin/reports` exports platform data as PDF (dompdf) or Excel (PhpSpreadsheet):

| Export | Formats |
|--------|---------|
| Users | PDF, Excel |
| Opportunities | PDF, Excel |
| Applications | PDF, Excel |
| Recommendations (per applicant, with match scores) | PDF |

PDFs are composed from Blade views in `resources/views/reports`; the spreadsheets keep the
same sheet names and column order the Spring exporter used, so archived files stay
comparable. Both exporters load the full table into memory — fine at FYP scale, but they
should be paginated or streamed before a large production dataset.

## Scheduled jobs

Daily jobs, driven by Laravel's scheduler:

| Command | Runs | What it does |
|---------|------|--------------|
| `scholarzim:deadline-reminders` | 08:00 | Notifies applicants with a pending application to a scholarship closing within 3 days, and anyone who saved it but has not applied |
| `scholarzim:archive-expired-opportunities` | 00:30 | Retires listings whose deadline has passed, so the catalogue only shows what can still be applied for |

Both are idempotent — one reminder per user per record — so a repeated run sends nothing
twice: the deadline job checks for an existing notification before writing one. Any can be invoked directly for a demo. In production the container supervises
`schedule:run`; locally, run it yourself:

```bash
php artisan schedule:run
```

## ScholarFit

ScholarFit is the recommendation component: given a student's profile and a listing, it
answers **"how well do these match?"** as a percentage. It is a decision *support* tool, not
the decision — the provider accepts or rejects, by hand, with a reason.

`app/Services/ScholarFit/ScholarFitEngine.php` scores a profile against a listing out of
100.

**Weighted dimensions** decide how good a match is; a miss costs points. The defaults are
the original weights — academic record 20, education level 25, field of study 25, location
15, deadline 10, results certificate 5 — and they now live in `config/scholarfit.php`. An
administrator can retune them at `/admin/scholarfit`; the override is stored in
`platform_settings` and read through `SettingsService`, which falls back to the config
file, so deleting the row restores the shipped weighting. The six must total 100, since
every score is presented to students as a percentage.

**Stated requirements** are checked alongside them by
`app/Services/ScholarFit/EligibilityEvaluator.php`: minimum A-Level points, an age ceiling,
a required citizenship or province, a results certificate on file. Each is one plain check
producing one plain sentence, and any unmet requirement forces the match to 0% and keeps
the listing out of that student's recommendations. A requirement the provider did not set
is never held against anyone.

The engine returns a per-dimension breakdown, the score, and what is holding it back. Each
dimension shortfall carries the profile field that fixes it, so the UI renders it as a link
rather than a complaint. Rankings are computed on demand rather than cached — a catalogue
sweep is two queries and a sort, which is cheaper than proving a cached ranking is still
true.

## Email

Every outbound message goes through one path:

```
EmailService  ->  ScholarZimMail (queued)  ->  the mailer in config/mail.php
```

Which transport is used where — three setups, no ambiguity:

| Where | `MAIL_MAILER` | Transport | Set by |
|-------|---------------|-----------|--------|
| **Production** | `mailgun` | Mailgun HTTP API | `.env.prod.example` + `docker-compose.prod.yml` |
| **Local Docker** | `smtp` | Bundled MailHog, UI at http://localhost:8025 | `docker-compose.yml` (overrides the file) |
| **Local, no Docker** | `log` (recommended) | Written to `storage/logs` | you, in `.env` |

`.env.example` defaults to `mailgun` because that is the production path; on a fresh clone
without a Mailgun key, set `MAIL_MAILER=log` and read the verification link out of the log.
`docker compose up` needs no change — it overrides the variable itself.

Credentials are read from the environment through `config/services.php` (`MAILGUN_DOMAIN`,
`MAILGUN_SECRET`, `MAILGUN_ENDPOINT`) and are never committed: both tracked templates ship
placeholders, and `.env` and `.env.docker` are gitignored.

What gets emailed:

| Trigger | Recipient |
|---------|-----------|
| Student registers | Verification link to the student |
| Password reset requested | Reset link to the requester |
| Provider registers | Verification queue notice to every administrator |
| Provider approved or rejected | The provider |
| Student applies | The listing's provider |
| Application accepted | The student |
| Application rejected | The student, with the provider's reason |
| Listing approved / rejected / closing soon | Provider or matching applicants |

`EmailService` returns whether delivery succeeded rather than only logging it, so the paths
where the email *is* the deliverable — verification, password reset — can report a failure
instead of claiming success. Notification paths deliberately ignore the result: one bounced
recipient must not fail an administrator's action.

Delivery is queued (`QUEUE_CONNECTION=database`), so nothing is sent without a worker
running. `TransactionalEmailTest` fakes the transport and asserts each message above is
actually handed to the mailer.

## Front end

Styling comes from the BVite Bootstrap 5 admin theme in the `pfn-ui` workspace folder. Its
compiled stylesheets and script are referenced statically from `public/assets/bvite/` — the
theme ships compiled and is never edited here, so it has nothing to gain from a build step.
The palette is set once via `data-bvite="theme-Mariner"` on `<body>` in each layout.

Every stylesheet and script in the document head comes from a single partial,
`resources/views/partials/assets.blade.php`, which all four layouts (`app`, `auth`, `public`,
`errors/layout`) include and none of them supplement. That is a rule rather than a
preference: when each layout kept its own copy of the list they drifted, and the drift was
invisible from the page that caused it — the error layout pointed at an
`assets/css/scholarzim.css` that has never existed, so every 404 and 500 rendered without
ScholarZim's own styles, and the auth layout was the only one omitting `theme-toggle.js`, so
signing in ignored a saved dark mode. `HeadAssetsTest` enforces both halves of the rule.

Link order inside that partial is load-bearing. The vendor theme defines a bare `main` with
its own dashboard grid (`grid-template-columns: 120px auto`) and `scholarzim.css` overrides
it back to `display: block`. Equal specificity means document order is the only reason the
override wins; reversed, every page collapses into that 120px first column while still
looking styled enough to seem deliberate.

ScholarZim's own CSS and JS live in `resources/css` and `resources/js` and go through Vite,
so they are minified and content-hashed and a deploy cannot serve a stale stylesheet out of
a browser cache. `resources/views/partials/assets.blade.php` emits the built tags; with no
manifest present it falls back to serving the source files individually through
`SourceAssetController`, so a missing `npm run build` degrades to an unminified site rather
than a 500 on every page.

`App\Support\FrontendAssets` picks between those three paths, and it checks them rather
than trusting that they exist:

- **`public/hot`** is honoured only if something is actually listening at the address inside
  it. Laravel reads that file as "the dev server is running", but Vite deletes it only on a
  clean shutdown - close the terminal or kill the process and it stays behind for good,
  pointing every stylesheet at a port nobody is on. The vendor theme keeps loading from
  `public/assets` either way, so the result is a page styled enough to look deliberate and
  missing the app-shell overlay that stops the theme's own `grid-template-areas` from
  stacking every `<main>` child into one cell. A stale file is ignored and deleted.
- **`public/build/manifest.json`** is honoured only if the chunks it names are on disk. A
  manifest pointing at files that are gone is worse than no manifest: the tags render and
  every one of them 404s.

Both are `.gitignore`d and neither is a build input, so a `git pull` cannot hand anyone a
broken front end - the worst case is the unminified fallback.

`theme-toggle.js` is deliberately left out of the bundle: it has to run before first paint
to avoid a flash of the wrong theme, so it stays a render-blocking script in the head. It
guards on the elements it needs, so it is safe on the auth and error pages that have no
toggle to show.

Charts on both analytics pages are inline SVG/CSS, so no charting library is required at
runtime.

Design details worth knowing:

- Wide tables carry `sz-table-stack` and a `data-label` on each cell; below `md` each row
  is redrawn as a card from those labels, so there is no duplicate mobile-only markup to
  keep in step.
- Every layout opens with a skip link, and the off-canvas sidebar is `visibility: hidden`
  when closed so its links leave the tab order rather than merely moving off-screen.
- A print stylesheet strips the navigation and controls and prints link URLs, for students
  who need a paper copy of an application or a decision.

## Tests

```bash
php artisan test
```

488 tests, 1,476 assertions (one skipped on Windows only).

- `SmokeTest` renders every authenticated page for each role and checks the role guards.
- `WorkflowTest` covers the moderation gate, the apply-once rule, provider decisions, and
  ScholarFit ranking.
- `ApplicationDecisionTest` covers the whole simplified workflow: applying, the pending
  start, the duplicate rule, accept and reject with a reason, that a reason is required,
  that neither decision can become the other, that no second award step exists, the two
  notifications, and what each side sees.
- `ApplicationStateMachineTest` and `ApplicationStateTransitionTest` prove the transition
  rules and that the endpoints are actually behind them.
- `ApplicationLifecycleTest` covers withdrawal and re-application after it.
- `ScholarshipDiscoveryTest` covers Objective 1: browsing, keyword search, filtering by
  field and level, and saving/unsaving a scholarship.
- `TransactionalEmailTest` asserts at the mailer that the provider is emailed when a student
  applies, the student is emailed on acceptance and rejection, administrators are emailed
  when a provider registers, and that no mail credential is hard-coded.
- `RecommendationTest` covers what reaches the recommendations page: usable percentages,
  best-first order, and the listings deliberately left out.
- `ScholarFitEligibilityTest` is the scoring truth table: which requirements zero a match,
  what a blank profile field says, and that scores follow the configured weights.
- `AwardAndDiscoveryTest` covers award values, value sorting and filtering, and the view
  counter behind the provider's numbers.
- `AccountSecurityTest` covers signing out other sessions and account deletion (including
  the provider refusal while listings are live).
- `AdminSettingsTest` covers the ScholarFit weight editor and bulk moderation.
- `ReportExportTest` downloads every PDF and Excel export, asserting real file signatures
  and that non-admins are refused.
- `ReminderJobTest` covers the reminder jobs, including idempotency and the deadline
  window boundary.
- `SourceAssetTest` covers the no-build asset fallback, its whitelist, a stale `public/hot`
  not reaching the page, and the fallback script list not drifting from `app.js`.
- `FrontendAssetsTest` covers the delivery-path decision for every state a checkout can be
  in, including a dev server that is listening and one that is not.
- `HeadAssetsTest` asserts no view references a missing asset, that every layout takes its
  head assets from the shared partial and adds none of its own, that all five error pages get
  the full stylesheet set, and that the overlay is still linked after the vendor theme.
- `SecurityHeadersTest` asserts the CSP and hardening headers, and that `script-src` has
  not been relaxed.

## Running in Docker

```bash
docker compose up --build
```

Serves http://localhost:8000 with MySQL and MailHog (http://localhost:8025), seeded with
demo data. The image runs nginx + PHP-FPM and supervises both the scheduler tick and a
queue worker; the front-end bundle is built in its own `node:20-alpine` stage, so node
never ships in the runtime image.

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for production, and [SUBMISSION.md](SUBMISSION.md)
for the documentation index.

## Notes

- Mail and notifications are queued (`QUEUE_CONNECTION=database`). Approving a listing fans
  a notification out to every matching applicant; doing that inline made the administrator
  wait on one API round trip per recipient. Nothing is delivered without a worker running.
- There is **no public JSON API**. It was removed along with its Sanctum tokens, developer
  portal and OpenAPI description: none of the five objectives needs it, and it was a second
  surface onto the same data. This is unrelated to the Mailgun **email** API above, which
  is required and untouched.
- `/health` is a cheap liveness probe — one database round trip — so the platform's health
  check is not the heaviest request on the box.
- Composer's audit check is disabled for this project (`audit.block-insecure=false`),
  because every Laravel 10 release now carries a published security advisory — Laravel 10
  is past its security-support window. Upgrading to a supported major is the real fix.
- Analytics month bucketing is driver-aware (MySQL / SQLite / Postgres); MySQL is the
  production target.
