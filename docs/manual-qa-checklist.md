# Manual QA checklist

Use this checklist before a demo, release, or after significant changes to verification flows.
Run `php artisan test` in the project root first — all automated tests should pass.

This file was corrected against the current codebase: several items below used to describe
bulk application decisions, an "information requested" status, saved-search alerts, and
two-factor authentication, none of which exist any more — they were scoped out on 2026-08-31
along with SMS, alongside the public JSON API. Checking for them would be checking for
something that was deliberately removed, not a regression.

---

## Applicant profile and results certificate

- [ ] A new applicant can save a profile with no results certificate — nothing blocks *saving
      the profile itself*
- [ ] The profile page shows a completeness badge (In progress / Complete) and a checklist
      naming exactly what is missing
- [ ] Choosing a different education level changes which fields the profile form shows
      (field of study, year of study, and document type all follow the level) without a page
      reload
- [ ] Setting education level to Primary shows a Guardian details card and hides the
      document-upload card entirely; saving without guardian name, phone and relationship is
      refused
- [ ] A date of birth that is impossible for the selected education level (e.g. Primary plus an
      adult's date of birth) is refused with a clear reason; a mature Undergraduate or
      postgraduate applicant is never refused purely for age
- [ ] Clicking **Apply** on a listing the applicant is eligible for works regardless of profile
      *completeness* (missing optional fields), but a listing the applicant is not eligible for
      refuses the submission and the wizard does not offer a Submit button for it
- [ ] A listing a provider has marked as requiring proof of academic results shows
      **Requirements not met** in recommendations for an applicant without one, and scores
      normally for one who has it
- [ ] Replacing a certificate or transcript on the profile makes the old file unreachable via a
      public path

## Education pathway and eligibility

- [ ] A Primary profile can apply only to a Form 1-targeted listing; every other target level is
      refused, both in recommendations (no percentage shown) and on a direct application attempt
- [ ] An O-Level profile can apply to an A-Level-, Polytechnic/Diploma-, or Undergraduate-targeted
      listing, but never a Postgraduate, Masters or PhD one
- [ ] An A-Level profile can apply to a Polytechnic/Diploma- or Undergraduate-targeted listing,
      but never a Postgraduate, Masters or PhD one
- [ ] A listing with an explicit **minimum qualifying level** narrower than its target (e.g.
      Undergraduate-targeted but requiring at least A-Level) refuses an applicant below that
      floor even though the general pathway would otherwise allow them
- [ ] No GPA field exists anywhere in the applicant profile, provider listing form, or
      ScholarFit scoring config

## Provider verification

- [ ] Provider registration requires a certificate PDF, organisation type, and registration
      number
- [ ] A pending provider can sign in and see their dashboard, but cannot publish a listing
- [ ] Admin can approve or reject a pending provider; the certificate can be viewed either way
- [ ] A non-admin cannot reach the provider-certificate route directly by URL

## Provider review

- [ ] The applicant's academic profile (level, institution, field, province, results summary)
      is shown on the review page
- [ ] The results certificate, if on file, opens inline for the listing's own provider
- [ ] A different provider gets refused (403) attempting the same certificate URL

## Award value and ScholarFit eligibility

- [ ] Posting a listing with a stated award value shows it on the card and detail page
- [ ] A blank award value stores nothing (not zero) and reads "Value not stated"
- [ ] Sorting by award value puts stated values first, unstated ones last, in both directions
- [ ] A student who fails a hard eligibility rule sees **Requirements not met** and the reason,
      not a percentage, and that listing does not appear in their recommendations
- [ ] A student missing the field a rule needs to check (e.g. no date of birth for an age
      limit) is prompted to fill it in, not silently refused

## Application lifecycle

- [ ] One student cannot submit a second application to the same listing
- [ ] A provider decision (Accept or Reject) is refused without a written reason
- [ ] Accepted and Rejected are both final — neither can later become the other
- [ ] Withdrawing a Pending application notifies the provider and frees the listing for a
      fresh application from the same student
- [ ] An Accepted or Rejected application cannot be withdrawn

## Security and ops

- [ ] Uploaded documents are not reachable under a public path — every download goes through
      an authenticated route
- [ ] Dark mode: dashboards and auth screens remain readable
- [ ] "Sign out all other sessions" ends a session open in a second browser
- [ ] Account deletion refuses without the typed confirmation, and refuses for a provider with
      live listings
- [ ] `/health` returns 200
- [ ] Queued mail is delivered once a worker is running (`php artisan queue:work`), and
      `php artisan queue:failed` is empty
- [ ] A student cannot open another student's application, document, or notification by
      guessing its URL; a provider cannot do the same to another provider's applicant

## Design and accessibility

- [ ] Tab from the top of any page: the first stop is "Skip to main content", and it works
- [ ] At phone width, tables read as cards with visible labels rather than overflowing
- [ ] At phone width with the sidebar closed, tabbing does not reach its links
- [ ] A closing-soon listing shows a countdown that escalates inside 7 and 3 days
- [ ] Print preview of an application: no navigation, no buttons, link URLs shown
- [ ] The profile completion indicator matches the checklist beside it

## Regression

- [ ] Student and provider registration and login flows work
- [ ] Forgot password / reset password flows work
- [ ] Scholarships browse, search, filter, and save work
- [ ] Provider dashboard loads; an Accept/Reject decision notifies the applicant
- [ ] Applicant dashboard and "my applications" list load correctly
- [ ] Sorting and filter chips survive paging
- [ ] With `npm run build` run, pages load hashed assets from `/build`; without it, the
      unminified fallback still renders

---

## Demo accounts

Seeded by `database/seeders/DatabaseSeeder.php`, password `ChangeMe123` for all:

| Role | Email | State |
|------|-------|-------|
| Admin | `admin@scholarzim.co.zw` | Active, super admin |
| Provider (verified) | `provider@scholarzim.co.zw` | Active |
| Provider (pending) | `trust@scholarzim.co.zw` | Pending verification |
| Applicant, Undergraduate | `student@scholarzim.co.zw` | Active, full document set incl. transcript |
| Applicant, A-Level (incomplete) | `chipo.ncube@scholarzim.co.zw` | Active, no documents |
| Applicant, Primary | `kudzai.marufu@scholarzim.co.zw` | Active, guardian details on file |
| Applicant, O-Level | `farai.sibanda@scholarzim.co.zw` | Active, results certificate |
| Applicant, A-Level | `tanaka.chirwa@scholarzim.co.zw` | Active, results certificate |
| Applicant, Masters | `blessing.moyana@scholarzim.co.zw` | Active, transcript on file |

Record any failures with browser, role, URL, and steps to reproduce.
