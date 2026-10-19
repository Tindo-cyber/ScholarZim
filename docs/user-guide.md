# ScholarZim User Guide

Screenshots for printed reports live in [docs/screenshots/](screenshots/) — capture during demo rehearsal (see README there).

## Applicant (student)

### Getting started

1. Register at `/register` with your email and password.
2. Complete your **profile** at `/applicant/profile`. The form only asks for what your
   education level actually needs: a Primary pupil sees a Grade 7 results-slip upload; an O/A-Level student sees a results-certificate upload; anyone from
   Certificate level upward sees a transcript upload, field of study and year of study. If you are under 18 (or at school with no date of birth given) a **Guardian details**
   section appears: your guardian's name, phone and relationship, and a tick that a parent or
   guardian is aware of and involved. You can browse and build your profile without them, but
   they are needed to submit an application, and providers see them on your application. There
   is no GPA field anywhere — providers see your actual results or transcript, not a number.
   A **Certificate** or **Diploma** has no degree classification and no academic results to enter: you upload your
   documents and that is all the profile needs. (A degree and above still state a classification.)
3. Upload your documents once, on the profile. The **Documents** card says how many are required
   for your education level, how many you have uploaded and how many remain, and lists each by
   name. **Upload the required documents listed below** — clear, readable copies; ones already on
   your profile are reused when they meet the requirements. Your academic certificate is in the
   *Academic certificates* section; your ID or passport, recommendation letter and optional CV are
   under *Other documents*. A new upload replaces the file you had. Only you, and a provider you apply
   to, can open your documents. The optional CV is listed but is not counted as required.
4. Browse scholarships at `/scholarships` or your dashboard recommendations.

![Applicant profile](../docs/screenshots/04-applicant-profile.png)

### Applying

- Your profile is the one place your details and documents live. Applying reuses them: you do
  not type your details again or upload a document ScholarZim already holds.
- Click **Apply** on a scholarship you are eligible for. The highest level you have completed, the
  level the scholarship targets, and any hard requirement it states are all checked again at
  the moment you apply — not only when the recommendations list was built — so a listing you
  are not eligible for refuses the submission with the same reason the listing page already
  showed you, and the wizard does not even offer a Submit button for it.
- ScholarZim checks that scholarship's requirements against your saved profile and tells you
  only what is missing. If something is, **A few details are still needed** asks for exactly
  that — a province, a date of birth, a guardian's details if you are under 18, a missing
  document — and saves it to your profile, so you will not be asked again. Anything that only
  the profile can hold (your results, your programme) is a link to that section, with a
  **Back to my application** button when you are done. It does not make you complete the whole
  profile again, and it does not collect documents for a scholarship you are not eligible for.
- One extra question can appear for school-leavers: a scholarship limited to named institutions
  asks which institutions you have applied to or hold an offer from (up to three), because you
  have no current institution to check. It is asked only on that kind of scholarship, saved
  once, and reused by the next one whenever it fits. It is not on the profile page. If the list
  you saved does not include an institution a later scholarship is limited to, that application
  page shows your saved list ticked and lets you add to it, remove from it or change it
  (**Save and check again**); eligibility is worked out again from the new list, and you can
  apply only if it now fits.
- Then write your personal statement (the one thing that is particular to each scholarship),
  tick the confirmation and **Submit**. The application is recorded for you.
- You never record where you have applied. Every application you submit appears in the
  **Application tracker** by itself, with its number, the date submitted and its status.
- Follow each application in the **Application tracker** (`/my-applications`, also on your dashboard): a progress rail per application - Applied, Under review, Decision - with the date each step happened. (The screenshot below is from before it was renamed.)

![Application tracker](../docs/screenshots/06-my-applications.png)

### While an application is open

- Your application sits at **Pending** until the provider reviews it. There is nothing more
  to do in the meantime.
- When they decide, you get an email and a notification. The application page then shows
  **Accepted** or **Rejected**, together with the reason they wrote.
- An acceptance means the provider has granted the scholarship and there is nothing further to
  confirm. Rarely, a provider may withdraw a decision they made by mistake: you are notified,
  the application goes back to **Pending** (an accepted scholarship is *not* guaranteed while
  they reconsider), and you are notified again when they decide. The page shows what happened
  and the reason they gave.
- Changed your mind before a decision? **Withdraw application** tells the provider the place
  is free. You can apply again later while the scholarship is still open.

### Saved scholarships

