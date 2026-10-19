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
- [ ] A Primary profile hides the transcript card; the Guardian details card shows for anyone under 18
      (or at school with no date of birth). Saving without guardian details works; SUBMITTING an
      application as a minor without all four (name, phone, relationship, tick) is refused with a
      message listing what is missing, and the provider's review page shows the guardian
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
      ScholarFit's eligibility rules

## Provider verification

- [ ] Provider registration requires the organisation name, a country of registration (nothing
      pre-selected; the filter box narrows the list), organisation type, a registration number
      **or** an explanation, a contact name and position, and a registration document
- [ ] Register organisations in Zimbabwe, the United Kingdom and South Africa: each lands in the
      same **Providers awaiting verification** queue with its country in **Registered in**
- [ ] **Review** shows country (with code), number or explanation, contact, website, document,
      submission date and history; opening the document adds a `VIEW_PROVIDER_CERTIFICATE` entry
- [ ] **Request more information**: the organisation stays pending, sees the note on its dashboard,
      updates its details or document, and returns to *Awaiting review*
- [ ] An older provider with no country shows *Not recorded* (never Zimbabwe); a pending one cannot
      be approved until an admin records the country under **Registration details**
- [ ] An approved foreign provider can post listings for Zimbabwe, another country and *Online /
      distance*; the destination is never filled in from its country of registration
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
- [ ] An Accepted application cannot be turned straight into Rejected (or the reverse): the
      page offers only **Withdraw decision and reopen**, and a direct request is refused
- [ ] **Withdraw decision and reopen** asks for a reason and a confirmation; with the reason
      empty nothing changes and the dialog opens again with the error
- [ ] Withdrawing a **Rejection** returns the application to Pending; the provider can then
      Accept it, or Reject it again with a new reason
- [ ] Withdrawing an **Acceptance** shows the warning that it reverses the award; the applicant
      is notified that the acceptance no longer stands and is not guaranteed
- [ ] With a one-slot listing: after accepting, a second applicant cannot be accepted; after
      withdrawing the first acceptance the second can, and the listing reopens if it had
      closed itself
- [ ] The applicant receives a notification at each step (decision, withdrawal, new decision)
      and sees the withdrawal and its reason on their application page
- [ ] **Decision history** on the review page lists each decision and withdrawal, oldest first,
      and the audit log keeps the original decision and its reason
- [ ] A different provider, an applicant and an administrator cannot withdraw the decision
- [ ] A reopened application still blocks a second application from the same student, and the
      applicant can still withdraw it
- [ ] Withdrawing a Pending application notifies the provider and frees the listing for a
      fresh application from the same student
- [ ] An Accepted or Rejected application cannot be withdrawn by the applicant

## Applying: reuse and documents

- [ ] The profile's **Documents** card shows one count ("N documents are required for your
      education level") and a matching progress line; the optional CV is listed but not counted
- [ ] The card's instruction reads "Upload the required documents listed below." with the hint
      "Upload clear, readable copies. Existing documents will be reused when they meet the
      requirements." and no mention of ScholarFit
- [ ] Changing the education level and saving changes the required count and the labels
- [ ] Choosing a level in the form (before saving) fills the **Field of study** list with that level's programmes, and the search box
      narrows it
- [ ] A provider's listing form offers every country (Zimbabwe and the common destinations first), plus Any country and Online
- [ ] Close the page without signing out, open it again: the password is asked for; a second tab beside an open one stays signed in
- [ ] **Field of study** is a list of the programmes at your level (never a text box); choosing one sets your programme, and the
      programme section below does not ask for it again; the apply page asks for it the same way when it is missing
- [ ] From **Certificate** upward there is no degree classification and no Academic information section; the profile is complete
      with its documents; the section appears only when a scholarship asks for earlier results
- [ ] Choosing **Primary**, **O-Level** or **A-Level** shows the results section with only that level's qualifications, changing as
      the level is changed (before saving)
- [ ] **Apply** with everything on file goes straight to the statement: no profile field and no
      document is asked for again
- [ ] **Apply** with a detail missing (for example no province on a province-restricted award)
      shows **A few details are still needed** asking only for that; saving it returns to the
      application and the detail is on the profile
- [ ] A minor is asked for guardian details in the same place; after saving, the application continues
- [ ] A missing required document is uploaded on that page and saved to the profile; a file of the
      wrong type is refused
- [ ] Results or a programme are a link to the profile with **Back to my application**
- [ ] One-click **Apply** on a listing card refuses (and opens the apply page) while a required
      document is missing; it works once they are all on file
- [ ] An award the applicant is not eligible for says **NOT ELIGIBLE** and collects no documents
- [ ] A school-leaver applying to an award limited to named institutions is asked where they have
      applied (nothing ticked) and it is not on the profile page; the answer is reused by the next such award
- [ ] With a saved list that does not include the award's institution, the page says **NOT ELIGIBLE** and shows the
      saved list ticked with **Save and check again**; adding the institution makes the statement appear, and a list
      that still does not fit stays NOT ELIGIBLE. An invalid choice (a fourth, none) saves nothing
- [ ] A direct submission by someone who is not eligible, or for a listing that is closed, is refused and leaves no
      new document on the profile
- [ ] The personal statement is still required (100 to 5,000 characters)
- [ ] After submitting, the application is in **Application tracker** with its number, date and
      status, and there is nothing to type to record it
- [ ] Editing the profile afterwards does not change what the provider sees on that application

## Provider documents

- [ ] On an application's review page every document it was submitted with has a **View** link:
      the attachment, results certificate, academic certificate / proof of study, Grade 7
      slip where applicable, CV, ID or passport, and recommendation letter
- [ ] A document the applicant's level asks for and did not send says **Not provided**, with no
      link; one that is recorded but whose file cannot be found says **File unavailable**
- [ ] "Not provided" follows the level the applicant had when they applied, not the one on their profile today; an
      older application with no record of that level lists only the documents it has
- [ ] Replacing a document on the applicant's profile after applying does not change what the
      provider opens (the application keeps what it was submitted with)
- [ ] A provider who does not own the listing gets a forbidden response for every document link,
      and an administrator has no route to an applicant's documents
- [ ] Each document opened by a provider appears in the audit log

## Security and ops

- [ ] Uploaded documents are not reachable under a public path — every download goes through
      an authenticated route
- [ ] Dark mode: dashboards and auth screens remain readable
- [ ] "Sign out all other sessions" ends a session open in a second browser
- [ ] The sign-in form has no "Keep me signed in" option
- [ ] Sign in, close the whole browser, open it again and visit the site: the landing page offers
      **Sign in** (not "Go to dashboard") and a dashboard URL redirects to the sign-in page. (If the
      browser is set to "continue where you left off" / restore the previous session it may restore
      the session cookie; that is the browser's setting, not ScholarZim's - check with it off.)
- [ ] The browser's cookies for the site show only a session cookie (no expiry date) after signing
      in, and no `remember_web_...` cookie
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
- [ ] Applicant dashboard (latest three in the tracker) and the **Application tracker** page (a rail per application with dates) load correctly
- [ ] Sorting and filter chips survive paging
- [ ] With `npm run build` run, pages load hashed assets from `/build`; without it, the
      unminified fallback still renders

---

## Demo accounts

Seeded by `database/seeders/DatabaseSeeder.php`, password `ChangeMe123` for all.
**Local/demo only — never reused in production;** the seeder refuses to run under
`APP_ENV=production`, so these accounts exist only in a local database:

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
