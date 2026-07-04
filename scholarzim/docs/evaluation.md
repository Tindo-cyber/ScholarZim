# ScholarZim Evaluation Evidence

This document supports viva/defense questions about how the system was verified.

## Automated testing

**Command:** `cd scholarzim && mvn clean test`

**Last verified:** 71 tests, 0 failures (local Maven Surefire)

| Suite | Tests | What it proves |
|-------|-------|----------------|
| AuthMvcTest | 9 | Login/register pages, auth flows |
| SecurityMvcTest | 8 | Role access, secured downloads, `/uploads/**` blocked |
| AdminVerificationMvcTest | 6 | Admin approve/reject, certificate download, RBAC |
| ApplicantResultsMvcTest | 5 | Results certificate upload and apply gate |
| ApplicationFlowMvcTest | 3 | Quick apply, wizard submit, duplicate handling |
| ApplicantProfileMvcTest | 2 | Profile validation and updates |
| ProviderRegistrationMvcTest | 5 | Provider registration and certificate rules |
| PublicApiMvcTest | 3 | Public REST endpoints |
| ApplicantApiMvcTest | 3 | Authenticated applicant API |
| Unit tests (service layer) | ~20 | Business rules without Spring context |
| FlywayMigrationIT | 1 | MySQL migrations V2–V5 (CI only) |
| FlywaySpringBootIT | 1 | Spring Boot + Flyway profile (CI only) |

## Continuous integration

GitHub Actions workflow (`.github/workflows/ci.yml`):

1. **test** — `mvn clean test` on every push/PR
2. **flyway-smoke** — MySQL 8 service container, runs Flyway integration tests

## Manual QA

Checklist: [manual-qa-checklist.md](manual-qa-checklist.md)

Run before viva and record:

- Browser used
- Date
- Any failures with steps to reproduce

## Non-functional notes

| Aspect | Observation |
|--------|-------------|
| Test suite runtime | ~90–120 seconds full `mvn clean test` on typical laptop |
| Database | H2 in-memory for fast tests; MySQL validated in CI Flyway job |
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
