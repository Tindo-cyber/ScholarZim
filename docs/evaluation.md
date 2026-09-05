# ScholarZim Evaluation Evidence

This document supports viva/defense questions about how the system was verified.

## Automated testing

**Command:** `cd ScholarZim && php artisan test`

**Last verified:** 2026-09-05 — 597 passed, 2 warnings, 1 skipped, 0 failures, 1875 assertions.

The exact count above will drift as the suite grows; the command is the source of truth, not
this number. The skip is a Windows-only `finfo` limitation in one document-type test, not a
gap in coverage — it runs (and passes) on Linux/CI.

Coverage by area rather than a per-file table, because the file-by-file version of this
table went stale the first time a suite was renamed and nobody caught it:

| Area | What is covered |
|------|------------------|
| Authentication | Session login/logout for all three roles, wrong password, unknown email, throttling, suspended accounts, bcrypt hashing, the legacy `password_hash` column mapping |
| Application workflow | Submit → Pending → Accept/Reject, duplicate-application blocking, withdrawal and re-application, decision reason required, ownership enforcement, concurrent-submission handling |
| ScholarFit | Weighted scoring, the hard-requirement pass/fail path, eligibility edge cases, ranking order, location scoring |
| Authorization | Cross-account access to applications/documents/notifications, provider-to-provider isolation, admin-only routes, suspension ending a live session |
| Discovery | Browse, keyword search, filters, saved scholarships |
| Provider verification | Registration, admin approval/rejection, the `account.active` gate on publishing |
| Notifications | In-app + email delivery, category preferences, deadline reminders, idempotent re-runs |
| Reports | All PDF and Excel exports download with the right content type; non-admins and guests are refused |
| Security | CSRF, security headers, HSTS behind a trusted proxy, document access control |
| PWA | Manifest and service worker content, precache scope (no private pages, no non-GET requests cached), offline fallback |
| Database / deployment | TLS certificate-authority path resolution, the container entrypoint's `APP_KEY` and CA-permission handling, empty-database safety for the public pages |
| Storage configuration | The private disk's root is read from `FILESYSTEM_ROOT` rather than hard-coded; documents are written under whatever root is configured, a root change does not silently resolve an old file, a document copied to a new root resolves under its original relative path unchanged, and download authorization is unaffected by where the disk physically points |
| Education pathway / eligibility | All fifteen brief-specified pathway scenarios (Primary→Form 1 through A-Level→PhD), a hard pathway failure as a real eligibility block (not a scoring penalty), a listing's own stricter minimum level, no GPA field anywhere, a transcript on file not manufacturing an academic score by itself, a missing locality never reducing a province-wide match, and a real HTTP submission refused for a pathway violation - not only excluded from recommendations |

## Continuous integration

GitHub Actions workflow (`.github/workflows/ci.yml`):

1. **test** — `php artisan test` with coverage on every push/PR
2. **migration-smoke** — MySQL 8 service container: migrate, seed, then roll all migrations back, which proves every migration is reversible

A separate `security.yml` workflow audits Composer dependencies weekly (`composer audit`, currently reporting no advisories). It replaced the Spring app's CodeQL job, since CodeQL has no PHP analyzer.

## Manual QA

Checklist: [manual-qa-checklist.md](manual-qa-checklist.md)

Run before viva and record:

- Browser used
- Date
- Any failures with steps to reproduce

## Non-functional notes

| Aspect | Observation |
|--------|-------------|
| Test suite runtime | On the order of two minutes for the full `php artisan test` run on a typical laptop; timing varies with hardware, so treat the CI run as authoritative |
| Database | SQLite in-memory for fast tests; MySQL 8 validated in the CI migration-smoke job and against a real Aiven MySQL instance for TLS behaviour |
| File uploads | PDF validation, size limits, path traversal rejected |
| Rate limiting | Login/register throttled per IP (in-memory) |

## Usability observations (template)

Fill in after a peer walkthrough:

1. An incomplete applicant profile is communicated as a checklist and a completeness badge -
   confirm a reviewer understands the difference between an unfinished but eligible profile
   (applying is still allowed) and a genuinely ineligible one (education pathway, a listing's
   stated requirements including a required certificate): the wizard does not offer a Submit
   button for the latter, and `ApplicationService::submit()` refuses it server-side even if
   reached directly.
2. Provider review screen surfaces academic context before status change.
3. Admin pending queue separates verification from day-to-day user management.
4. Dark mode remains readable on dashboard and auth screens.
5. Mobile navigation accessible on applicant dashboard.

## Known gaps (documented, deliberate scope)

- SMS is not implemented. It was scoped out along with 2FA, saved-search alerts, bulk
  moderation of applications, and the public JSON API when the project was narrowed to its
  five objectives on 2026-08-31. Reintroducing it is a scope decision, not a bug fix.
- Browser E2E is not automated (the manual checklist above is used instead).
- `composer audit` reports no advisories; the `audit.block-insecure=false` setting in
  `composer.json` is a leftover from the Laravel 10 era and no longer reflects a real
  constraint — safe to remove whenever someone is next in that file.
