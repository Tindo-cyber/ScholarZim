# ScholarZim (Laravel 10)

Laravel 10 port of the ScholarZim scholarship platform, previously a Spring Boot
application. The database schema, column names, and business rules are carried over
unchanged, so the same MySQL database can back either application.

## Requirements

- PHP 8.1+ (developed against 8.4)
- Composer 2
- MySQL 8 (SQLite works for local runs and the test suite)

## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

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

Two daily reminder jobs, driven by Laravel's scheduler:

| Command | Runs | What it does |
|---------|------|--------------|
| `scholarzim:deadline-reminders` | 08:00 | Notifies applicants with a live application to a scholarship closing within 3 days, and anyone who saved it but has not applied |
| `scholarzim:profile-reminders` | 09:00 | Nudges active applicants whose profile or results certificate is incomplete |

Both are idempotent — one reminder per user per record — so a repeated run sends nothing
twice. Either can be invoked directly for a demo. In production the container supervises
`schedule:run`; locally, run it yourself:

```bash
php artisan schedule:run
```

`SmsService` is called by the deadline job but logs the message rather than dispatching it;
the delivery path is wired end to end and awaits a gateway.

## ScholarFit

`app/Services/ScholarFit/ScholarFitEngine.php` scores a profile against a listing out of
100, with the original weights: academic record 20, education level 25, field of study 25,
location 15, deadline 10, results certificate 5. It returns a per-dimension breakdown, the
criteria met, and what is holding the score back — the UI renders all three.

## Front end

Styling comes from the BVite Bootstrap 5 admin theme in the `pfn-ui` workspace folder. Its
compiled stylesheets and script are referenced from `public/assets/bvite/`; all Blade markup
is written for this application on top of Bootstrap 5 conventions, with ScholarZim-specific
structure in `public/assets/css/scholarzim.css`. The theme palette is set once via
`data-bvite="theme-Mariner"` on `<body>` in each layout.

Charts on the admin analytics page are inline SVG/CSS, so no charting library is required
at runtime.

## Tests

```bash
php artisan test
```

32 tests, 130 assertions.

- `SmokeTest` renders every authenticated page for each role and checks the role guards.
- `WorkflowTest` covers the moderation gate, the apply-once rule, provider decisions, and
  ScholarFit ranking.
- `ReportExportTest` downloads every PDF and Excel export, asserting real file signatures
  and that non-admins are refused.
- `ReminderJobTest` covers both reminder jobs, including idempotency and the deadline
  window boundary.
- `SecurityHeadersTest` asserts the CSP and hardening headers, and that `script-src` has
  not been relaxed.

## Running in Docker

```bash
docker compose up --build
```

Serves http://localhost:8000 with MySQL and MailHog (http://localhost:8025), seeded with
demo data. The image runs nginx + PHP-FPM and supervises the scheduler tick.

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for production, and [SUBMISSION.md](SUBMISSION.md)
for the documentation index.

## Notes

- Composer's audit check is disabled for this project (`audit.block-insecure=false`),
  because every Laravel 10 release now carries a published security advisory — Laravel 10
  is past its security-support window. Upgrading to a supported major is the real fix.
- Analytics month bucketing is driver-aware (MySQL / SQLite / Postgres); MySQL is the
  production target.
