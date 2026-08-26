# ScholarZim (Laravel 10)

Laravel 10 port of the ScholarZim scholarship platform, previously a Spring Boot
application. The database schema, column names, and business rules are carried over
unchanged, so the same MySQL database can back either application.

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
| Controllers        | `app/Http/Controllers` (Admin, Applicant, Provider, Auth, Api) |
| Role guards        | `app/Http/Middleware/EnsureUserHasRole.php`           |
| Response hardening | `app/Http/Middleware/SecurityHeaders.php`             |
| Scheduled jobs     | `app/Console/Commands`, wired in `app/Console/Kernel.php` |
| Report exports     | `app/Services/ReportService.php`, `app/Services/ExcelReportService.php` |
| Routes             | `routes/web.php`, `routes/api.php`                    |
| Layouts            | `resources/views/layouts` (app, public, auth)         |
| Components         | `resources/views/components`                          |
| Views              | `resources/views/{public,auth,applicant,provider,admin,applications,opportunities,notifications,account}` |
| Report templates   | `resources/views/reports`                             |
| Container config   | `docker/` (nginx, php.ini, supervisor, entrypoint)    |
| Runtime settings   | `config/scholarfit.php` + `app/Services/SettingsService.php` |
| Front-end sources  | `resources/css`, `resources/js` (bundled by Vite)     |
| API description    | `resources/api/openapi.json`                          |

## Rules worth knowing

- **Moderation gates publication.** A provider submits a listing as `PENDING`; it is
  invisible to the public and unapplyable until an admin approves it. Approval is also
  the moment applicants are notified — announcing earlier would push unreviewed listings
  out by email.
- **Provider accounts start inactive.** A provider can sign in and see their dashboard
  while verification is pending, but the publishing routes require an active account
  (`account.active` middleware).
- **Decisions need a reason.** Approving or rejecting an application requires written
  feedback; it is shown to the applicant verbatim.
- **Uploads never sit in `public/`.** Files go to the private disk and are served through
  `FileDownloadController`, so every read is authorised and sensitive reads are audited.
- **Email preferences gate email only.** In-app notifications are always written; the
  three category toggles decide what gets emailed.
- **Eligibility disqualifies; weights only score.** A provider's hard rule that an
  applicant provably fails zeroes the match and drops the listing out of their
  recommendations — a high percentage next to "you are not eligible" would be a lie. A rule
  the profile cannot be tested against is a prompt, not a refusal.
- **Withdrawal is the applicant's own decision**, so it does not lock them out: a withdrawn
  application can be replaced with a fresh one while the listing is still open. The row
  stays either way, so the provider sees the seat released rather than the record vanishing.
- **Bulk is a shortcut for the click, not for the rules.** Every application or listing in a
  batch goes through the same single-item path: the same ownership checks, the same
  written-reason requirement, the same notifications, the same audit entries.

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
| `scholarzim:search-alerts` | 07:30 | Tells applicants when a newly published listing matches a search they saved |
| `scholarzim:deadline-reminders` | 08:00 | Notifies applicants with a live application to a scholarship closing within 3 days, and anyone who saved it but has not applied |
| `scholarzim:interview-reminders` | 08:30 | Reminds both sides about an interview happening within the next day |
| `scholarzim:profile-reminders` | 09:00 | Nudges active applicants whose profile or results certificate is incomplete |

All are idempotent — one reminder per user per record — so a repeated run sends nothing
twice — the deadline and profile jobs check for an existing notification, the interview job
stamps `interview_reminded_at` (cleared on a reschedule, so a new date earns a new
reminder), and the alert job keeps a per-search high-water mark of the last listing id it
mentioned. Any can be invoked directly for a demo. In production the container supervises
`schedule:run`; locally, run it yourself:

```bash
php artisan schedule:run
```

`SmsService` is called by the deadline job but logs the message rather than dispatching it;
the delivery path is wired end to end and awaits a gateway.

## ScholarFit

`app/Services/ScholarFit/ScholarFitEngine.php` scores a profile against a listing out of
100. It keeps two kinds of criteria deliberately apart:

**Weighted dimensions** decide how good a match is; a miss costs points. The defaults are
the original weights — academic record 20, education level 25, field of study 25, location
15, deadline 10, results certificate 5 — and they now live in `config/scholarfit.php`. An
administrator can retune them at `/admin/scholarfit`; the override is stored in
`platform_settings` and read through `SettingsService`, which falls back to the config
file, so deleting the row restores the shipped weighting. The six must total 100, since
every score is presented to students as a percentage.

