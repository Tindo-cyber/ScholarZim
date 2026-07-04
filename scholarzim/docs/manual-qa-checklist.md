# Manual QA checklist

Use this checklist before a demo, release, or after significant changes to verification flows.
Run `mvn clean test` in `scholarzim/` first — all automated tests should pass.

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

## Security and ops

- [ ] `/uploads/**` is not publicly accessible (redirect or auth required)
- [ ] Dark mode: dashboards and auth screens remain readable (no washed-out WebP overlays)
- [ ] Demo login `tanaka.moyo@student.co.zw` / `Password123!` can apply (demo cert seeded)

## Regression

- [ ] Student login and registration flows work
- [ ] Provider login and pending-registration messaging work
- [ ] Forgot password / reset password flows work
- [ ] Scholarships browse, filter, and save scholarship work
- [ ] Provider dashboard loads; application status changes (approve/reject) notify applicant
- [ ] Applicant dashboard and my-applications list load correctly

---

## Notes

| Area | Demo account |
|------|----------------|
| Applicant | `tanaka.moyo@student.co.zw` / `Password123!` |
| Provider | Use an approved demo provider from seeder |
| Admin | Use admin account from local/dev configuration |

Record any failures with browser, role, URL, and steps to reproduce.
