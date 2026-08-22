# ScholarZim Evaluation Evidence

This document supports viva/defense questions about how the system was verified.

## Automated testing

**Command:** `cd ScholarZim && php artisan test`

**Last verified:** 29 tests, 118 assertions, 0 failures (PHPUnit)

| Suite | Tests | What it proves |
|-------|-------|----------------|
| SmokeTest | 7 | Public, applicant, provider and admin pages render; role gates and guest redirects hold; unapproved listings stay invisible |
| WorkflowTest | 5 | Moderation queue, approval publishing, apply-once rule, provider decision reasons, ScholarFit ranking |
| ReportExportTest | 10 | All four PDF and three Excel exports download with the right content type; the hub renders; non-admins and guests are refused |
| ReminderJobTest | 6 | Deadline reminders reach pending applicants and savers, stay idempotent, ignore deadlines outside the window; profile reminders nudge once and skip complete profiles |
| ExampleTest | 1 | Framework smoke test |

## Continuous integration

GitHub Actions workflow (`.github/workflows/ci.yml`):

1. **test** — `php artisan test` with coverage on every push/PR
2. **migration-smoke** — MySQL 8 service container: migrate, seed, then roll all migrations back, which proves every migration is reversible

A separate `security.yml` workflow audits Composer dependencies weekly. It replaced the Spring app's CodeQL job, since CodeQL has no PHP analyzer.

## Manual QA

Checklist: [manual-qa-checklist.md](manual-qa-checklist.md)

Run before viva and record:

- Browser used
- Date
- Any failures with steps to reproduce

## Non-functional notes

| Aspect | Observation |
|--------|-------------|
| Test suite runtime | ~3 seconds for the full `php artisan test` run on a typical laptop |
| Database | SQLite in-memory for fast tests; MySQL validated in the CI migration-smoke job |
| File uploads | PDF validation, 5 MB limit, path traversal rejected |
| Rate limiting | Login/register throttled per IP (in-memory) |

## Usability observations (template)

Fill in after a peer walkthrough:

1. Applicant apply gate clearly communicates missing certificate requirement.
2. Provider review screen surfaces academic context before status change.
3. Admin pending queue separates verification from day-to-day user management.
4. Dark mode remains readable on dashboard and auth screens.
5. Mobile navigation accessible on applicant dashboard.

## Known gaps (documented future work)

- SMS channel is log-only (no external gateway).
- Browser E2E not automated (manual checklist used instead).
- API layer covers public catalog and applicant features only.
- Laravel 10 is past its security-support window, so the open framework advisories reported by `composer audit` have no 10.x fix available; the audit job is advisory-only until the app moves to a supported release.
