# ScholarZim Viva Demo Script

**Duration:** 12–15 minutes  
**Password for all demo accounts:** `Password123!`

## Before you start

1. Start infrastructure: `cd scholarzim && docker compose up -d`
2. Run the app: `mvn spring-boot:run -Dspring-boot.run.profiles=demo`  
   Or full stack: `docker compose up --build` (includes app service)
3. Open http://localhost:8080
4. Optional: Mailhog UI at http://localhost:8025 for password-reset email demo

Run tests beforehand: `mvn clean test` (65+ tests should pass).

---

## Demo accounts

| Role | Email | State | Use in demo |
|------|-------|-------|-------------|
| Admin | admin@scholarzim.co.zw | ACTIVE | Approve providers, audit log |
| Provider (active) | scholarships@uk.gov.zw | ACTIVE | Review applications, download results cert |
| Provider (pending) | pending.provider@org.co.zw | PENDING_APPROVAL | Live admin approval |
| Applicant (ready) | tanaka.moyo@student.co.zw | ACTIVE + results cert | Full apply flow |
| Applicant (no cert) | simba.ndlovu@student.co.zw | ACTIVE, profile without PDF | Apply gate redirect |

---

## Step 1 — Public catalog (2 min)

**Talking point:** Open access to scholarship discovery without registration.

1. Visit `/` — landing page
2. Visit `/scholarships` — browse list, use search/filter if shown
3. Open any scholarship detail — show deadline, provider, funding type
4. Mention REST API: `/api/public/stats`, `/api/public/scholarships`

---

## Step 2 — Applicant apply gate (3 min)

**Talking point:** Trust and verification — applicants must upload verified results before applying.

1. Log out if needed
2. Login as **simba.ndlovu@student.co.zw**
3. Browse to a scholarship → click **Apply**
4. Show redirect to `/applicant/profile?resultsRequired=1` with warning banner
5. Upload a PDF on profile (any small PDF ≤ 5 MB)
6. Return to scholarship → apply wizard opens
7. Submit application → confirmation page

**Fallback:** If upload fails, switch to **tanaka.moyo@student.co.zw** (pre-seeded with certificate).

---

## Step 3 — Provider review (3 min)

**Talking point:** Providers only see applicant data for their own opportunities.

1. Logout → login as **scholarships@uk.gov.zw**
2. Open provider applications list (`/provider/applications`)
3. Open an application review — show academic profile card
4. Click **View results certificate** — inline PDF (200 OK)
5. Mention: unrelated provider receives 403 (covered by automated tests)

---

## Step 4 — Admin verification (3 min)

**Talking point:** Platform gatekeeping — only verified organisations publish scholarships.

1. Logout → login as **admin@scholarzim.co.zw**
2. Open `/admin/dashboard` — pending providers section
3. Show **pending.provider@org.co.zw**
4. Approve provider → flash success message
5. Download provider registration certificate from admin link
6. Open **Audit log** — show recent LOGIN, APPROVE, VIEW events

**Optional:** Reject a test provider if time permits.

---

## Step 5 — Quality evidence (2 min)

**Talking point:** Automated verification of business rules.

1. In terminal: `mvn clean test` — show green build
2. Mention CI on GitHub: unit tests + Flyway migration smoke on MySQL 8
3. Point to `docs/manual-qa-checklist.md` and `docs/evaluation.md`

---

## Step 6 — Security highlights (1 min)

Cover briefly (see [security.md](security.md)):

- BCrypt passwords, role-based URL access
- Secured file downloads (not public `/uploads/**`)
- Rate limiting on login and provider registration
- Optional TOTP 2FA when enabled (`scholarzim.security.2fa.enabled=true`)

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Database connection refused | `docker compose up -d mysql` and wait for healthy status |
| Empty scholarship list | Use `demo` profile so seeder runs; or delete DB volume and restart |
| Login fails | Check account status (pending provider cannot login as active) |
| Certificate download 404 | Re-run demo profile to recreate stub PDFs on disk |
