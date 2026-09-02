# ScholarZim Security

## Authentication

- **Form-based login** with server-side sessions (no JWT in browser MVC flow).
- **bcrypt** password hashing via Laravel's `Hash` facade.
- **Account states:** `ACTIVE`, `PENDING_APPROVAL`, `REJECTED`, `SUSPENDED` — non-active accounts cannot authenticate.
- **Password reset:** UUID token, 1-hour expiry, single use. Delivered through Laravel Mail: the **Mailgun HTTP API** in production, **MailHog** under local Docker (SMTP `:1025`, UI `:8025`), `log` for a local clone without Docker. Failed delivery writes `EMAIL_DELIVERY_FAILED` to the audit log.
- **Email verification:** UUID token, single use, retired when a new one is issued. Delivered by the same path.
- **No second factor.** TOTP was removed as outside the project's objectives. The `two_factor_*` columns remain on `users` as unused legacy columns and stay in the model's `$hidden` list so a legacy row can never be serialised into a response.
- **Session management.** `AuthenticateSession` is in the `web` middleware group, so "sign out all other sessions" on the account security page genuinely ends every other session on every device. It works by rotating the password hash the sessions carry, which is why it asks for the current password first.
## Authorization

| Path pattern | Access |
|--------------|--------|
| `/`, `/scholarships/**`, auth pages | Public |
| `/applicant/**`, `/apply/**`, `/my-applications` | ROLE_APPLICANT |
| `/provider/**`, `/opportunities/create` | ROLE_PROVIDER |
| `/admin/**` | ROLE_ADMIN |
| `/applications/*/document`, `/applications/*/results-certificate` | Authenticated + ownership checks in service layer |
| `/health` | Public |

There is no public JSON API: `/api/**`, its Sanctum tokens and the developer portal were removed. The Mailgun **email** API is unrelated and unchanged.

Enforced by route middleware groups (`auth`, `role:ROLE_*`, and `account.active` for publishing routes) in `routes/web.php`. Ownership checks that depend on the record — an applicant's own application, a provider's own listing — live in the service layer.

## File access

- Uploaded files are stored on the private `local` disk (`storage/app`), outside the web root. Nginx additionally denies any request under `/storage/`.
- Downloads use authenticated endpoints with access checks:
  - Application documents — applicant or owning provider
  - Applicant results certificate — applicant, owning provider, or admin (with audit)
  - Provider registration certificate — admin only

Stored filenames are server-generated UUIDs, so a client-supplied name never reaches the filesystem. The one user-controlled path segment, `/my-documents/{documentType}`, is resolved through the `ApplicantProfile::DOCUMENT_TYPES` whitelist rather than used directly.

## Input validation

- Uploads are content-sniffed in `FileStorageService::guard()` — `UploadedFile::getMimeType()` inspects the actual file bytes via finfo rather than trusting the client-supplied `Content-Type` header, and size is capped at 5 MB. Accepted types: PDF, Word, JPEG, PNG.
- Laravel form-request validation on registration and profile forms, with a second `mimes`/`max` check at the controller boundary.
- CSRF protection on every session-authenticated POST via the `web` middleware group; public GET `/api/public/**` needs none.

## Rate limiting

Laravel's `throttle` middleware, keyed per client IP:

| Route | Limit |
|-------|-------|
| `POST /login` | 10 per minute |
| `POST /register` | 10 per minute |
| `POST /register/provider` | 5 per hour |
| `POST /forgot-password` | 5 per minute |
| `POST /resend-verification` | 3 per minute |


**Limitation:** the default cache driver is per-instance, so limits are not shared across multiple app instances (future work: a Redis-backed cache store).

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
| TWO_FACTOR_ENABLED / TWO_FACTOR_DISABLED | Second factor turned on or off |
| TWO_FACTOR_CHALLENGE_FAILED | Wrong code at setup or sign-in |
| LOGOUT_OTHER_SESSIONS | Every other session for an account ended |
| API_TOKEN_CREATED / API_TOKEN_REVOKED | Personal access token issued or revoked |
| ACCOUNT_SELF_DELETED | A user deleted their own account |
| WITHDRAW_APPLICATION, REQUEST_APPLICATION_INFO, PROVIDE_APPLICATION_INFO | Application lifecycle |
| BULK_STATUS_UPDATE, BULK_MODERATION | A decision applied across a selection |
| UPDATE_SCHOLARFIT_WEIGHTS | Scoring weights changed or reset |

Admin can review entries at `/admin/dashboard` → audit log section.

The trail deliberately **outlives the account it describes**: deleting a user removes their
profile, applications, saved scholarships, alerts, and notifications, but not the audit
entries, which record what the platform did rather than who the user was.

## Account deletion

Self-service at `/account/security`, and the admin path goes through the same service, so
both take identical steps under identical refusals:

- The current password **and** the account's own email address must both be given: a
  password alone is muscle memory, and this is not reversible.
- A provider still holding live listings is refused. Other people's applications point at
  those rows, so deleting the listing would erase someone else's history; they withdraw the
  listings first.
- The bootstrap super admin can never be deleted.
- Removal runs in one transaction, in foreign-key order — the schema deliberately does not
  cascade, so a stray delete cannot quietly take applications with it.

## HTTP headers

Set by `app/Http/Middleware/SecurityHeaders.php`, applied to the whole `web` middleware group: `Content-Security-Policy`, `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, and `Permissions-Policy`. `Strict-Transport-Security` is sent only on HTTPS requests, so a plain-HTTP dev server never pins developers to `https://localhost`.

`script-src` is plain `'self'` — no `'unsafe-inline'`, and no nonce. The Blade views carry no inline `<script>` at all and every asset is self-hosted under `public/assets`, so the per-request nonce the Thymeleaf templates needed is no longer necessary. `object-src` is `'none'`, and `form-action`, `base-uri` and `frame-ancestors` are all pinned to `'self'`.

`style-src` still allows `'unsafe-inline'`: inline `style="..."` attributes are used across a number of views, and extracting all of them was out of scope for this pass — future work.

`SecurityHeadersTest` asserts the headers are present and that `script-src` has not been relaxed.

Production: set `SESSION_SECURE_COOKIE=true` so session cookies are only sent over HTTPS.

## Demo vs production

| Setting | Demo/dev | Production |
|---------|----------|------------|
| Demo seeder | Enabled | **Disabled** |
| Demo login hints on login page | Shown | Hidden |
| `APP_DEBUG` / stack traces | Enabled | **Disabled** |
| Config, route and view caches | Off, so edits apply live | Warmed on boot |
| DB credentials | Local defaults | Environment variables |
| Uploads path | Local `storage/app` | `storage/app` on a mounted persistent disk |

## Known limitations (future work)

- **Cluster rate limiting** — per-instance cache only.
- **`composer audit` is non-blocking** — `audit.block-insecure=false` remains in `composer.json` from the Laravel 10 era, when every release carried an advisory with no fix to move to. The framework is now Laravel 12 and the audit reports nothing; the setting is stale rather than load-bearing and should be removed.
- **Ephemeral uploads on free Render** — attach a persistent disk (or object storage) so certificates survive redeploys.
- **Admin PDF/Excel reports** — current exporters load full tables into memory; fine for FYP scale; paginate or stream before large production datasets.
- **`style-src 'unsafe-inline'`** — inline `style="..."` attributes remain across a number of views; extracting them is future work.
