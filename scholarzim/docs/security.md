# ScholarZim Security

## Authentication

- **Form-based login** with server-side sessions (no JWT in browser MVC flow).
- **BCrypt** password hashing via Spring Security `PasswordEncoder`.
- **Account states:** `ACTIVE`, `PENDING_APPROVAL`, `REJECTED`, `SUSPENDED` — non-active accounts cannot authenticate.
- **Password reset:** UUID token, 1-hour expiry, single use; email delivery via JavaMailSender with configurable retry (`scholarzim.mail.retry.max-attempts`, default 3). Failed delivery after all retries writes `EMAIL_DELIVERY_FAILED` to the audit log. Demo stack routes mail to **Mailhog** (SMTP `:1025`, UI `:8025`).
- **Optional 2FA:** TOTP (authenticator app) when `scholarzim.security.2fa.enabled=true` and user has enabled 2FA on their account. Login requires a second step at `/login/2fa-challenge`.

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

- PDF uploads validated by content type and size (≤ 5 MB) for certificates.
- Bean Validation on registration and profile forms.
- CSRF protection on MVC forms; disabled for `/api/**` (session cookie API — same-origin use only).

## Rate limiting

In-memory Bucket4j filters (`LoginRateLimitFilter`):

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

Production (`application-prod.properties`): secure session cookies when served over HTTPS.

## Demo vs production

| Setting | Demo/dev | Production |
|---------|----------|------------|
| Demo seeder | Enabled | **Disabled** |
| Demo login hints on login page | Shown | Hidden |
| Swagger UI | Available | Disabled |
| DB credentials | Local defaults | Environment variables |
| 2FA | Configurable | Recommended for admin |

## Known limitations (future work)

- **SMS notifications** — interface exists; implementation logs only (no gateway).
- **Cluster rate limiting** — in-memory only.
- **API CSRF** — session cookie API not intended for third-party cross-site use.
- **Account deletion** — data export only; full erasure not implemented.
