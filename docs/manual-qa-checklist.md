# Manual QA checklist

Use this checklist before a demo, release, or after significant changes to verification flows.
Run `php artisan test` in the project root first — all automated tests should pass.

---

## Applicant results certificate

- [ ] New applicant: profile save blocked without PDF; succeeds with a real PDF (≤ 5 MB)
- [ ] Browse opportunity → **Apply** redirects to profile when no certificate is on file
- [ ] After upload, apply wizard opens; step 2 optional document is separate from profile certificate
- [ ] Replace certificate on profile; previous file is not reachable via public `/uploads/**`

## Provider verification

- [ ] Provider registration requires PDF + organisation type + registration number
- [ ] Pending provider cannot publish ACTIVE opportunities
- [ ] Admin can approve/reject pending providers; certificate view/download works
- [ ] Non-admin cannot access `/admin/providers/*/certificate`

## Provider review

- [ ] Academic profile card shows level, institution, field, province, and results summary
- [ ] **View results certificate** opens inline PDF for the opportunity owner
- [ ] Unrelated provider receives 403 when attempting certificate download

## Award value and eligibility

- [ ] Posting a listing with an award value shows it on the card and the detail page
- [ ] A blank award field stores nothing, not zero — the listing reads "Value not stated"
- [ ] Sorting by award value puts stated values first and unstated ones last, both directions
- [ ] A minimum-award filter excludes listings with no stated value
- [ ] A student who fails a hard rule sees "You are not eligible" with the reason, no percentage
- [ ] The same student does not see that listing in their recommendations
- [ ] A student missing the field a rule tests (no date of birth) is prompted, not refused

## Alerts, withdrawal, and questions

- [ ] Saving a search from the browse page stores exactly the filters on screen
- [ ] After a matching listing is approved, one alert arrives; a second run sends nothing
- [ ] Provider "Information requested" puts a reply box on the applicant's page
- [ ] The applicant's answer appears on the provider's review screen
- [ ] Withdrawing an application notifies the provider and allows re-applying
- [ ] An approved or rejected application can no longer be withdrawn

## Bulk actions

- [ ] Select-all ticks every row; the button count matches the selection
- [ ] A bulk decline without a reason is refused, exactly like a single decline
- [ ] A batch containing one already-reviewed row still processes the rest, and says so

## Security and ops

- [ ] `/uploads/**` is not publicly accessible (redirect or auth required)
- [ ] Dark mode: dashboards and auth screens remain readable (no washed-out WebP overlays)
- [ ] A recovery code signs in once, and the remaining count drops
- [ ] "Sign out all other sessions" ends a session open in a second browser
- [ ] Account deletion refuses without the typed email; refuses for a provider with live listings
- [ ] `/health` returns 200 with `"database": "up"`
- [ ] Queued mail arrives with a worker running, and `queue:failed` is empty
- [ ] Demo login `tanaka.moyo@student.co.zw` / `Password123!` can apply (demo cert seeded)

## Design and accessibility

- [ ] Tab from the top of any page: the first stop is "Skip to main content", and it works
- [ ] At phone width, the applications and users tables read as cards with visible labels
- [ ] At phone width with the sidebar closed, tabbing does not reach its links
- [ ] A closing-soon listing shows a countdown chip that escalates inside 7 and 3 days
- [ ] Print preview of an application: no navigation, no buttons, link URLs shown
- [ ] The profile completion ring matches the checklist beside it

## Regression

- [ ] Student login and registration flows work
- [ ] Provider login and pending-registration messaging work
- [ ] Forgot password / reset password flows work
- [ ] Scholarships browse, filter, and save scholarship work
- [ ] Provider dashboard loads; application status changes (approve/reject) notify applicant
- [ ] Applicant dashboard and my-applications list load correctly
- [ ] Sorting and filter chips survive paging (the ordering is not lost on page 2)
- [ ] With `npm run build` run, pages load hashed assets from `/build`; without it, the
      unminified fallback still renders

---

## Notes

| Area | Demo account |
|------|----------------|
| Applicant | `tanaka.moyo@student.co.zw` / `Password123!` |
| Provider | Use an approved demo provider from seeder |
| Admin | Use admin account from local/dev configuration |

Record any failures with browser, role, URL, and steps to reproduce.