**Hard eligibility rules** decide whether the applicant may apply at all; a miss zeroes the
score. A provider sets them per listing (minimum A-Level points, an age ceiling, required
citizenship or province, a results certificate on file). A rule the provider did not set is
never a disqualification, and neither is a rule the profile has no data to test — that
becomes a prompt to fill the field in, because a blank field is not evidence of
ineligibility.

The engine returns a per-dimension breakdown, the criteria met, and what is holding the
score back. Each shortfall carries the profile field that fixes it, so the UI renders it as
a link rather than a complaint. Rankings are cached per applicant, keyed on their profile's
timestamp, the catalogue version, and the weights in force, so no cached score can outlive
any of them.

## Front end

Styling comes from the BVite Bootstrap 5 admin theme in the `pfn-ui` workspace folder. Its
compiled stylesheets and script are referenced statically from `public/assets/bvite/` — the
theme ships compiled and is never edited here, so it has nothing to gain from a build step.
The palette is set once via `data-bvite="theme-Mariner"` on `<body>` in each layout.

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
to avoid a flash of the wrong theme, so it stays a render-blocking script in the head.

Charts on both analytics pages are inline SVG/CSS, so no charting library is required at
runtime.

Design details worth knowing:

- Wide tables carry `sz-table-stack` and a `data-label` on each cell; below `md` each row
  is redrawn as a card from those labels, so there is no duplicate mobile-only markup to
  keep in step.
- Every layout opens with a skip link, and the off-canvas sidebar is `visibility: hidden`
  when closed so its links leave the tab order rather than merely moving off-screen.
- A print stylesheet strips the navigation and controls and prints link URLs, for students
  taking an application to an interview.

## Tests

```bash
php artisan test
```

111 tests, 389 assertions.

- `SmokeTest` renders every authenticated page for each role and checks the role guards.
- `WorkflowTest` covers the moderation gate, the apply-once rule, provider decisions, and
  ScholarFit ranking.
- `ApplicationLifecycleTest` covers withdrawal, re-application after it, the provider's
  question and the applicant's answer, and bulk decisions (including that bulk does not
  loosen the written-reason rule or cross provider boundaries).
- `ScholarFitEligibilityTest` is the scoring truth table: which rules disqualify, which
  only prompt, and that scores actually follow the configured weights.
- `AwardAndDiscoveryTest` covers award values, value sorting and filtering, and the view
  counter behind the provider funnel.
- `SavedSearchAlertTest` covers saved searches and the alert job, including that saving a
  search does not replay the back catalogue and that a repeat run sends nothing twice.
- `TwoFactorTest` covers setup, the sign-in challenge, and single-use recovery codes.
- `AccountSecurityTest` covers API tokens, signing out other sessions, and account deletion
  (including the provider refusal while listings are live).
- `ApiV1Test` covers the versioned API, token auth, and that the catalogue never exposes
  more than the public site.
- `AdminSettingsTest` covers the ScholarFit weight editor and bulk moderation.
- `ReportExportTest` downloads every PDF and Excel export, asserting real file signatures
  and that non-admins are refused.
- `ReminderJobTest` covers all three reminder jobs, including idempotency and the deadline
  window boundary.
- `SourceAssetTest` covers the no-build asset fallback, its whitelist, a stale `public/hot`
  not reaching the page, and the fallback script list not drifting from `app.js`.
- `FrontendAssetsTest` covers the delivery-path decision for every state a checkout can be
  in, including a dev server that is listening and one that is not.
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

## Public API

`/api/v1` is read-only. The catalogue (`/scholarships`, `/stats`, `/facets`) is open and
rate-limited; `/me`, `/me/applications`, and `/me/recommendations` take a Sanctum bearer
token created on the account security page. Everything reads through the same service the
site does, so the API can never expose more than an anonymous visitor already sees.

The OpenAPI description is at `/api/v1/openapi.json` (source in `resources/api`), rendered
for humans at `/developers`. The unversioned `/api/public/*` paths the dashboard shell was
written against still answer, pointing at the same controllers.

## Notes

- Mail and notifications are queued (`QUEUE_CONNECTION=database`). Approving a listing fans
  a notification out to every matching applicant; doing that inline made the administrator
  wait on one SMTP round trip per recipient. Nothing is delivered without a worker running.
- `/health` is a cheap liveness probe — one database round trip — so the platform's health
  check is not the heaviest request on the box.
- Composer's audit check is disabled for this project (`audit.block-insecure=false`),
  because every Laravel 10 release now carries a published security advisory — Laravel 10
  is past its security-support window. Upgrading to a supported major is the real fix.
- Analytics month bucketing is driver-aware (MySQL / SQLite / Postgres); MySQL is the
  production target.
