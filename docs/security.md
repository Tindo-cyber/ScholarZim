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

- Uploaded files are stored on the disk named by `filesystems.default`
  (`FILESYSTEM_DISK`; `FileStorageService::diskName()`), never under `public/`.
  That is the private `local` disk (root `storage/app`, or `FILESYSTEM_ROOT`)
  unless the environment sets another: `render.yaml` and `.env.prod.example`
  set `FILESYSTEM_DISK=s3`, intended for a private Cloudflare R2 bucket - see
  `config/filesystems.php` and `docs/DEPLOYMENT.md`. Either way files are only
  read back through the controllers below. Nginx additionally denies any request
  under `/storage/`.
- Downloads use authenticated endpoints with access checks, one route per
  document relationship rather than one generic "give me this path" endpoint:
  - `/applications/{id}/document` — the applicant who submitted it, or the
    provider reviewing that application
  - `/my-documents/{documentType}` — always the signed-in user's own profile
    document; there is no path by which one account can name another's
  - `/provider/applications/{id}/results-certificate` and `/transcript` — the
    provider reviewing that specific application only, audited
    (`VIEW_APPLICANT_RESULTS` / `VIEW_APPLICANT_TRANSCRIPT`)
  - `/admin/providers/{userId}/certificate` — admin only (route group and
    `DocumentPolicy::viewProviderCertificate`), audited (`VIEW_PROVIDER_CERTIFICATE`).
    The same route serves every organisation's registration document whatever
    its country; an organisation replacing its document while awaiting
    verification goes through the same upload checks, and the replaced file is deleted.

Stored filenames are server-generated UUIDs, so a client-supplied name never reaches the filesystem. The one user-controlled path segment, `/my-documents/{documentType}`, is resolved through the `ApplicantProfile::DOCUMENT_TYPES` whitelist rather than used directly. A file flagged by the (optional) malware scanner is refused to every caller regardless of ownership — see `DocumentScanner` and `FileStorageService::respond()`.

## Input validation

- Provider registration: the country of registration must be one of the ISO 3166-1 codes in
  `App\Support\Countries` (the browser's value is never trusted as-is); the registration number
  accepts any country's format but only letters, digits and `. / - ( ) # : & ,`; a number or an
  explanation is required. Choosing a country is a statement, not proof — every organisation,
  wherever registered, stays `PENDING` until an administrator verifies its document, and
  publishing routes keep requiring `account.active`.

- Uploads are content-sniffed in `FileStorageService::guard()` — `UploadedFile::getMimeType()` inspects the actual file bytes via finfo rather than trusting the client-supplied `Content-Type` header, and size is capped at 5 MB. Accepted types: PDF, Word, JPEG, PNG.
- Laravel form-request validation on registration and profile forms, with a second `mimes`/`max` check at the controller boundary.
- CSRF protection on every POST, PUT and DELETE via the `web` middleware group. There is no `/api` route group to exempt.

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
| APPROVE_PROVIDER / REJECT_PROVIDER | Organisation verified or refused; the entry names its country of registration |
| REQUEST_PROVIDER_INFO | Admin asked an organisation for more evidence (account stays pending) |
| UPDATE_PROVIDER_REGISTRATION | Registration details recorded by an admin (old and new values) or updated by a pending organisation |
| VIEW_APPLICANT_RESULTS | Provider/admin views results PDF |
| APPLY, STATUS_UPDATE, admin user ops | Business workflows |
| LOGOUT_OTHER_SESSIONS | Every other session for an account ended |
| ACCOUNT_SELF_DELETED | A user deleted their own account |
| WITHDRAW_APPLICATION | An applicant withdrew an application |
| BULK_MODERATION | One moderation decision applied to several listings |
| UPDATE_SCHOLARFIT_WEIGHTS | Historical only: ScholarFit used to have configurable scoring weights, which were removed (it now has no score), so nothing writes this any more; old audit rows may still carry it |
| TWO_FACTOR_ENABLED, TWO_FACTOR_DISABLED, TWO_FACTOR_CHALLENGE_FAILED, API_TOKEN_CREATED, API_TOKEN_REVOKED, REQUEST_APPLICATION_INFO, PROVIDE_APPLICATION_INFO, BULK_STATUS_UPDATE | Historical only: written by two-factor sign-in, API tokens, the "information requested" status and bulk application decisions, all removed on 2026-08-31. Nothing writes them now; the names stay so old audit rows still display |

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
| Uploads path | Local `storage/app` (unset `FILESYSTEM_ROOT`) | On Render's current `plan: free`, still `storage/app` inside the container - not persistent; see `docs/DEPLOYMENT.md` for what upgrading to a paid plan and setting `FILESYSTEM_ROOT` to a mounted disk requires |

## Known limitations (future work)

- **Cluster rate limiting** — per-instance cache only.
- **Ephemeral uploads on free Render** — the disk root is configurable (`FILESYSTEM_ROOT`), but no disk is actually attached on `plan: free`, so uploads still do not survive a redeploy today. Upgrading the plan, attaching a Render Persistent Disk, and setting `FILESYSTEM_ROOT` to its mount path (see `docs/DEPLOYMENT.md`) is what closes this - it is configuration, not a code change, but it has not been done because the current deployment stays on the free plan.
- **Admin PDF/Excel reports** — current exporters load full tables into memory; fine for FYP scale; paginate or stream before large production datasets.
- **`style-src 'unsafe-inline'`** — inline `style="..."` attributes remain across a number of views; extracting them is future work.