- Save opportunities from the browse page for later review (`/applicant/saved`).
- Saving is a bookmark — nothing is emailed about it, and you can unsave at any time.
- You are reminded when a scholarship you saved or applied to is closing within three days.

### Understanding ScholarFit eligibility

- ScholarFit is not a score or a percentage. It checks whether your profile meets what a
  listing actually requires, and shows **Eligible** or **Not eligible** with the exact
  reasons either way.
- Two rules apply to every listing regardless of what the provider configured: whether the
  listing is for the next stage after the highest level you have completed (your profile level
  is the one you have FINISHED, so an O-Level leaver is not matched with O-Level funding, a
  graduate is not matched with degree bursaries, and a PhD needs a Master's first), and whether
  this specific listing has raised its own minimum qualifying level.
- Record the results of the level you have completed. Results from an earlier level are offered
  only when a scholarship you could apply for asks for them.
- On top of those, providers can set their own hard rules — a minimum points figure, required
  subject grades, an age limit, a province requirement, proof of academic results on file.
  Failing any one of these means you cannot be considered: the listing is left out of your
  recommendations (it appears instead in "scholarships you don't qualify for yet", with the
  reason), and applying to it directly is refused.
- If we simply do not have the information to check a rule, we ask for it rather than ruling
  you out. Filling in your date of birth and province, for example, lets us check age and
  province rules for you.
- Every requirement you did meet is shown too, not just the ones you failed, so a refusal
  reads as a verdict on the one or two things that fell short rather than on your whole
  profile.

### Account

- **Settings** → `/account/security` — password, email preferences, sessions, and account
  deletion.
- **Signing in:** closing the browser signs you out, so the next visit asks for your password.
  There is no "keep me signed in" option. Some browsers can be set to restore your previous
  session when they reopen, which also restores your sign-in; sign out explicitly on a shared
  computer.
- **Sign out all other sessions** ends every session except the one you are using — useful
  after signing in on a library or lab computer.
- **Messages** → notification inbox for application updates and deadline reminders.

---

## Provider (organisation)

### Registration

Organisations registered in **any country** use the same form and the same verification.

1. Register at `/register/provider`.
2. Give the organisation's registered name, its **country of registration** (where it is legally registered —
   not where your scholarships are held), its type, its **official registration number** in whatever format your
   country uses (or, if it has none, an explanation of why), your name and position, a work email, and upload the
   **registration certificate or equivalent document** (PDF, Word, JPG or PNG, up to 5 MB; include an English
   translation if it is not in English). Confirm you are authorised to act for the organisation.
3. Verify your email address, then wait for an administrator to verify the organisation. Until then your account
   is pending and cannot publish.
4. If the administrator asks for more information, the request appears on your dashboard under **Registration
   details**; update your details or upload a clearer document there and it returns to the review queue.

Each scholarship's **study destination** is chosen on the listing form (Zimbabwe, another country, "Any country"
or "Online / distance"), independently of where your organisation is registered. See
`docs/international-providers.md`.

### After approval

- Create opportunities at `/opportunities/create`.
- Review applications at `/provider/applications`.
- View the applicant's profile and download their results certificate or transcript — whichever
  their education level actually uses (a Grade 7 results slip for a Primary pupil) — for your
  opportunities only.
- What a provider opens is what the applicant submitted: results, transcript and Grade 7 slip are
  recorded with the application when it is sent, so replacing a document afterwards does not change
  (or remove) what the provider was shown.
- A Form 1 award can require proof of results. A Grade 7 pupil is asked for no documents in
  general, but can upload their Grade 7 results slip on their profile, and an award that requires
  proof will not accept an application without it.

### Describing an award

- **Who this scholarship is for** — the education level it targets (Form 1 counts as a target
  here even though no applicant's own profile is ever set to it — it is the Primary-to-secondary
  transition award). A student whose current level can never reach the level you pick is
  refused before anything else is checked, regardless of how the rest of the listing is set up.
  There is no "Honours" option: for a BSc or BCom Honours at a Zimbabwean university, choose
  **Undergraduate**; for a one-year Honours after a degree (as in South Africa), choose
  **Postgraduate**.
- **Minimum qualifying level** — optional, and only needed when your listing is stricter than
  the general pathway: an Undergraduate award that should only take A-Level applicants
  directly, for example, rather than accepting O-Level applicants the way the general pathway
  otherwise would.
