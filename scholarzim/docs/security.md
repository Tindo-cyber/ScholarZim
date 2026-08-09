# ScholarZim Security

## Authentication

- **Form-based login** with server-side sessions (no JWT in browser MVC flow).
- **BCrypt** password hashing via Spring Security `PasswordEncoder`.
- **Account states:** `ACTIVE`, `PENDING_APPROVAL`, `REJECTED`, `SUSPENDED` — non-active accounts cannot authenticate.
- **Password reset:** UUID token, 1-hour expiry, single use; email delivery via JavaMailSender with configurable retry (`scholarzim.mail.retry.max-attempts`, default 3). Failed delivery after all retries writes `EMAIL_DELIVERY_FAILED` to the audit log. Demo stack routes mail to **Mailhog** (SMTP `:1025`, UI `:8025`).
## Authorization

| Path pattern | Access |
|--------------|--------|
| `/`, `/scholarships/**`, auth pages | Public |
| `/applicant/**`, `/apply/**`, `/my-applications` | ROLE_APPLICANT |
| `/provider/**`, `/opportunities/create` | ROLE_PROVIDER |
| `/admin/**` | ROLE_ADMIN |
| `/applications/*/document`, `/applications/*/results-certificate` | Authenticated + ownership checks in service layer |
| `/api/public/**` | Public |
| `/api/applicant/**` | ROLE_APPLICANT |

Method-level security (`@PreAuthorize`) is enabled for selected provider service methods.

## File access

- Uploaded files are stored outside the web root.
- **Legacy `/uploads/**` is blocked** — redirects unauthenticated users to login.
- Downloads use authenticated endpoints with access checks:
  - Application documents — applicant or owning provider
  - Applicant results certificate — applicant, owning provider, or admin (with audit)
  - Provider registration certificate — admin only

`FileStorageService.resolve()` rejects path traversal (`../`).

## Input validation

- Uploads (PDF and image) are content-sniffed with Apache Tika (`FileStorageService`) — the actual file bytes are inspected, not the client-supplied `Content-Type` header, and size is capped at 5 MB.
- Bean Validation on registration and profile forms.
- CSRF protection on MVC forms and session-authenticated `/api/applicant/**`; ignored only for public GET `/api/public/**`.

## Rate limiting

In-memory Bucket4j filters (`LoginRateLimitFilter`), keyed per client IP and backed by Caffeine caches (`expireAfterAccess` + `maximumSize`) so idle client buckets are evicted instead of growing unbounded over a long-running deployment:

- Login / register: 10 requests per minute per IP
- Provider registration: 5 per hour per IP

**Limitation:** Not shared across multiple app instances (document as future work: Redis-backed limits).

## Audit logging

Security-relevant events written to `audit_log`:

| Action | When |
|--------|------|
| REGISTER | User registration |
| LOGIN_SUCCESS | Successful login |
| LOGIN_FAILURE | Failed login attempt |
| PASSWORD_RESET_REQUEST | Reset email requested |
| PASSWORD_RESET_COMPLETE | Password changed via token |
| EMAIL_DELIVERY_FAILED | Email could not be sent after retries |
| VIEW_PROVIDER_CERTIFICATE | Admin downloads provider cert |
| VIEW_APPLICANT_RESULTS | Provider/admin views results PDF |
| APPLY, STATUS_UPDATE, admin user ops | Business workflows |

Admin can review entries at `/admin/dashboard` → audit log section.

## HTTP headers

Content-Security-Policy and `X-Frame-Options: SAMEORIGIN` configured in `SecurityConfig`.

`script-src` does **not** allow `'unsafe-inline'`. The five inline `<script>` blocks that previously required it were externalized to `/js/*.js` (theme init, cache-purge, account-security, the profile-document auto-submit handler). The two admin chart templates (`admin/analytics.html`, `admin/dashboard.html`) still render small `th:inline="javascript"` blocks because they inject per-request chart data — those carry a per-request nonce instead: `CspNonceHeaderWriter` mints a random nonce and writes it into both the CSP header and a request attribute, and `CspNonceModelAdvice` exposes it to Thymeleaf as `${cspNonce}`, which the templates stamp onto the script tag via `th:attr="nonce=${cspNonce}"`. `style-src` still allows `'unsafe-inline'` (inline `style="..."` attributes and `<style>` blocks are used across a number of templates; nonce-ing or extracting all of them was out of scope for this pass — future work).

Production (`application-prod.properties`): secure session cookies when served over HTTPS.

## Demo vs production

| Setting | Demo/dev | Production |
|---------|----------|------------|
| Demo seeder | Enabled | **Disabled** |
| Demo login hints on login page | Shown | Hidden |
| Swagger UI | Available | Disabled |
| DB credentials | Local defaults | Environment variables |
| Uploads path | Local `uploads/` | `SCHOLARZIM_UPLOAD_DIR` on a persistent disk |

## Known limitations (future work)

- **SMS notifications** — interface exists; implementation logs only (no gateway).
- **Cluster rate limiting** — in-memory only.
- **Ephemeral uploads on free Render** — attach a persistent disk (or object storage) so certificates survive redeploys.
- **Admin PDF/Excel reports** — current exporters load full tables into memory; fine for FYP scale; paginate or stream before large production datasets.
- **Account deletion** — data export only; full erasure not implemented.