- **What the award is worth** — the value, currency, and how many awards are on offer. This
  is the first thing a student compares, and it is what value sorting and the minimum-award
  filter read. A listing with no stated value still appears in search, but is excluded from
  both.
- **Your own application page** — an optional link, shown alongside the ScholarZim
  application.
- **Hard eligibility rules** — minimum A-Level points, an age ceiling, required citizenship,
  province, an optional target locality (a specific place, e.g. Gweru) and settlement type
  (rural or urban), and whether proof of academic results must be on file. These **disqualify**
  rather than score down: a student who fails one is told they are not eligible, the listing
  is left out of their recommendations, and applying to it directly is refused. Leave a rule
  blank unless it genuinely is one; guidance belongs in the description, where it informs
  rather than blocks.

### Reviewing applications

- Open an application from the inbox to see the student, their profile, their documents and
  their ScholarFit eligibility check, then press **Accept** or **Reject**. Under **Documents**
  you can open everything the application was submitted with: the attachment, results
  certificate, academic certificate or proof of study, Grade 7 slip, CV, ID or passport, and
  recommendation letter. A document the applicant did not send says **Not provided**.
- Both decisions require a written reason. The applicant reads it verbatim, in their email
  and on their application page.
- Accepting *is* granting the scholarship — there is no separate award step. A decision is not
  edited in place. If you made a mistake, open the application and choose **Withdraw decision
  and reopen**: you must give a reason (the applicant is shown it), you confirm the dialog, and
  the application goes back to **Pending** so you can accept or reject it afresh. Withdrawing an
  **acceptance** reverses the award: the applicant is told it no longer stands and is not
  guaranteed, and the award slot is released. Your original decision and reason stay in the
  application's **Decision history** and the audit log. The applicant is notified at each step.
- Decisions are made one application at a time: a reason written for one student should not
  be sent to a batch.

### Dashboard and analytics

- `/provider/dashboard` — overview of opportunities and pending applications.
- `/provider/analytics` — views, saves, applications, and how many are pending, accepted or
  rejected, plus a per-listing breakdown. A view
  trend, per-listing performance, and who is applying by field and level. Your own visits to
  your listings are not counted.

---

## Administrator

### Dashboard

- `/admin/dashboard` — platform statistics, user management, pending provider queue.

### Scholarship moderation

- New listings queue on the dashboard. Tick several and use the bar underneath to approve
  or decline them together; a decline still needs a reason, which the provider is shown
  verbatim.
- A listing that looks like an existing one — same title, or same awarding body and closing
  date — is flagged as a **possible duplicate**. It is a prompt to look, not a refusal: two
  intakes of the same annual bursary are a legitimate pair of rows, and only a person can
  tell that apart from a double submission. Open the preview to see what it matched.

### Provider verification

1. Open **Providers awaiting verification** on the dashboard; the **Registered in** column shows each
   organisation's country of registration (or *Not recorded* for accounts registered before it was asked).
2. Choose **Review** to see the full record — name, country, type, registration number or the reason there is
   none, contact person and position, email, phone, website, submission date and review history — and open the
   registration document (each opening is audited).
3. **Approve** to activate the account, **Reject** with a reason, or **Request more information** (the account
   stays pending and the organisation is told what you need). Apply the same checks whatever the country: the
   document should name the organisation and support the country and number given.
4. For an older account with no country on record, record it under **Registration details** on the review page
   (it must be recorded before a pending account can be approved; it changes nothing else about the account).

### User management

- Suspend, activate, or delete user accounts from the admin dashboard.

### Audit log

- Review security and compliance events (logins, certificate views, application actions).

### Reports

- Export analytics and user reports (PDF/Excel) from admin tools where available.

---

## Public visitor

- Browse scholarships at `/scholarships` without an account.
- View opportunity details, award values, and deadlines. Sort by deadline or award value,
  and narrow with the filters; each active filter appears as a chip you can remove on its
  own.
- Register when ready to apply.

![Landing page](../docs/screenshots/01-landing.png)

---

## Password reset (email)

1. On the login page, click **Forgot password**.
2. Enter your account email and submit.
3. Check your inbox — with Docker demo stack, open **Mailhog** at http://localhost:8025.
4. Click the reset link in the email (valid for 1 hour).

If delivery fails after retries, an `EMAIL_DELIVERY_FAILED` event is written to the audit log.

![Forgot password](../docs/screenshots/10-forgot-password.png)

## Demo accounts

See [demo-script.md](demo-script.md) for viva/demo login credentials (demo profile only).
